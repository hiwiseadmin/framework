<?php
namespace plugin\wisecms\service\content;

use plugin\wisecms\model\Content;
use plugin\wisecms\model\TagRelation;
use plugin\wisecms\service\site\CacheService;
use plugin\wisecms\service\site\SiteService;
use think\facade\Db;

/**
 * 相关文章 / 热门 / 编辑推荐服务（A-2）
 *
 * 设计要点（详见 docs/wisecms-recommend-design.md）：
 *   · 零迁移：只用现有表与索引；候选集一次 SQL 取回（LIMIT scanLimit），排序在 PHP 完成；
 *   · 可测性：score() / rank() / normalizeLimit() / *Key() 为纯函数，可脱离框架与数据库测试；
 *   · 单次查询：一次 SQL 取候选集，副表字段与 URL 走 OutputService 批量处理（无 N+1）；
 *   · 可降级：无标签 → 同栏目热门；缓存驱动异常 → 直接查库（推荐位不应因缓存故障 500）；
 *   · 站点隔离：所有查询硬编码 site_id + status=2 + delete_time IS NULL，不接受外部传入。
 */
class RecommendService
{
    /** 打分权重（详见设计 §3.1） */
    const W_TAG_HIT  = 10.0;   // 共同标签数（主信号）
    const W_SAME_CAT = 3.0;    // 同栏目
    const W_RECENCY  = 2.0;    // 时间衰减上限
    const W_VIEW     = 0.5;    // 热度（弱信号）

    /** 时间衰减尺度（天）：score += W_RECENCY * exp(-days / DECAY_DAYS) */
    const DECAY_DAYS = 30.0;

    /** 展示条数上限（normalizeLimit 默认 max） */
    const MAX_LIMIT = 20;

    /** 候选集 / 缓存条数硬上限 */
    const MAX_SCAN = 100;

    /**
     * 内容主表候选字段（列表展示所需最小集）
     *
     * 时间列为 create_time / update_time / publish_time（DATETIME）。
     */
    const LIST_FIELDS = 'c.id,c.catid,c.model_id,c.title,c.description,c.thumb,c.slug,c.alias,'
        . 'c.create_time,c.update_time,c.publish_time,c.view_count';

    protected ?CacheService $cache = null;
    protected ?OutputService $output = null;

    /**
     * @param CacheService|null  $cache  可注入（测试用 spy）
     * @param OutputService|null $output 可注入
     */
    public function __construct(?CacheService $cache = null, ?OutputService $output = null)
    {
        $this->cache  = $cache;
        $this->output = $output;
    }

    // ══════════════════════════ 对外入口 ══════════════════════════

    /**
     * 相关文章（共同标签 + 同栏目 + 时间衰减 + 热度，含缓存）
     *
     * @return array<int, array<string, mixed>> 经 OutputService 处理后的内容列表
     */
    public function related(int $contentId, int $limit = 8): array
    {
        if ($contentId <= 0) {
            return [];
        }

        $row = Content::where('id', $contentId)->find();
        if (!$row) {
            return [];
        }

        $content = $row->toArray();
        // 仅取标签 ID，避免加载整个 Tag 模型
        $content['tags'] = TagRelation::where('content_id', $contentId)->column('tag_id');

        return $this->relatedWithContext($this->buildContext($content), $limit);
    }

    /**
     * 站点热门（view_count + 可选时间窗口，含缓存）
     */
    public function hot(int $limit = 8, int $siteId = 0): array
    {
        $limit  = self::normalizeLimit($limit, self::MAX_LIMIT);
        $siteId = $siteId > 0 ? $siteId : (new SiteService())->resolveCurrentSiteId();

        try {
            $all = $this->cache()->remember(
                self::hotKey($siteId),
                fn() => $this->computeHot($siteId, $this->cacheLimit()),
                $this->ttl()
            );
        } catch (\Throwable $e) {
            // 缓存驱动异常时降级为实时查询
            $all = $this->computeHot($siteId, $this->cacheLimit());
        }

        return array_slice(is_array($all) ? $all : [], 0, $limit);
    }

    /**
     * 编辑推荐（is_top / is_recommend，含缓存）
     */
    public function picked(int $limit = 8, int $siteId = 0): array
    {
        $limit  = self::normalizeLimit($limit, self::MAX_LIMIT);
        $siteId = $siteId > 0 ? $siteId : (new SiteService())->resolveCurrentSiteId();

        try {
            $all = $this->cache()->remember(
                self::pickedKey($siteId),
                fn() => $this->computePicked($siteId, $this->cacheLimit()),
                $this->ttl()
            );
        } catch (\Throwable $e) {
            $all = $this->computePicked($siteId, $this->cacheLimit());
        }

        return array_slice(is_array($all) ? $all : [], 0, $limit);
    }

