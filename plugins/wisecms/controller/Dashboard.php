<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\model\Comment;
use plugin\wisecms\model\Content;
use plugin\wisecms\service\content\CommentService;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\integration\AnalyticsService;
use plugin\wisecms\service\site\SiteService;

/**
 * 数据看板（UIUX-P0-44 / P2-01）
 *
 * P2-01 增强三件事：
 *   1. **趋势区间可切换**（7 / 30 / 90 天）—— 非 AJAX 首屏渲染 + AJAX 局部切换（不刷新整页）；
 *   2. **待办清单** —— 只列**有真实筛选入口**的项，每项都能点进对应列表（不做死待办）；
 *   3. **卡片下钻** —— 四张统计卡都是链接，直达带筛选/排序的内容列表。
 *
 * ⚠️ 站点口径：所有数字都跟随后台「当前站点」（`SiteService::resolveAdminSiteId()`）；
 *    看板页因此**必须挂站点条**，切站后由 `dashboard.js` 局部重载（P1-14 无刷新约定）。
 *
 * ⚠️ AJAX 契约保持向后兼容：仍返回 `stats / topContents / trend / site_id`，
 *    新增 `days / todos`；`trend` 结构不变（`[{date, count}, ...]`）。
 */
class Dashboard extends BaseController
{
    /**
     * 趋势可选区间（白名单收敛）
     *
     * 前端按钮与后端校验共用本常量；非白名单值一律回落到首项，避免 `?days=100000` 拉长查询。
     */
    const TREND_DAYS = [7, 30, 90];

    /**
     * 数据看板
     */
    public function index()
    {
        $analytics = new AnalyticsService();

        // P2：看板统计跟随后台「当前站点」（原实现无参调用 → 默认站点 1，
        // 多站点下切站后看板数字不变、且与内容列表口径不一致）
        $siteId = (new SiteService())->resolveAdminSiteId();

        $days        = $this->normalizeDays($this->request->param('days', self::TREND_DAYS[0]));
        $stats       = $analytics->getContentStats($siteId);
        $topContents = $analytics->getTopContents(10, $siteId);
        $trend       = $analytics->getTrendData($days, $siteId);
        $todos       = $this->todos($siteId);

        if ($this->request->isAjax()) {
            // trend 回原始数组（首屏由模板写进 data 属性，此处交给前端自行处理）
            return $this->success('', [
                'stats'       => $stats,
                'topContents' => $topContents,
                'trend'       => $trend,
                'days'        => $days,
                'todos'       => $todos,
                'site_id'     => $siteId,
            ]);
        }

        $this->assign('cards', $this->cards($stats));
        $this->assign('todos', $todos);
        $this->assign('totalTodos', array_sum(array_column($todos, 'count')));
        $this->assign('trendDays', self::TREND_DAYS);
        $this->assign('days', $days);
        // 趋势 / 热门内容都经 data 属性下发（页内零内联脚本），由 js/dashboard.js 渲染
        $this->assign('trendJson', json_encode($trend, JSON_UNESCAPED_UNICODE));
        $this->assign('topJson', json_encode($this->slimTop($topContents), JSON_UNESCAPED_UNICODE));

        return $this->fetch('admin/dashboard/index');
    }

    /**
     * 统计卡片（P2-01：每张卡都带下钻链接，直达带筛选/排序的列表）
     *
     * 下钻参数全部对应**后端真实支持**的筛选键
     * （`Content::index` 收 `status` / `order`，白名单在 `ContentService::getList()`）：
     *   · 已发布 → `status=2`
     *   · 草稿   → `status=0`
     *   · 总浏览量 → `order=view_count desc`（按浏览量倒序）
     *
     * @param array $stats `AnalyticsService::getContentStats()`
     * @return array<int, array{key:string,label:string,value:int,url:string,cls:string,icon:string,title:string}>
     */
    protected function cards(array $stats): array
    {
        return [
            [
                'key'   => 'total',
                'label' => '内容总数',
                'value' => (int) ($stats['total'] ?? 0),
                'url'   => (string) plugin_url('wisecms/content/index'),
                'cls'   => 'cms-kpi cms-kpi-primary',
                'icon'  => 'bi bi-collection',
                'title' => '查看全部内容',
            ],
            [
                'key'   => 'published',
                'label' => '已发布',
                'value' => (int) ($stats['published'] ?? 0),
                'url'   => (string) plugin_url('wisecms/content/index', ['status' => ContentService::STATUS_PUBLISHED]),
                'cls'   => 'cms-kpi cms-kpi-success',
                'icon'  => 'bi bi-send-check',
                'title' => '只看已发布内容',
            ],
            [
                'key'   => 'draft',
                'label' => '草稿',
                'value' => (int) ($stats['draft'] ?? 0),
                'url'   => (string) plugin_url('wisecms/content/index', ['status' => ContentService::STATUS_DRAFT]),
                'cls'   => 'cms-kpi cms-kpi-secondary',
                'icon'  => 'bi bi-pencil',
                'title' => '只看草稿',
            ],
            [
                'key'   => 'views',
                'label' => '总浏览量',
                'value' => (int) ($stats['totalViews'] ?? 0),
                'url'   => (string) plugin_url('wisecms/content/index', ['order' => 'view_count desc']),
                'cls'   => 'cms-kpi cms-kpi-info',
                'icon'  => 'bi bi-eye',
                'title' => '按浏览量倒序查看内容',
            ],
        ];
    }

