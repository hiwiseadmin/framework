<?php

declare(strict_types=1);

namespace plugin\wisedocs\service;

use plugin\wisedocs\model\Category as CategoryModel;
use plugin\wisedocs\model\Document as DocumentModel;
use plugin\wisedocs\model\Tag as TagModel;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

/**
 * 文档搜索服务（P3-2 重写 · P5 增强）
 *
 * P3 修复：`whereOrLike()`（TP 不存在）致命错误、标签改走关联表、空间过滤、搜索建议。
 * P5 增强：
 *   - 热搜统计（基于缓存驱动，不强制依赖 Redis）；
 *   - 可选 FULLTEXT 引擎（`config('wisedocs.search_engine')`），不可用时自动回退 LIKE。
 */
class SearchService
{
    /** 文档-标签关联表（Db 自动补前缀） */
    protected const PIVOT = 'wisedocs_document_tags';

    /** 热搜缓存键 */
    protected const HOT_KEY = 'wisedocs:hot_searches';

    /** 热搜缓存时长（秒） */
    protected const HOT_TTL = 604800;

    /** 热搜最多保留词条数 */
    protected const HOT_MAX = 200;

    /** FULLTEXT 可用性探测缓存键 */
    protected const FT_PROBE_KEY = 'wisedocs:fulltext_available';

    public function __construct(protected int $projectId = 0)
    {
    }

    /**
     * 搜索文档
     *
     * @return array{documents: \think\Paginator, total: int, keyword: string, suggestions: array}
     */
    public function search(string $keyword, int $categoryId = 0, int $page = 1, int $limit = 15): array
    {
        $keyword = trim($keyword);

        $query = DocumentModel::where('status', DocumentModel::STATUS_PUBLISHED);

        if ($this->projectId > 0) {
            $query->where('project_id', $this->projectId);
        }

        if ($categoryId > 0) {
            $ids = (new CategoryModel())->getChildrenIds($categoryId, true);
            $query->whereIn('category_id', $ids);
        }

        if ($keyword !== '') {
            $this->applyKeywordFilter($query, $keyword);
        }

        $paginator = $query->order('is_top DESC, sort_order DESC, id DESC')
            ->field('id,title,slug,excerpt,view_count,is_top,category_id,published_at')
            ->paginate([
                'list_rows' => $limit,
                'page'      => max(1, $page),
                'query'     => ['keyword' => $keyword, 'category_id' => $categoryId ?: ''],
            ]);

        return [
            'documents'   => $paginator,
            'total'       => $paginator->total(),
            'keyword'     => $keyword,
            'suggestions' => $this->suggestions($keyword),
        ];
    }

    /**
     * 搜索建议（标题补全）
     */
    public function suggestions(string $keyword, int $limit = 8): array
    {
        $keyword = trim($keyword);
        if (mb_strlen($keyword) < 2) {
            return [];
        }

        $query = DocumentModel::where('status', DocumentModel::STATUS_PUBLISHED)
            ->whereLike('title', "%{$keyword}%");

        if ($this->projectId > 0) {
            $query->where('project_id', $this->projectId);
        }

        return $query->limit($limit)->column('title');
    }

    // ── 热搜统计（P5-1） ─────────────────────────────────

    /**
     * 记录一次搜索词
     */
    public function recordSearch(string $keyword): void
    {
        $keyword = trim($keyword);
        if ($keyword === '' || mb_strlen($keyword) < 2) {
            return;
        }

        try {
            $map = Cache::get(self::HOT_KEY, []);
            if (!is_array($map)) {
                $map = [];
            }

            $map[$keyword] = (int) ($map[$keyword] ?? 0) + 1;

            // 控制规模：仅保留计数最高的若干词条
            if (count($map) > self::HOT_MAX) {
                arsort($map);
                $map = array_slice($map, 0, self::HOT_MAX, true);
            }

            Cache::set(self::HOT_KEY, $map, self::HOT_TTL);
        } catch (\Throwable $e) {
            // 热搜失败不影响搜索
        }
    }

    /**
     * 热门搜索词
     *
     * @return array<int, array{keyword:string, count:int}>
     */
    public function hotSearches(int $limit = 10): array
    {
        try {
            $map = Cache::get(self::HOT_KEY, []);
            if (!is_array($map) || empty($map)) {
                return [];
            }

            arsort($map);

            $result = [];
            foreach (array_slice($map, 0, $limit, true) as $keyword => $count) {
                $result[] = ['keyword' => (string) $keyword, 'count' => (int) $count];
            }

            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── 关键词过滤 ───────────────────────────────────────

    /**
     * 按引擎应用关键词过滤（FULLTEXT 不可用时回退 LIKE）
     */
    protected function applyKeywordFilter($query, string $keyword): void
    {
        $tagDocIds = $this->documentIdsByTagKeyword($keyword);
        $engine    = (string) config('wisedocs.search_engine', 'like');

        if ($engine === 'fulltext' && $this->fulltextAvailable()) {
            $query->where(function ($q) use ($keyword, $tagDocIds) {
                $q->whereRaw('MATCH(`title`, `excerpt`, `content`) AGAINST (? IN NATURAL LANGUAGE MODE)', [$keyword]);

                if (!empty($tagDocIds)) {
                    $q->whereOr(function ($sub) use ($tagDocIds) {
                        $sub->whereIn('id', $tagDocIds);
                    });
                }
            });

            return;
        }

        // 默认 LIKE
        $query->where(function ($q) use ($keyword, $tagDocIds) {
            $q->whereLike('title', "%{$keyword}%")
              ->whereLike('excerpt', "%{$keyword}%", 'OR')
              ->whereLike('content', "%{$keyword}%", 'OR');

            if (!empty($tagDocIds)) {
                $q->whereOr(function ($sub) use ($tagDocIds) {
                    $sub->whereIn('id', $tagDocIds);
                });
            }
        });
    }

    /**
     * FULLTEXT 是否可用（探测一次并缓存）
     */
    protected function fulltextAvailable(): bool
    {
        try {
            $cached = Cache::get(self::FT_PROBE_KEY);
            if ($cached !== null) {
                return (bool) $cached;
            }
        } catch (\Throwable $e) {
            // 缓存不可用时走探测
        }

        $available = false;

        try {
            $table = (string) Db::name('wisedocs_documents')->getTable();
            Db::query("SELECT `id` FROM `{$table}` WHERE MATCH(`title`, `excerpt`, `content`) AGAINST (? IN NATURAL LANGUAGE MODE) LIMIT 1", ['probe']);
            $available = true;
        } catch (\Throwable $e) {
            Log::info('[wisedocs] FULLTEXT 不可用，回退 LIKE：' . $e->getMessage());
        }

        try {
            Cache::set(self::FT_PROBE_KEY, $available ? 1 : 0, 3600);
        } catch (\Throwable $e) {
            // 忽略缓存异常
        }

        return $available;
    }

    /**
     * 命中「标签名」的文档 ID 集合
     *
     * @return int[]
     */
    protected function documentIdsByTagKeyword(string $keyword): array
    {
        try {
            $tagIds = TagModel::whereLike('name', "%{$keyword}%")->column('id');
            if (empty($tagIds)) {
                return [];
            }

            return array_map(
                'intval',
                Db::name(self::PIVOT)->whereIn('tag_id', $tagIds)->column('document_id')
            );
        } catch (\Throwable $e) {
            return [];
        }
    }
}