    /**
     * 详情页一次性取三块推荐，减少控制器逻辑
     *
     * 受 recommend_enable 总开关控制；相关文章的种子标签直接复用 $content['tags']，
     * 不再回查标签关联表。
     *
     * @param array $content 已含 id / catid / model_id / site_id / tags 的内容数组
     * @param array $opts    ['related'=>int, 'hot'=>int, 'picked'=>int]（缺省回退配置）
     * @return array{related: array, hot: array, picked: array}
     */
    public function forDetail(array $content, array $opts = []): array
    {
        if (!(bool) config('wisecms.recommend_enable', true)) {
            return ['related' => [], 'hot' => [], 'picked' => []];
        }

        $ctx    = $this->buildContext($content);
        $siteId = (int) $ctx['site_id'];
        if ($siteId <= 0) {
            $siteId = (new SiteService())->resolveCurrentSiteId();
        }

        return [
            'related' => (int) $ctx['content_id'] > 0
                ? $this->relatedWithContext($ctx, self::normalizeLimit($opts['related'] ?? config('wisecms.recommend_related_limit', 8)))
                : [],
            'hot'     => $this->hot(self::normalizeLimit($opts['hot'] ?? config('wisecms.recommend_hot_limit', 8)), $siteId),
            'picked'  => $this->picked(self::normalizeLimit($opts['picked'] ?? config('wisecms.recommend_picked_limit', 8)), $siteId),
        ];
    }

    // ══════════════════════════ 计算（含缓存包裹） ══════════════════════════

    /**
     * 相关文章（给定上下文，含缓存）
     *
     * @param array $ctx   buildContext() 结果
     * @param int   $limit 展示条数
     */
    protected function relatedWithContext(array $ctx, int $limit): array
    {
        $limit     = self::normalizeLimit($limit, self::MAX_LIMIT);
        $contentId = (int) ($ctx['content_id'] ?? 0);
        if ($contentId <= 0) {
            return [];
        }

        try {
            $all = $this->cache()->remember(
                self::relatedKey($contentId),
                fn() => $this->computeRelated($ctx, $this->cacheLimit()),
                $this->ttl()
            );
        } catch (\Throwable $e) {
            $all = $this->computeRelated($ctx, $this->cacheLimit());
        }

        return array_slice(is_array($all) ? $all : [], 0, $limit);
    }

    /**
     * 相关文章：候选 SQL（tag_hits）→ 降级补足 → rank() → 输出处理
     */
    protected function computeRelated(array $ctx, int $limit): array
    {
        $contentId = (int) ($ctx['content_id'] ?? 0);
        $catid     = (int) ($ctx['catid'] ?? 0);
        $modelId   = (int) ($ctx['model_id'] ?? 0);
        $siteId    = (int) ($ctx['site_id'] ?? 0);
        if ($siteId <= 0) {
            $siteId = (new SiteService())->resolveCurrentSiteId();
        }
        $tagIds = array_values(array_unique(array_filter(array_map('intval', (array) ($ctx['tag_ids'] ?? [])))));

        $scanLimit  = max(1, self::normalizeLimit($limit, self::MAX_SCAN));
        $candidates = [];

        // ① 主路径：按共同标签取候选（tag_hits 为共同标签数）
        if ($tagIds !== []) {
            $candidates = $this->queryByTags($tagIds, $contentId, $siteId, $modelId, $scanLimit);
        }

        // ② 降级/补足：无标签，或候选不足且 recommend_fill 开启 → 同栏目热门补足
        $fill = $tagIds === [] || (bool) config('wisecms.recommend_fill', false);
        if ($fill && count($candidates) < $scanLimit) {
            $exclude = [$contentId => $contentId];
            foreach ($candidates as $candidate) {
                $exclude[(int) ($candidate['id'] ?? 0)] = (int) ($candidate['id'] ?? 0);
            }
            $candidates = array_merge(
                $candidates,
                $this->queryByCategory($catid, $siteId, array_values($exclude), $scanLimit - count($candidates))
            );
        }

        $ranked = self::rank($candidates, $ctx, $scanLimit, time());

        return $this->output()->processContentListOutput($ranked);
    }