    /**
     * 待办清单（P2-01）
     *
     * 只列**有真实筛选入口**的项（内容列表支持 `status`、评论列表支持 `status`、
     * 回收站有独立页），每项都带可点击跳转 —— 不做「有数字却点不进去」的死待办。
     *
     * @return array<int, array{key:string,label:string,count:int,url:string,icon:string}>
     */
    protected function todos(int $siteId): array
    {
        // 待审核内容（先审后发时运营的主战场）
        $pendingContent = (int) Content::where('site_id', $siteId)
            ->where('status', ContentService::STATUS_PENDING)
            ->whereNull('delete_time')
            ->count();
        // 草稿
        $draftContent = (int) Content::where('site_id', $siteId)
            ->where('status', ContentService::STATUS_DRAFT)
            ->whereNull('delete_time')
            ->count();
        // 待审核评论（`comment_check` 开启时才会有）
        $pendingComment = (int) Comment::where('site_id', $siteId)
            ->where('status', CommentService::STATUS_PENDING)
            ->count();
        // 回收站内容
        $recycleContent = (int) Content::onlyTrashed()->where('site_id', $siteId)->count();

        return [
            [
                'key'   => 'content_pending',
                'label' => '待审核内容',
                'count' => $pendingContent,
                'url'   => (string) plugin_url('wisecms/content/index', ['status' => ContentService::STATUS_PENDING]),
                'icon'  => 'bi bi-hourglass-split',
            ],
            [
                'key'   => 'comment_pending',
                'label' => '待审核评论',
                'count' => $pendingComment,
                'url'   => (string) plugin_url('wisecms/comment/index', ['status' => CommentService::STATUS_PENDING]),
                'icon'  => 'bi bi-chat-dots',
            ],
            [
                'key'   => 'content_draft',
                'label' => '草稿内容',
                'count' => $draftContent,
                'url'   => (string) plugin_url('wisecms/content/index', ['status' => ContentService::STATUS_DRAFT]),
                'icon'  => 'bi bi-pencil-square',
            ],
            [
                'key'   => 'content_recycle',
                'label' => '回收站内容',
                'count' => $recycleContent,
                'url'   => (string) plugin_url('wisecms/recycle/index'),
                'icon'  => 'bi bi-trash3',
            ],
        ];
    }

    /**
     * 趋势区间收敛：非白名单值回落首项（既有 GET 参数防注入/防慢查询）
     */
    protected function normalizeDays($days): int
    {
        $days = (int) $days;

        return in_array($days, self::TREND_DAYS, true) ? $days : self::TREND_DAYS[0];
    }

    /**
     * 精简热门内容（只保留列表要展示的 4 列）
     *
     * ⚠️ `AnalyticsService::getTopContents()` 返回**整行**（含 `content` 长文本字段），
     *    直接写进 `data` 属性会让页面体积暴涨（10 篇正文可能上百 KB）。
     *
     * @param array $rows
     * @return array<int, array{id:int,title:string,view_count:int,publish_time:string,status:int}>
     */
    protected function slimTop(array $rows): array
    {
        $slim = [];
        foreach ($rows as $row) {
            $slim[] = [
                'id'           => (int) ($row['id'] ?? 0),
                'title'        => (string) ($row['title'] ?? ''),
                'view_count'   => (int) ($row['view_count'] ?? 0),
                'publish_time' => (string) ($row['publish_time'] ?? ''),
                'status'       => (int) ($row['status'] ?? 0),
            ];
        }

        return $slim;
    }
}
