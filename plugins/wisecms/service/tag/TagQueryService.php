<?php
declare(strict_types=1);

namespace plugin\wisecms\service\tag;

use plugin\wisecms\model\Category;
use plugin\wisecms\model\Tag;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\site\CacheService;
use plugin\wisecms\service\url\UrlService;
use think\facade\Db;

/**
 * 模板标签库取数服务（C-3）
 *
 * 设计要点（详见 docs/wisecms-template-taglib-design.md §6.2）：
 *   · 标签类只做「翻译」，SQL / 缓存 / URL 全部落在本服务，可单测、可审计注入；
 *   · 标签属性一律白名单收口：orderby / order / flag 只做枚举→列名映射，
 *     typeid / limit / ids 强制 intval 收敛，杜绝「模板作者可控的 SQL 注入」；
 *   · 查询硬编码 status=已发布 + delete_time IS NULL + site_id，草稿/待审与跨站数据不外泄；
 *   · 列表输出复用 UrlService::content()（内部有栏目路径缓存，避免 N+1），
 *     栏目名一次 whereIn 批量取回；
 *   · arclist / channel / tags 走站点级缓存，缓存驱动异常降级为实时查询；
 *   · {cms:sql} 为默认关闭的高级逃生口（§5.5 / §10.2），四道闸门后才落地；
 *   · normalizeXxx() / cacheKey() / safeSqlFragment() / buildTree() 为纯函数，可脱离框架测试。
 */
class TagQueryService
{
    /** 标签名（用于拼缓存键） */
    const TAG_ARCLIST = 'arclist';
    const TAG_CHANNEL = 'channel';
    const TAG_TAGS    = 'tags';

    /** arclist：limit 上限兜底（配置缺失时使用） / 默认值兜底 */
    const MAX_LIMIT     = 50;
    const DEFAULT_LIMIT = 10;

    /** channel：返回的栏目条数上限 / 默认（0 = 不限） */
    const CHANNEL_MAX_LIMIT     = 200;
    const CHANNEL_DEFAULT_LIMIT = 0;

    /** tags：标签条数上限 / 默认 */
    const TAGS_MAX_LIMIT     = 100;
    const TAGS_DEFAULT_LIMIT = 20;

    /** tags orderby 白名单（count = 本站引用数，由 ContentService::recountTags 维护） */
    const TAGS_ORDERBY_MAP = ['count' => 'count', 'id' => 'id', 'name' => 'name'];

    /** 栏目查询字段（含 site_id / alias，供 UrlService::category 与面包屑复用） */
    const CATEGORY_FIELDS = 'id,site_id,parent_id,type,name,alias,model_id,list_rows,sort';

    /** sql：结果条数硬上限 */
    const SQL_MAX_LIMIT = 100;

    /**
     * sql：表名白名单（**显式枚举**，P0-3 加固）
     *
     * 此前用 `^wisecms_[a-z0-9_]+$` 放行全部 cms 自有表，等于把 `wisecms_webhook`
     * （含 secret）、`wisecms_site`、`wisecms_search_log`（含 IP/关键词）等
     * 非前台表也暴露给模板作者，可整表读取敏感列。
     * 现收敛为「前台内容表」显式枚举：未知表名一律拒绝。
     */
    const SQL_TABLE_ALLOWLIST = [
        'wisecms_content',
        'wisecms_category',
        'wisecms_tag',
        'wisecms_tag_relation',
        'wisecms_page',
        'wisecms_friend_link',
    ];

    /**
     * sql：危险关键词黑名单（\b 边界，避免误伤 update_time / delete_time 等列名）
     *
     * P0-3 加固：必须同时拦截 `select` / `from` —— `where` 子句的字符集白名单允许
     * `( ) = ' " < >`，若放行子查询即可构造布尔盲注读取任意表，例如：
     *   where="1=1 and (select substr(secret,1,1) from wisecms_webhook limit 1)='a'"
     * 同时拦截跨库/元数据访问（information_schema / performance_schema / mysql / sys）
     * 与 `join`，彻底关闭 `{cms:sql}` 的注入面。
     */
    const SQL_DENY_PATTERN = '/\b(union|select|from|join|insert|update|delete|drop|alter|create|truncate|replace|grant|revoke|execute|exec|call|sleep|benchmark|load_file|outfile|dumpfile|into|procedure|handler|information_schema|performance_schema|mysql|sys)\b/i';

