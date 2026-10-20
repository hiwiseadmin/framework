<?php
namespace plugin\wisecms\service\content;
use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\service\site\CacheService;

use plugin\wisecms\model\Tag;
use plugin\wisecms\model\TagRelation;
use plugin\wisecms\model\UrlAlias;
use plugin\wisecms\service\url\UrlService;
use think\facade\Db;

class TagService
{
    /**
     * 获取标签列表（后台，**当前站点**的词表）
     *
     * @param array{site_id?:int, keyword?:string, page?:int, limit?:int} $params
     */
    public function getList(array $params = []): array
    {
        // P2 标签站点隔离：词表按站点独立，后台列表只看本站标签
        $query = Tag::where('site_id', max(1, (int) ($params['site_id'] ?? 1)))->order('count', 'desc');
        if (!empty($params['keyword'])) {
            // P1-B6：转义 LIKE 通配符（非注入，但 `%`/`_` 会退化为全表扫描）
            $keyword = addcslashes((string) $params['keyword'], '%_\\');
            $query->where('name', 'like', "%{$keyword}%");
        }
        // P1-B6：分页参数收敛，避免 limit 无上限造成的慢查询/资源耗尽
        $page  = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        return $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
    }

    /**
     * 站点标签云（唯一入口：前台标签页 / `{cms:tags}` / API，P1-D 起、P2 收敛）
     *
     * P2 标签词表按站点隔离后，`wisecms_tag` 自带 `site_id` 与**本站口径**的 `count`，
     * 因此不再需要「关联表 + 内容表」三表 JOIN + GROUP BY 统计（原实现代价高且与
     * `tag.count` 口径并存）：
     *   · 排序 / 计数直接用 `count`（由 `ContentService::recountTags` 按站点维护）；
     *   · 仍输出 `site_count` 键，兼容既有 API 消费方（值等于 `count`）。
     *
     * 缓存键含 site/limit/page（`KEY_TAG_CLOUD`），失效统一走
     * `CacheService::TAG_CLOUD_PATTERN` 前缀。
     *
     * @param int $siteId 当前站点
     * @param int $limit  条数（1..200）
     * @param int $page   页码
     * @return array<int, array<string, mixed>> 每项含 id / name / alias / count / site_count
     */
    public function siteCloud(int $siteId, int $limit = 50, int $page = 1): array
    {
        $limit = min(200, max(1, $limit));
        $page  = max(1, $page);

        return (new CacheService())->remember(
            sprintf(CacheService::KEY_TAG_CLOUD, $siteId, $limit, $page),
            static function () use ($siteId, $limit, $page) {
                $rows = Tag::where('site_id', $siteId)
                    ->order('count', 'desc')
                    ->order('id', 'asc')
                    ->page($page, $limit)
                    ->select()
                    ->toArray();

                return array_map(static function (array $row): array {
                    $row['site_count'] = (int) ($row['count'] ?? 0);

                    return $row;
                }, $rows);
            },
            1800
        );
    }

    /**
     * 站点维度：某标签下的已发布内容（P1-D）
     *
     * 刻意用 `Db::name()` 原生查询而非模型：`Content` 带 SoftDelete，其 `__TABLE__`
     * 会展开为真实表名，与 `alias('c')`（MySQL 要求按别名引用）同用时会产生非法列名，
     * 故这里用原生查询 + 显式 `status` / `delete_time` 条件（与 RecommendService 一致）。
     *
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function siteContentList(int $tagId, int $siteId, int $page = 1, int $limit = 15): array
    {
        $limit = min(100, max(1, $limit));
        $page  = max(1, $page);

        $query = Db::name('wisecms_content')->alias('c')
            ->join('wisecms_tag_relation tr', 'tr.content_id = c.id')
            ->where('tr.tag_id', $tagId)
            ->where('c.site_id', $siteId)
            ->where('c.status', ContentService::STATUS_PUBLISHED)
            ->whereNull('c.delete_time');

        $total = (int) (clone $query)->count();
        $list  = $query->field('c.*')
            ->order('c.create_time', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 创建标签（后台，**站点内**唯一）
     *
     * @param string $name   标签名
     * @param int    $siteId 所属站点
     */
    public function create(string $name, int $siteId): Tag
    {
        $name = trim($name);
        if ($name === '') {
            throw new WisecmsException('标签名不能为空', ErrorCode::VALIDATE_FAILED);
        }
        if (Tag::where('site_id', $siteId)->where('name', $name)->find()) {
            throw new WisecmsException('标签已存在', ErrorCode::DUPLICATE);
        }

        $tag = $this->createUnique($name, $siteId);
        self::ensureAlias($tag);
        (new CacheService())->onTagChanged($siteId);

        (new UrlService())->syncTag($tag);

        return $tag;
    }

