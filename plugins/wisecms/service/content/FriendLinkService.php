<?php
declare(strict_types=1);

namespace plugin\wisecms\service\content;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\FriendLink;
use plugin\wisecms\service\site\CacheService;

/**
 * 友情链接服务（B-1）
 *
 * 设计要点（详见 docs/wisecms-friendlink-design.md）：
 *   · 单表 wisecms_friend_link，分组用 group_name 字符串，无独立分类表；
 *   · url / logo 是「管理员可控但极易被当成可信来源」的外链字段，
 *     写入前必须经 normalizeUrl() 白名单 scheme ∈ {http,https}，
 *     否则 javascript: / data: 会变成存储型 XSS（§8.1）；
 *   · 前台唯一入口 forFront()：status=1 + 有效期窗口 + sort desc，
 *     走 CacheService 站点级缓存，缓存驱动异常降级查库（§7）；
 *   · 所有读写强制带 site_id（多站点隔离，§8.4）；
 *   · normalizeUrl() / cacheKey() 为纯函数，可脱离框架与数据库测试（§9）。
 */
class FriendLinkService
{
    /** 允许写入的字段白名单（防批量赋值越权，§8.7） */
    const ALLOWED_FIELDS = [
        'site_id', 'group_name', 'name', 'url', 'logo', 'description',
        'is_image', 'target', 'nofollow', 'sort', 'status', 'start_time', 'end_time',
    ];

    /** 允许的打开方式（枚举收口） */
    const TARGETS = ['_blank', '_self'];

    /** URL 最大长度（与表 varchar(500) 一致） */
    const URL_MAX_LEN = 500;

    /** 缓存 TTL 兜底（秒） */
    const DEFAULT_TTL = 1800;

    /** 分组名占位（空分组参与缓存键时使用） */
    const GROUP_PLACEHOLDER = '_';

    protected ?CacheService $cache = null;

    /**
     * @param CacheService|null $cache 可注入（测试用 spy）
     */
    public function __construct(?CacheService $cache = null)
    {
        $this->cache = $cache;
    }

    // ══════════════════════════ 后台 CRUD ══════════════════════════

    /**
     * 后台列表：站点 + 关键词(name|url) + 状态 + 分组 + 分页
     *
     * @param array $params site_id/keyword/status/group_name/page/limit
     * @return array 分页数组（paginate()->toArray()）
     */
    public function getList(array $params = []): array
    {
        $query = FriendLink::where('site_id', (int) ($params['site_id'] ?? 1));

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $escaped = addcslashes($keyword, '%_\\');
            $query->where('name|url', 'like', "%{$escaped}%");
        }

        if (isset($params['status']) && $params['status'] !== '' && $params['status'] !== null) {
            $query->where('status', (int) $params['status']);
        }

        $group = trim((string) ($params['group_name'] ?? ''));
        if ($group !== '') {
            $query->where('group_name', $group);
        }

