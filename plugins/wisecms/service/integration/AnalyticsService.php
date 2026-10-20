<?php
namespace plugin\wisecms\service\integration;

use plugin\wisecms\model\Content;
use plugin\wisecms\service\site\CacheService;
use think\facade\Cache;

/**
 * 数据分析
 */
class AnalyticsService
{
    public function recordPageView(int $contentId): void
    {
        Content::where('id', $contentId)->inc('view_count')->update();

        // 按「日期+小时」聚合计数。
        // 原实现以 uniqid() 作为缓存键，条目只增不减且无法聚合：
        // 既导致缓存无限膨胀，又拿不到任何可用统计。
        $bucketKey = sprintf(CacheService::KEY_ANALYTICS_PV, $contentId, date('YmdH'));
        $count     = (int) Cache::get($bucketKey, 0);
        Cache::set($bucketKey, $count + 1, 86400 * 8);
    }

    /**
     * 内容概览
     *
     * @param int $siteId 站点 ID；**0 = 不限定站点**（P2：默认值由 1 改为 0 ——
     *                    原默认值硬编码站点 1，多站点下会把看板数字算到错误站点上）
     */
    public function getContentStats(int $siteId = 0): array
    {
        // 每次调用生成一个全新查询（避免条件在多条统计之间串用）
        $scoped = fn() => $siteId > 0
            ? Content::where('site_id', $siteId)->whereNull('delete_time')
            : Content::whereNull('delete_time');

        $total      = $scoped()->count();
        $published  = $scoped()->where('status', 2)->count();
        $draft      = $scoped()->where('status', 0)->count();
        $totalViews = (int) ($scoped()->sum('view_count') ?? 0);
        $avgSeo     = (float) ($scoped()->avg('seo_score') ?? 0);

        return compact('total', 'published', 'draft', 'totalViews', 'avgSeo');
    }

    /**
     * 浏览量 TOP 内容
     *
     * @param int $siteId 站点 ID；0 = 不限定站点
     */
    public function getTopContents(int $limit = 10, int $siteId = 0): array
    {
        $query = Content::where('status', 2)->whereNull('delete_time');
        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        return $query->order('view_count', 'desc')->limit($limit)->select()->toArray();
    }

    /**
     * 发布趋势（近 N 天，按天补零）
     *
     * ⚠️ 原实现是 for 循环里**每天一条 COUNT**：7 天 7 条 SQL，P2-01 新增 30/90 档后
     *    会直接放大到 30/90 条 SQL。现改为**一次 GROUP BY**（`DATE(create_time)`），
     *    SQL 条数恒定；缺日补 0 的动作仍在 PHP 侧完成，返回结构不变。
     *
     * @param int $days   天数（收敛到 1..365）
     * @param int $siteId 站点 ID；0 = 不限定站点
     */
    public function getTrendData(int $days = 7, int $siteId = 0): array
    {
        $days = max(1, min(365, $days));
        $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days')) . ' 00:00:00';

        // ⚠️ 必须排除软删（回收站）内容：同一个看板上 `getContentStats()` 与
        //    `getTopContents()` 都带 `whereNull('delete_time')`，本方法原缺此条件 →
        //    趋势会把已进回收站的内容也算作「当天发布」，与卡片数字对不上（P2-01 实测）。
        $query = Content::where('status', 2)
            ->whereNull('delete_time')
            ->where('create_time', '>=', $start);
        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        // `DATE(create_time)` 是原生表达式：必须走 `fieldRaw`，用 `field()` 会被当列名加反引号；
        // `GROUP BY` 用**字段别名** `d`（MySQL 允许），避免 group() 对表达式做引号处理。
        $counts = [];
        foreach ($query->fieldRaw('DATE(create_time) AS d, COUNT(*) AS c')->group('d')->select()->toArray() as $row) {
            $counts[(string) $row['d']] = (int) $row['c'];
        }

        $data = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date   = date('Y-m-d', strtotime("-{$i} days"));
            $data[] = ['date' => $date, 'count' => $counts[$date] ?? 0];
        }

        return $data;
    }
}