    /**
     * 站点内「按名称查找或创建」标签（**内容存标签 / 跨站复制 的唯一入口**）
     *
     * P2 收敛：原 `ContentService::saveTags` 内联了一份 find-or-create，与 `create()` 重复且
     * 能力更弱（没有撞键重试、没有别名回填、没有 URL 别名同步），两处实现容易漂移；现统一走本方法。
     *
     * @param int    $siteId 目标站点（标签归属 = 内容所属站点）
     * @param string $name   标签名
     * @throws WisecmsException 标签名为空
     */
    public function findOrCreate(int $siteId, string $name): Tag
    {
        $name = trim($name);
        if ($name === '') {
            throw new WisecmsException('标签名不能为空', ErrorCode::VALIDATE_FAILED);
        }

        $tag = Tag::where('site_id', $siteId)->where('name', $name)->find();
        if ($tag) {
            return $tag;
        }

        $tag = $this->createUnique($name, $siteId);
        self::ensureAlias($tag);
        (new CacheService())->onTagChanged($siteId);

        (new UrlService())->syncTag($tag);

        return $tag;
    }

    /**
     * 写入标签并处理唯一键竞态（P1-C / P2 站点内唯一）
     *
     * 唯一键为 `(site_id, name)` 与 `(site_id, alias)`，「先查后写」在并发下存在窗口：
     *   · `name` 撞键 → 语义等同「本站标签已存在」，转成业务异常（而不是 500）；
     *   · `alias` 撞键（不同标签名 slug 化后同名）→ 重新生成 -N 后缀后重试。
     *
     * @param int $attempt 重试次数（alias 冲突时递增）
     */
    protected function createUnique(string $name, int $siteId, int $attempt = 0): Tag
    {
        try {
            return Tag::create([
                'site_id' => $siteId,
                'name'    => $name,
                'alias'   => self::makeAlias($name, $siteId),
                // create_time / update_time 由模型 autoWriteTimestamp 维护
            ]);
        } catch (\Throwable $e) {
            if (!cms_is_duplicate_key($e)) {
                throw $e;
            }
            // 并发同名标签：按「已存在」语义返回业务错误
            if (Tag::where('site_id', $siteId)->where('name', $name)->find()) {
                throw new WisecmsException('标签已存在', ErrorCode::DUPLICATE);
            }
            if ($attempt >= 3) {
                throw $e;
            }

            return $this->createUnique($name, $siteId, $attempt + 1);
        }
    }

    /**
     * 生成标签 URL 别名（slug 化，供伪静态 /tag/{alias}/ 使用）
     *
     * 规则（详见 docs/wisecms-recommend-design.md §5.4）：
     *   1. 小写 + 非 [a-z0-9] 转为 '-' + 去首尾 '-'；
     *   2. 纯中文/符号 slug 化后为空时返回 ''，由 ensureAlias() 在拿到主键后回填 tag-{id}；
     *   3. **站点内**与既有别名冲突时追加 -2 / -3 …（P2：别名唯一性收敛到站点，
     *      否则 A 站用过的 slug 会让 B 站被迫退让为 `-2`）。
     *
     * @param string $name      标签名
     * @param int    $siteId    所属站点
     * @param int    $excludeId 冲突检测时排除的标签 ID（更新自身时传入）
     */
    public static function makeAlias(string $name, int $siteId, int $excludeId = 0): string
    {
        $base = self::slugify($name);
        if ($base === '') {
            return '';
        }

        $alias  = $base;
        $suffix = 1;
        while (true) {
            $query = Tag::where('site_id', $siteId)->where('alias', $alias);
            if ($excludeId > 0) {
                $query->where('id', '<>', $excludeId);
            }
            if (!$query->find()) {
                return $alias;
            }
            $alias = $base . '-' . (++$suffix);
        }
    }