    /** 缓存 TTL 兜底（秒） */
    const DEFAULT_TTL = 900;

    /** 参数指纹长度 */
    const FINGERPRINT_LEN = 12;

    /** 默认排序列 / 方向 */
    const DEFAULT_ORDERBY = 'id';
    const DEFAULT_ORDER   = 'desc';

    /** orderby 白名单：模板值 → 真实列名（绝不拼接原值） */
    const ORDERBY_MAP = [
        'id'           => 'id',
        'create_time'  => 'create_time',
        'update_time'  => 'update_time',
        'publish_time' => 'publish_time',
        'view_count'   => 'view_count',
        'listorder'    => 'listorder',
    ];

    /** order 白名单 */
    const ORDER_MAP = ['asc' => 'asc', 'desc' => 'desc'];

    /** flag 白名单：top=置顶、recommend=推荐、hot=按热度（view_count desc） */
    const FLAG_MAP = ['top' => 'top', 'recommend' => 'recommend', 'hot' => 'hot'];

    /**
     * 列表标签查询字段（列表展示所需最小集）
     *
     * 时间列为 `create_time` / `update_time` / `publish_time`（DATETIME）；
     * 模板输出请用 `{:substr($item.create_time, 0, 10)}`（`|date` 滤镜要求 int 时间戳，不适用于 DATETIME）。
     */
    const LIST_FIELDS = 'id,catid,model_id,title,description,thumb,slug,alias,'
        . 'create_time,update_time,publish_time,view_count,is_top,is_recommend';

    /** keyword 最大长度（防慢查询） */
    const KEYWORD_MAX_LEN = 100;

    protected ?CacheService $cache = null;

    /**
     * @param CacheService|null $cache 可注入（测试用 spy）
     */
    public function __construct(?CacheService $cache = null)
    {
        $this->cache = $cache;
    }

    // ══════════════════════════ 对外入口 ══════════════════════════

    /**
     * 内容列表（{cms:arclist}）
     *
     * @param array $attrs  模板属性（已由编译期固定为字符串/受信变量）
     * @param int   $siteId 站点 ID（由 TagRuntime 解析，不接受模板传入）
     * @return array<int, array<string, mixed>> 补齐 url / category_name 的内容列表
     */
    public function arclist(array $attrs, int $siteId): array
    {
        $normalized = self::normalizeArclist($attrs);
        if ($siteId <= 0) {
            return [];
        }

        try {
            return $this->cache()->remember(
                self::cacheKey(self::TAG_ARCLIST, $normalized, $siteId),
                fn() => $this->queryArclist($normalized, $siteId),
                $this->resolveTtl($attrs)
            );
        } catch (\Throwable $e) {
            // 缓存驱动异常时降级为实时查询：标签不应因缓存故障拖垮整页
            return $this->queryArclist($normalized, $siteId);
        }
    }

    /**
     * 栏目列表（{cms:channel}）
     *
     * 返回指定父级（parentid，0=顶级）下的栏目，含 children 子树，并补齐 url。
     *
     * @return array<int, array<string, mixed>>
     */
    public function channel(array $attrs, int $siteId): array
    {
        $normalized = self::normalizeChannel($attrs);
        if ($siteId <= 0) {
            return [];
        }

        try {
            return $this->cache()->remember(
                self::cacheKey(self::TAG_CHANNEL, $normalized, $siteId),
                fn() => $this->queryChannel($normalized, $siteId),
                $this->resolveTtl($attrs)
            );
        } catch (\Throwable $e) {
            return $this->queryChannel($normalized, $siteId);
        }
    }

    /**
     * 标签云（{cms:tags}）
     *
     * @return array<int, array<string, mixed>> 补齐 url 的标签列表
     */
    public function tags(array $attrs, int $siteId): array
    {
        $normalized = self::normalizeTags($attrs);
        if ($siteId <= 0) {
            return [];
        }

        try {
            return $this->cache()->remember(
                self::cacheKey(self::TAG_TAGS, $normalized, $siteId),
                fn() => $this->queryTags($normalized, $siteId),
                $this->resolveTtl($attrs)
            );
        } catch (\Throwable $e) {
            return $this->queryTags($normalized, $siteId);
        }
    }