        $page  = max(1, (int) ($params['page'] ?? 1));
        // P1-19b：原实现只有 `max(1)`、**没有上限** → `limit=999999` 可把全表拉出来；
        // 与其它列表（Content / Recycle / Comment / Page）统一收敛到 100。
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        return $query->order('sort', 'desc')->order('id', 'desc')
            ->paginate(['page' => $page, 'list_rows' => $limit])
            ->toArray();
    }

    /**
     * 新增友情链接
     */
    public function create(array $data): FriendLink
    {
        $data = $this->sanitize($data);
        $data['site_id'] = (int) ($data['site_id'] ?? 1);

        // 创建期默认值（friendlink_nofollow 作为「单条可覆盖」的全局默认）
        $data['group_name'] = (string) ($data['group_name'] ?? '');
        $data['name']       = (string) ($data['name'] ?? '');
        $data['logo']       = (string) ($data['logo'] ?? '');
        $data['target']     = (string) ($data['target'] ?? '_blank');
        $data['is_image']   = (int) ($data['is_image'] ?? 0);
        $data['nofollow']   = (int) ($data['nofollow'] ?? (int) (bool) config('wisecms.friendlink_nofollow', false));
        $data['sort']       = (int) ($data['sort'] ?? 0);
        $data['status']     = (int) ($data['status'] ?? 1);
        // 时间窗口列已统一为 DATETIME（NULL = 不限），不可再强转 int
        $data['start_time'] = cms_datetime($data['start_time'] ?? null);
        $data['end_time']   = cms_datetime($data['end_time'] ?? null);

        $link = FriendLink::create($data);
        $this->flushCache($data['site_id']);

        return $link;
    }

    /**
     * 更新友情链接
     */
    public function update(int $id, array $data): FriendLink
    {
        $link = FriendLink::find($id);
        if (!$link) {
            throw new WisecmsException('友情链接不存在', ErrorCode::NOT_FOUND);
        }

        $data = $this->sanitize($data);
        unset($data['site_id']); // 站点归属不允许改动

        $link->save($data);
        $this->flushCache((int) $link->site_id);

        return $link;
    }

    /**
     * 软删除
     */
    public function delete(int $id): bool
    {
        $link = FriendLink::find($id);
        if (!$link) {
            throw new WisecmsException('友情链接不存在', ErrorCode::NOT_FOUND);
        }

        $siteId = (int) $link->site_id;
        $link->delete();
        $this->flushCache($siteId);

        return true;
    }

    /**
     * 批量软删除
     *
     * @param array $ids
     * @return int 成功删除条数
     */
    public function batchDelete(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));
        if ($ids === []) {
            return 0;
        }

        $links = FriendLink::whereIn('id', $ids)->select();
        $siteIds = [];
        $count = 0;
        foreach ($links as $link) {
            $siteIds[(int) $link->site_id] = true;
            $link->delete();
            $count++;
        }

        foreach (array_keys($siteIds) as $siteId) {
            $this->flushCache((int) $siteId);
        }

        return $count;
    }

    /**
     * 切换 / 设置上下架状态
     *
     * @param int $status 0=隐藏 1=显示
     */
    public function toggleStatus(int $id, int $status): bool
    {
        $link = FriendLink::find($id);
        if (!$link) {
            throw new WisecmsException('友情链接不存在', ErrorCode::NOT_FOUND);
        }

        $link->save(['status' => $status === 1 ? 1 : 0]);
        $this->flushCache((int) $link->site_id);

        return true;
    }

    /**
     * 批量排序
     *
     * @param array $orders [['id' => 1, 'sort' => 10], ...]
     */
    public function sort(array $orders): void
    {
        $siteIds = [];
        foreach ($orders as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id   = (int) ($row['id'] ?? 0);
            $sort = (int) ($row['sort'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $link = FriendLink::find($id);
            if (!$link) {
                continue;
            }
            $siteIds[(int) $link->site_id] = true;
            $link->save(['sort' => $sort]);
        }

        foreach (array_keys($siteIds) as $siteId) {
            $this->flushCache((int) $siteId);
        }
    }

    // ══════════════════════════ 前台 ══════════════════════════

    /**
     * 前台唯一入口：取当前站点可见友链（走缓存）
     *
     * 时间窗口用 (start_time=0 OR start_time<=now) AND (end_time=0 OR end_time>=now)，
     * 避免 BETWEEN 0 AND 0 把不限期的记录过滤掉。
     *
     * @param int    $siteId 站点 ID
     * @param string $group  分组名（空=全部）
     * @param int    $limit  条数上限（0=不限）
     * @return array<int, array<string, mixed>> 每项含 target/nofollow/is_image/rel
     */
    public function forFront(int $siteId, string $group = '', int $limit = 0): array
    {
        if ($siteId <= 0 || !(bool) config('wisecms.friendlink_enable', true)) {
            return [];
        }

        $group = trim($group);
        $limit = max(0, $limit);

        $key = self::cacheKey($siteId, $group, $limit);
        $ttl = (int) config('wisecms.friendlink_ttl', self::DEFAULT_TTL);
        if ($ttl <= 0) {
            $ttl = self::DEFAULT_TTL;
        }

        try {
            return $this->cache()->remember(
                $key,
                fn () => $this->queryFront($siteId, $group, $limit),
                $ttl
            );
        } catch (\Throwable $e) {
            // 缓存驱动异常时降级为实时查询：友链属页脚装饰，不应因缓存故障 500（§7.2）
            return $this->queryFront($siteId, $group, $limit);
        }
    }

    /**
     * 实际前台查询（无缓存）
     *
     * @return array<int, array<string, mixed>>
     */
    protected function queryFront(int $siteId, string $group, int $limit): array
    {
        // 时间窗口：NULL = 不限（时间字段统一后由 int 0 改为 NULL）
        $now = cms_now();

        $query = FriendLink::where('site_id', $siteId)
            ->where('status', 1)
            ->where(function ($q) use ($now) {
                $q->whereNull('start_time')->whereOr('start_time', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('end_time')->whereOr('end_time', '>=', $now);
            });

        if ($group !== '') {
            $query->where('group_name', $group);
        }

        $query->order('sort', 'desc')->order('id', 'desc');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return array_map([$this, 'prepareRow'], $query->select()->toArray());
    }

    /**
     * 分组列表（后台筛选下拉）
     *
     * @return array<int, string>
     */
    public function groups(int $siteId): array
    {
        try {
            $groups = FriendLink::where('site_id', $siteId)
                ->where('group_name', '<>', '')
                ->order('group_name', 'asc')
                ->column('group_name');
        } catch (\Throwable $e) {
            return [];
        }

        $groups = array_filter(array_map('strval', (array) $groups), fn ($g) => $g !== '');

        return array_values(array_unique($groups));
    }

    /**
     * 点击计数 +1（不失效列表缓存：点击数不参与列表渲染，§7.3）
     */
    public function incrementClick(int $id): void
    {
        FriendLink::where('id', $id)->inc('click_count')->update();
    }

    // ══════════════════════════ 纯函数 ══════════════════════════

    /**
     * URL 白名单归一（纯函数）
     *
     * 规则：去空格 → 协议相对地址（//host/path）补全为 https:// →
     * 校验 scheme ∈ {http,https} 且 host 非空 → 非法返回 null。
     *
     * @return string|null 合法返回归一后的 URL（截断到 500），非法返回 null
     */
    public static function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // 协议相对地址补全
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if ((string) ($parts['host'] ?? '') === '') {
            return null;
        }

        return mb_substr($url, 0, self::URL_MAX_LEN);
    }

    /**
     * 前台缓存键（纯函数）：与 CacheService::KEY_FRIEND_LINK 一致（§7.1）
     *
     * @param int    $siteId 站点 ID
     * @param string $group  分组名（空分组用 '_' 占位）
     * @param int    $limit  条数上限（0=全部）
     */
    public static function cacheKey(int $siteId, string $group, int $limit): string
    {
        $group = trim($group);

        return sprintf(
            CacheService::KEY_FRIEND_LINK,
            max(0, $siteId),
            $group === '' ? self::GROUP_PLACEHOLDER : $group,
            max(0, $limit)
        );
    }

    // ══════════════════════════ 内部工具 ══════════════════════════

    /**
     * 字段白名单收口 + URL 校验 + 类型收敛（create/update 共用，§4）
     *
     * @throws WisecmsException url 非 http/https 时
     */
    protected function sanitize(array $data): array
    {
        $data = array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));

        // url：必填且必须为 http/https
        $data['url'] = self::normalizeUrl((string) ($data['url'] ?? ''));
        if ($data['url'] === null) {
            throw new WisecmsException('链接地址必须是 http/https 协议', ErrorCode::VALIDATE_FAILED);
        }

        // logo：可空；非空但非法时清空（不因 logo 阻断整条保存）
        $rawLogo = trim((string) ($data['logo'] ?? ''));
        $data['logo'] = $rawLogo === '' ? '' : (string) (self::normalizeUrl($rawLogo) ?? '');

        // target：枚举收口
        $target = (string) ($data['target'] ?? '_blank');
        $data['target'] = in_array($target, self::TARGETS, true) ? $target : '_blank';

        // 文本长度收口
        if (array_key_exists('group_name', $data)) {
            $data['group_name'] = mb_substr(trim((string) $data['group_name']), 0, 50);
        }
        if (array_key_exists('name', $data)) {
            $data['name'] = mb_substr(trim((string) $data['name']), 0, 100);
        }
        if (array_key_exists('description', $data)) {
            $data['description'] = mb_substr(trim((string) $data['description']), 0, 255);
        }

        // 布尔型数值字段收敛
        foreach (['is_image', 'nofollow', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = ((int) $data[$field]) === 1 ? 1 : 0;
            }
        }

        // 排序值原样取整
        if (array_key_exists('sort', $data)) {
            $data['sort'] = (int) $data['sort'];
        }

        // 时间窗口字段：统一为 DATETIME 或 null（null = 不限）
        // 兼容空串 / 历史 int 时间戳 / `Y-m-d H:i` 字符串三类入参
        foreach (['start_time', 'end_time'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = cms_datetime($data[$field]);
            }
        }

        return $data;
    }

    /**
     * 输出归一：target 枚举 + rel 组装 + is_image 布尔化
     *
     * `target="_blank"` 强制补 `noopener noreferrer`，否则被链页面可通过
     * `window.opener` 反向控制本站（§8.4）。
     */
    protected function prepareRow(array $row): array
    {
        $target = (string) ($row['target'] ?? '_blank');
        $row['target'] = in_array($target, self::TARGETS, true) ? $target : '_blank';

        $rel = [];
        if (!empty($row['nofollow'])) {
            $rel[] = 'nofollow';
        }
        if ($row['target'] === '_blank') {
            $rel[] = 'noopener';
            $rel[] = 'noreferrer';
        }

        $row['rel']      = implode(' ', $rel);
        $row['is_image'] = !empty($row['is_image']);
        $row['nofollow'] = (int) ($row['nofollow'] ?? 0);

        return $row;
    }

    /**
     * 变更后失效该站点友链缓存（页脚直出 + {cms:links} 标签）
     */
    protected function flushCache(int $siteId): void
    {
        try {
            $this->cache()->onFriendLinkChanged($siteId);
        } catch (\Throwable $e) {
            // 缓存失效失败不应阻断业务写入，TTL 兜底
        }
    }

    protected function cache(): CacheService
    {
        return $this->cache ??= new CacheService();
    }
}