    /**
     * slug 化：小写 + 非 [a-z0-9] 转 '-' + 去首尾 '-'
     */
    public static function slugify(string $name): string
    {
        $slug = mb_strtolower(trim($name), 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * 中文/符号名 slug 化结果为空时，用主键回填 tag-{id}（保证伪静态 token 非空）
     *
     * P2：占用检测限定在标签所属站点内（别名唯一性已收敛到站点）。
     */
    public static function ensureAlias(Tag $tag): Tag
    {
        if ((string) $tag->alias !== '') {
            return $tag;
        }

        $siteId = (int) $tag->site_id;

        // tag-{id} 理论唯一，但历史数据可能已占用该别名，冲突时派生 -N 后缀（P1-C）
        $alias = 'tag-' . (int) $tag->id;
        for (
            $n = 1;
            Tag::where('site_id', $siteId)->where('alias', $alias)->where('id', '<>', (int) $tag->id)->find();
            $n++
        ) {
            $alias = 'tag-' . (int) $tag->id . '-' . $n;
        }

        $tag->save(['alias' => $alias]);

        return $tag;
    }

    /**
     * 更新标签（站点内唯一）
     *
     * 改名/改别名时必须先在**所属站点内**校验唯一性，否则会撞 `(site_id, name)` /
     * `(site_id, alias)` 唯一键 → 底层 1062 直接 500。
     */
    public function update(int $id, array $data): Tag
    {
        $tag = Tag::find($id);
        if (!$tag) throw new WisecmsException('标签不存在', ErrorCode::NOT_FOUND);

        $siteId = (int) $tag->site_id;

        // 站点归属不随编辑变更（标签不能被迁到别的站点 —— 迁移后其关联内容仍在原站）
        unset($data['site_id']);

        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : null;
        if ($name === '') {
            throw new WisecmsException('标签名不能为空', ErrorCode::VALIDATE_FAILED);
        }
        if ($name !== null && $name !== (string) $tag->name
            && Tag::where('site_id', $siteId)->where('name', $name)->where('id', '<>', $id)->find()) {
            throw new WisecmsException('标签已存在', ErrorCode::DUPLICATE);
        }

        $alias = array_key_exists('alias', $data) ? (string) $data['alias'] : null;
        if ($alias !== null && $alias !== '' && $alias !== (string) $tag->alias
            && Tag::where('site_id', $siteId)->where('alias', $alias)->where('id', '<>', $id)->find()) {
            throw new WisecmsException('别名已被占用', ErrorCode::DUPLICATE);
        }

        $tag->save($data);
        (new CacheService())->onTagChanged($siteId);

        (new UrlService())->syncTag($tag);

        return $tag;
    }

    /**
     * 删除标签（连同全站…本站范围内的关联与 URL 别名）
     */
    public function delete(int $id): bool
    {
        $tag = Tag::find($id);
        if (!$tag) throw new WisecmsException('标签不存在', ErrorCode::NOT_FOUND);

        $siteId = (int) $tag->site_id;

        // 关联表已冗余 site_id：本站标签的关联必然同站（`(site_id, tag_id)` 索引命中）
        TagRelation::where('site_id', $siteId)->where('tag_id', $id)->delete();
        $tag->delete();
        (new CacheService())->onTagChanged($siteId);

        (new UrlService())->removeAlias(UrlAlias::TYPE_TAG, $id);

        return true;
    }

    /**
     * 站点归属校验（后台越权防护）
     *
     * 越界一律按「标签不存在」抛错，不暴露「该 id 存在但属于别的站点」。
     */
    public function assertSiteOwned(int $id, int $siteId): Tag
    {
        $tag = Tag::where('id', $id)->where('site_id', $siteId)->find();
        if (!$tag) {
            throw new WisecmsException('标签不存在', ErrorCode::NOT_FOUND);
        }

        return $tag;
    }
}