    /**
     * 受控直查（{cms:sql}，默认禁用）
     *
     * 五道闸门（设计 §5.5 / §10.2 + P0-3 加固）：
     *   ① 配置开关在 TagRuntime 处拦截（本方法只负责查询侧）；
     *   ② table 必须命中 {@see SQL_TABLE_ALLOWLIST} 显式枚举（不再放行任意 wisecms_* 表）；
     *   ③ field / where / order 片段过「危险关键词 + 字符集」双校验，任一非法即放弃；
     *      关键词黑名单已包含 select / from / join / information_schema 等，子查询不可用；
     *   ④ limit 收敛到 1..SQL_MAX_LIMIT。
     *
     * 刻意不做站点隔离与缓存：这是给高级用户的自定义查询口子，结果语义由模板作者自负。
     *
     * @return array<int, array<string, mixed>>
     */
    public function sql(array $attrs): array
    {
        $table = strtolower(trim((string) ($attrs['table'] ?? '')));
        if (!in_array($table, self::SQL_TABLE_ALLOWLIST, true)) {
            return [];
        }

        $field = self::safeSqlFragment((string) ($attrs['field'] ?? '*'), true);
        if ($field === null) {
            return [];
        }
        if ($field === '') {
            $field = '*';
        }

        $where = self::safeSqlFragment((string) ($attrs['where'] ?? ''), false);
        if ($where === null) {
            return [];
        }

        $order = self::safeSqlFragment((string) ($attrs['order'] ?? ''), false);
        if ($order === null) {
            return [];
        }

        $limit = (int) ($attrs['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::SQL_MAX_LIMIT, $limit));

        try {
            $query = Db::name($table)->field($field);
            if ($where !== '') {
                $query->whereRaw($where);
            }
            if ($order !== '') {
                $query->orderRaw($order);
            }

            return $query->limit($limit)->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ══════════════════════════ 纯函数（可零依赖测试） ══════════════════════════

    /**
     * arclist 属性白名单 + 默认值 + 类型收敛（纯函数）
     *
     * 返回仅含「查询语义」的固定键序数组，供查询与缓存指纹共用；
     * id（循环变量名）、cache（TTL）等非查询属性不参与，避免同数据多缓存。
     *
     * @param array $attrs 原始模板属性
     * @return array{typeid:int[],sub:bool,model:int,limit:int,orderby:string,order:string,flag:string,keyword:string,day:int,ids:int[],exclude:int[]}
     */
    public static function normalizeArclist(array $attrs): array
    {
        $maxLimit = (int) config('wisecms.tag_arclist_limit_max', self::MAX_LIMIT);
        if ($maxLimit <= 0) {
            $maxLimit = self::MAX_LIMIT;
        }

        $defaultLimit = (int) config('wisecms.tag_default_limit', self::DEFAULT_LIMIT);
        if ($defaultLimit <= 0) {
            $defaultLimit = self::DEFAULT_LIMIT;
        }

        // limit：优先 limit，其次兼容 DedeCMS 的 row 写法
        $rawLimit = $attrs['limit'] ?? $attrs['row'] ?? null;
        $limit    = ($rawLimit === null || $rawLimit === '') ? $defaultLimit : (int) $rawLimit;
        $limit    = max(1, min($maxLimit, $limit));

        // orderby / order / flag：枚举→白名单，非法值一律回落默认，绝不进入 SQL
        $orderby = self::pick($attrs['orderby'] ?? '', self::ORDERBY_MAP, self::DEFAULT_ORDERBY);
        $order   = self::pick($attrs['order'] ?? '', self::ORDER_MAP, self::DEFAULT_ORDER);
        $flag    = self::pick($attrs['flag'] ?? '', self::FLAG_MAP, '');

        $keyword = trim((string) ($attrs['keyword'] ?? ''));
        if ($keyword !== '' && mb_strlen($keyword) > self::KEYWORD_MAX_LEN) {
            $keyword = mb_substr($keyword, 0, self::KEYWORD_MAX_LEN);
        }

        $day = (int) ($attrs['day'] ?? 0);

        return [
            'typeid'  => self::intList($attrs['typeid'] ?? $attrs['catid'] ?? ''),
            'sub'     => self::toBool($attrs['sub'] ?? false),
            'model'   => max(0, (int) ($attrs['model'] ?? 0)),
            'limit'   => $limit,
            'orderby' => $orderby,
            'order'   => $order,
            'flag'    => $flag,
            'keyword' => $keyword,
            'day'     => $day > 0 ? $day : 0,
            'ids'     => self::intList($attrs['ids'] ?? ''),
            'exclude' => self::intList($attrs['exclude'] ?? ''),
        ];
    }

    /**
     * channel 属性白名单 + 默认值 + 类型收敛（纯函数）
     *
     * parentid / typeid 二选一（typeid 为 DedeCMS 习惯写法）；limit=0 表示不限。
     *
     * @param array $attrs 原始模板属性
     * @return array{parentid:int,limit:int,showhidden:bool}
     */
    public static function normalizeChannel(array $attrs): array
    {
        $rawLimit = $attrs['limit'] ?? null;
        $limit    = ($rawLimit === null || $rawLimit === '')
            ? self::CHANNEL_DEFAULT_LIMIT
            : max(0, min(self::CHANNEL_MAX_LIMIT, (int) $rawLimit));

        return [
            'parentid'   => max(0, (int) ($attrs['parentid'] ?? $attrs['typeid'] ?? 0)),
            'limit'      => $limit,
            'showhidden' => self::toBool($attrs['showhidden'] ?? false),
        ];
    }

    /**
     * tags 属性白名单 + 默认值 + 类型收敛（纯函数）
     *
     * @param array $attrs 原始模板属性
     * @return array{limit:int,orderby:string,order:string}
     */
    public static function normalizeTags(array $attrs): array
    {
        $rawLimit = $attrs['limit'] ?? null;
        $limit    = ($rawLimit === null || $rawLimit === '')
            ? self::TAGS_DEFAULT_LIMIT
            : max(1, min(self::TAGS_MAX_LIMIT, (int) $rawLimit));

        return [
            'limit'   => $limit,
            'orderby' => self::pick($attrs['orderby'] ?? '', self::TAGS_ORDERBY_MAP, 'count'),
            'order'   => self::pick($attrs['order'] ?? '', self::ORDER_MAP, self::DEFAULT_ORDER),
        ];
    }

    /**
     * SQL 片段校验（纯函数）
     *
     * 命中危险关键词或非法字符集时返回 null（调用方据此放弃整个标签）；
     * 空串返回 ''（表示该子句省略）。
     *
     * @param bool $allowStar field 子句允许 `*`
     */
    public static function safeSqlFragment(string $fragment, bool $allowStar = false): ?string
    {
        $fragment = trim($fragment);
        if ($fragment === '') {
            return '';
        }

        // ① 语句分隔符 / 注释符一律拒绝
        if (preg_match('/(;|--|\/\*|\*\/|#)/', $fragment) === 1) {
            return null;
        }

        // ② 危险关键词黑名单
        if (preg_match(self::SQL_DENY_PATTERN, $fragment) === 1) {
            return null;
        }

        // ③ 字符集白名单
        $pattern = $allowStar
            ? '/^[A-Za-z0-9_,.*\s`]+$/'
            : '/^[A-Za-z0-9_,.=`\'"()<>!+\-*\/%|\s]+$/';

        if (preg_match($pattern, $fragment) !== 1) {
            return null;
        }

        return $fragment;
    }

    /**
     * 标签缓存键（纯函数）
     *
     * 参数指纹 = md5(固定键序序列化) 前 12 位；属性键序不同但内容相同 → 同键。
     *
     * @param string $tag     标签名（arclist / channel / tags / links）
     * @param array  $attrs   归一化属性（或原始属性，内部会再排序）
     * @param int    $siteId  站点 ID
     */
    public static function cacheKey(string $tag, array $attrs, int $siteId): string
    {
        $fingerprint = substr(
            md5((string) json_encode(self::ksortDeep($attrs), JSON_UNESCAPED_UNICODE)),
            0,
            self::FINGERPRINT_LEN
        );

        return sprintf(self::keyPattern($tag), $siteId, $fingerprint);
    }

    /**
     * 标签名 → 缓存键模板
     */
    public static function keyPattern(string $tag): string
    {
        return match ($tag) {
            'arclist' => CacheService::KEY_TAG_ARCLIST,
            'channel' => CacheService::KEY_TAG_CHANNEL,
            'tags'    => CacheService::KEY_TAG_TAGS,
            'links'   => CacheService::KEY_TAG_LINKS,
            default   => 'wisecms:taglib:' . preg_replace('/[^a-z0-9_]/i', '', $tag) . ':%d:%s',
        };
    }

    // ══════════════════════════ 查询 ══════════════════════════

    /**
     * 内容列表实际查询（无缓存）
     *
     * 硬编码 status=2 + delete_time IS NULL + site_id，不接受模板传入 status/site_id。
     */
    protected function queryArclist(array $normalized, int $siteId): array
    {
        $query = Db::name('wisecms_content')
            ->where('site_id', $siteId)
            ->where('status', ContentService::STATUS_PUBLISHED)
            ->whereNull('delete_time');

        // 栏目：sub=1 时展开子栏目（默认仅本栏目，避免误伤）
        if ($normalized['typeid'] !== []) {
            $catids = $normalized['sub']
                ? $this->expandCategoryIds($normalized['typeid'], $siteId)
                : $normalized['typeid'];
            $query->whereIn('catid', $catids);
        }

        if ($normalized['model'] > 0) {
            $query->where('model_id', $normalized['model']);
        }

        if ($normalized['keyword'] !== '') {
            $query->where('title', 'like', '%' . addcslashes($normalized['keyword'], '%_\\') . '%');
        }

        if ($normalized['day'] > 0) {
            // create_time 为 DATETIME，时间窗口按 datetime 比较
            $query->where('create_time', '>=', date('Y-m-d H:i:s', time() - $normalized['day'] * 86400));
        }

        if ($normalized['ids'] !== []) {
            $query->whereIn('id', $normalized['ids']);
        }

        if ($normalized['exclude'] !== []) {
            $query->whereNotIn('id', $normalized['exclude']);
        }

        if ($normalized['flag'] === 'top') {
            $query->where('is_top', 1);
        } elseif ($normalized['flag'] === 'recommend') {
            $query->where('is_recommend', 1);
        }

        [$orderby, $order] = self::resolveOrder($normalized);

        $rows = $query
            ->field(self::LIST_FIELDS)
            ->order($orderby, $order)
            ->limit($normalized['limit'])
            ->select()->toArray();

        return $this->attachMeta($rows);
    }

    /**
     * 排序收敛：flag=hot 强制按热度；其余用白名单列 + 方向
     *
     * @return array{0:string,1:string}
     */
    protected static function resolveOrder(array $normalized): array
    {
        if ($normalized['flag'] === 'hot') {
            return ['view_count', 'desc'];
        }

        return [$normalized['orderby'], $normalized['order']];
    }

    /**
     * 栏目树实际查询（无缓存）：一次查询 + 内存建树
     *
     * showhidden=0（默认）仅取启用栏目；children 命名与 view/index/layout.html 的
     * `{$cat.children}` 保持一致（注意 Category::getTree() 产出的是 `childlist`）。
     */
    protected function queryChannel(array $normalized, int $siteId): array
    {
        try {
            $query = Category::where('site_id', $siteId)
                ->field(self::CATEGORY_FIELDS)
                ->order('sort', 'asc')
                ->order('id', 'asc');

            if (!$normalized['showhidden']) {
                $query->where('status', 1);
            }

            $rows = $query->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $tree = self::buildTree($rows, $normalized['parentid']);
        if ($normalized['limit'] > 0) {
            $tree = array_slice($tree, 0, $normalized['limit']);
        }

        return $this->attachCategoryUrls($tree);
    }

    /**
     * 标签云实际查询（无缓存）：默认按引用数降序
     *
     * P2 标签词表按站点隔离：必须限定 `site_id`，否则 `{cms:tags}` 会把**他站的标签名**
     * 输出到本站页面（标签名本身即业务信息，属跨站信息泄露）。
     *
     * @param int $siteId 当前站点
     */
    protected function queryTags(array $normalized, int $siteId): array
    {
        try {
            $rows = Tag::where('site_id', $siteId)
                ->order($normalized['orderby'], $normalized['order'])
                ->limit($normalized['limit'])
                ->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $urls = new UrlService();
        foreach ($rows as &$row) {
            $row['url'] = $urls->tag($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * 扁平栏目行 → 树（纯函数，沿用查询返回的顺序，不重排）
     *
     * @param array $rows     含 id / parent_id 的栏目行
     * @param int   $parentId 根父级（0=顶级）
     */
    protected static function buildTree(array $rows, int $parentId): array
    {
        $children = [];
        foreach ($rows as $row) {
            if (!isset($row['id'], $row['parent_id'])) {
                continue;
            }
            $children[(int) $row['parent_id']][] = $row;
        }

        return self::buildBranch($children, $parentId);
    }

    /**
     * 递归构建子树（纯函数）
     */
    protected static function buildBranch(array $children, int $parentId): array
    {
        $branch = [];
        foreach ($children[$parentId] ?? [] as $row) {
            $row['children'] = self::buildBranch($children, (int) $row['id']);
            $branch[]        = $row;
        }

        return $branch;
    }

    /**
     * 递归补栏目 url（复用同一 UrlService 实例，保留其栏目路径缓存）
     */
    protected function attachCategoryUrls(array $nodes, ?UrlService $urls = null): array
    {
        $urls ??= new UrlService();

        foreach ($nodes as &$node) {
            $node['url'] = $urls->category($node);
            if (!empty($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->attachCategoryUrls($node['children'], $urls);
            }
        }
        unset($node);

        return $nodes;
    }

    /**
     * 批量补 url / category_name（无 N+1）
     */
    protected function attachMeta(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $urls   = new UrlService();
        $catids = [];
        foreach ($rows as $row) {
            $catid = (int) ($row['catid'] ?? 0);
            if ($catid > 0) {
                $catids[$catid] = $catid;
            }
        }

        $names = [];
        if ($catids !== []) {
            try {
                $names = Category::whereIn('id', array_values($catids))->column('name', 'id');
            } catch (\Throwable $e) {
                $names = [];
            }
        }

        foreach ($rows as &$row) {
            $row['url']           = $urls->content($row);
            $row['category_name'] = (string) ($names[(int) ($row['catid'] ?? 0)] ?? '');
        }
        unset($row);

        return $rows;
    }

    /**
     * 栏目 ID 展开（含自身与全部子孙栏目，BFS，一次查询）
     *
     * @param int[] $rootIds
     * @return int[]
     */
    protected function expandCategoryIds(array $rootIds, int $siteId): array
    {
        if ($rootIds === []) {
            return [];
        }

        try {
            $rows = Category::where('site_id', $siteId)
                ->where('status', 1)
                ->field('id,parent_id')
                ->select()->toArray();
        } catch (\Throwable $e) {
            return $rootIds;
        }

        $children = [];
        foreach ($rows as $row) {
            $children[(int) $row['parent_id']][] = (int) $row['id'];
        }

        $result = [];
        $queue  = array_values($rootIds);
        while ($queue !== []) {
            $id = (int) array_shift($queue);
            if (isset($result[$id])) {
                continue;
            }
            $result[$id] = $id;
            foreach ($children[$id] ?? [] as $child) {
                if (!isset($result[$child])) {
                    $queue[] = $child;
                }
            }
        }

        $result = array_values($result);
        sort($result);

        return $result;
    }

    // ══════════════════════════ 内部工具 ══════════════════════════

    /**
     * 白名单取值：命中则返回映射值，否则返回默认
     */
    protected static function pick($value, array $map, string $default): string
    {
        $key = strtolower(trim((string) $value));

        return $map[$key] ?? $default;
    }

    /**
     * 逗号串 / 数组 → 去重升序正整数列表（丢弃非法项）
     *
     * @param mixed $value
     * @return int[]
     */
    protected static function intList($value): array
    {
        $parts = is_array($value) ? $value : explode(',', (string) $value);
        $out   = [];
        foreach ($parts as $part) {
            $id = (int) trim((string) $part);
            if ($id > 0) {
                $out[$id] = $id;
            }
        }

        $out = array_values($out);
        sort($out);

        return $out;
    }

    /**
     * 宽松布尔解析（1/true/yes/on）
     */
    protected static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * 递归按键名排序，保证指纹稳定
     */
    protected static function ksortDeep(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::ksortDeep($value);
            }
        }

        return $data;
    }

    /**
     * TTL：属性 cache 优先，否则配置 tag_cache_ttl
     */
    protected function resolveTtl(array $attrs): int
    {
        $ttl = (int) ($attrs['cache'] ?? 0);
        if ($ttl <= 0) {
            $ttl = (int) config('wisecms.tag_cache_ttl', self::DEFAULT_TTL);
        }

        return $ttl > 0 ? $ttl : self::DEFAULT_TTL;
    }

    protected function cache(): CacheService
    {
        return $this->cache ??= new CacheService();
    }
}