    /**
     * 站点热门（无缓存）
     */
    protected function computeHot(int $siteId, int $limit): array
    {
        $query = Db::name('wisecms_content')
            ->where('site_id', $siteId)
            ->where('status', ContentService::STATUS_PUBLISHED)
            ->whereNull('delete_time');

        $days = (int) config('wisecms.recommend_hot_days', 0);
        if ($days > 0) {
            // 热门时间窗口：create_time 为 DATETIME
            $query->where('create_time', '>=', date('Y-m-d H:i:s', time() - $days * 86400));
        }

        $rows = $query
            ->field(self::LIST_FIELDS)
            ->order('view_count', 'desc')
            ->order('create_time', 'desc')
            ->limit(max(1, $limit))
            ->select()->toArray();

        return $this->output()->processContentListOutput($rows);
    }

    /**
     * 编辑推荐（无缓存）
     */
    protected function computePicked(int $siteId, int $limit): array
    {
        $rows = Db::name('wisecms_content')
            ->where('site_id', $siteId)
            ->where('status', ContentService::STATUS_PUBLISHED)
            ->whereNull('delete_time')
            ->where(function ($query) {
                $query->where('is_top', 1)->whereOr('is_recommend', 1);
            })
            ->field(self::LIST_FIELDS)
            ->order('is_top', 'desc')
            ->order('listorder', 'desc')
            ->order('create_time', 'desc')
            ->limit(max(1, $limit))
            ->select()->toArray();

        return $this->output()->processContentListOutput($rows);
    }

    // ══════════════════════════ 候选查询 ══════════════════════════

    /**
     * 按共同标签取候选（tag_hits = 与当前内容的共同标签数）
     *
     * @param int[] $tagIds    种子标签
     * @param int   $contentId 当前内容（排除自身）
     * @param int   $siteId    站点隔离
     * @param int   $modelId   >0 时锁定同模型（跨模型字段语义不一致）
     * @param int   $limit     候选上限
     */
    protected function queryByTags(array $tagIds, int $contentId, int $siteId, int $modelId, int $limit): array
    {
        $query = Db::name('wisecms_content')->alias('c')
            ->join('wisecms_tag_relation tr', 'tr.content_id = c.id')
            ->whereIn('tr.tag_id', $tagIds)
            ->where('c.id', '<>', $contentId)
            ->where('c.status', ContentService::STATUS_PUBLISHED)
            ->whereNull('c.delete_time')
            ->where('c.site_id', $siteId)
            ->field(self::LIST_FIELDS . ', COUNT(DISTINCT tr.tag_id) AS tag_hits')
            ->group('c.id')
            ->order('tag_hits', 'desc')
            ->order('c.create_time', 'desc')
            ->limit(max(1, $limit));

        if ($modelId > 0) {
            $query->where('c.model_id', $modelId);
        }

        return $query->select()->toArray();
    }

    /**
     * 按同栏目热门取候选（降级路径，tag_hits 恒为 0）
     *
     * @param int[] $excludeIds 需排除的内容 ID（含当前内容）
     */
    protected function queryByCategory(int $catid, int $siteId, array $excludeIds, int $limit): array
    {
        if ($catid <= 0 || $limit <= 0) {
            return [];
        }

        return Db::name('wisecms_content')->alias('c')
            ->where('c.catid', $catid)
            ->whereNotIn('c.id', $excludeIds)
            ->where('c.status', ContentService::STATUS_PUBLISHED)
            ->whereNull('c.delete_time')
            ->where('c.site_id', $siteId)
            ->field(self::LIST_FIELDS . ', 0 AS tag_hits')
            ->order('c.view_count', 'desc')
            ->order('c.create_time', 'desc')
            ->limit($limit)
            ->select()->toArray();
    }

    // ══════════════════════════ 纯函数（可零依赖测试） ══════════════════════════

    /**
     * 单条候选打分（纯函数）
     *
     * score = tag_hits*10 + (同栏目?3:0) + 2*exp(-days/30) + 0.5*ln(1+view_count)
     */
    public static function score(array $candidate, array $ctx, int $now): float
    {
        $total = (float) ((int) ($candidate['tag_hits'] ?? 0)) * self::W_TAG_HIT;

        $catid = (int) ($candidate['catid'] ?? 0);
        if ($catid > 0 && $catid === (int) ($ctx['catid'] ?? 0)) {
            $total += self::W_SAME_CAT;
        }

        // 时间衰减：create_time 为 DATETIME，先归一为时间戳
        $createdAt = cms_timestamp($candidate['create_time'] ?? null);
        if ($createdAt > 0 && $now > 0) {
            $days = max(0.0, ($now - $createdAt) / 86400);
            $total += self::W_RECENCY * exp(-$days / self::DECAY_DAYS);
        } else {
            $total += self::W_RECENCY;
        }

        $total += self::W_VIEW * log(1 + max(0, (int) ($candidate['view_count'] ?? 0)));

        return $total;
    }

    /**
     * 打分排序 + 截断（纯函数）
     *
     * 按 score 降序；分数相同时 create_time 降序；排除自身并去重；截断到 limit。
     *
     * @return array<int, array<string, mixed>> 排序后的候选（不含 score）
     */
    public static function rank(array $candidates, array $ctx, int $limit, int $now): array
    {
        $contentId = (int) ($ctx['content_id'] ?? 0);
        $seen      = [];
        $scored    = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $id = (int) ($candidate['id'] ?? 0);
            if ($id <= 0 || ($contentId > 0 && $id === $contentId) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $scored[]  = ['item' => $candidate, 'score' => self::score($candidate, $ctx, $now)];
        }

        usort($scored, static function (array $a, array $b): int {
            if (abs($a['score'] - $b['score']) < 1e-9) {
                // 同分按创建时间降序
                return cms_timestamp($b['item']['create_time'] ?? null)
                    <=> cms_timestamp($a['item']['create_time'] ?? null);
            }
            return $a['score'] < $b['score'] ? 1 : -1;
        });

        $result = [];
        foreach (array_slice($scored, 0, max(0, $limit)) as $row) {
            $result[] = $row['item'];
        }

        return $result;
    }

    /**
     * 条数收敛到 1..$max（纯函数）
     *
     * @param mixed $limit
     */
    public static function normalizeLimit($limit, int $max = self::MAX_LIMIT): int
    {
        $max   = $max > 0 ? $max : self::MAX_LIMIT;
        $value = (int) $limit;

        return max(1, min($max, $value));
    }

    /**
     * 相关文章缓存键（纯函数，键中不含 limit）
     */
    public static function relatedKey(int $contentId): string
    {
        return sprintf(CacheService::KEY_RECOMMEND_RELATED, $contentId);
    }

    /**
     * 站点热门缓存键（纯函数，键中不含 limit）
     */
    public static function hotKey(int $siteId): string
    {
        return sprintf(CacheService::KEY_RECOMMEND_HOT, $siteId);
    }

    /**
     * 编辑推荐缓存键（纯函数，键中不含 limit）
     */
    public static function pickedKey(int $siteId): string
    {
        return sprintf(CacheService::KEY_RECOMMEND_PICKED, $siteId);
    }

    // ══════════════════════════ 内部工具 ══════════════════════════

    /**
     * 从内容数组构建打分上下文（tags 支持 Tag 数组或标签 ID 数组）
     *
     * @return array{content_id:int, catid:int, model_id:int, site_id:int, tag_ids:int[], create_time:int}
     */
    protected function buildContext(array $content): array
    {
        $tagIds = [];
        foreach ((array) ($content['tags'] ?? []) as $tag) {
            $id = is_array($tag) ? (int) ($tag['id'] ?? 0) : (int) $tag;
            if ($id > 0) {
                $tagIds[$id] = $id;
            }
        }

        return [
            'content_id'  => (int) ($content['id'] ?? 0),
            'catid'       => (int) ($content['catid'] ?? 0),
            'model_id'    => (int) ($content['model_id'] ?? 0),
            'site_id'     => (int) ($content['site_id'] ?? 0),
            'tag_ids'     => array_values($tagIds),
            // create_time（DATETIME）归一为时间戳，供时间衰减打分
            'create_time' => cms_timestamp($content['create_time'] ?? null),
        ];
    }

    /**
     * 缓存 / 候选集条数上限（recommend_cache_limit，收敛到 1..MAX_SCAN）
     */
    protected function cacheLimit(): int
    {
        return self::normalizeLimit((int) config('wisecms.recommend_cache_limit', 20), self::MAX_SCAN);
    }

    /**
     * 缓存 TTL（recommend_cache_ttl，<=0 回退 1800）
     */
    protected function ttl(): int
    {
        $ttl = (int) config('wisecms.recommend_cache_ttl', 1800);

        return $ttl > 0 ? $ttl : 1800;
    }

    protected function cache(): CacheService
    {
        return $this->cache ??= new CacheService();
    }

    protected function output(): OutputService
    {
        return $this->output ??= new OutputService();
    }
}
