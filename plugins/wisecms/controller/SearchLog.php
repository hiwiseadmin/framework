<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\service\content\SearchService;
use plugin\wisecms\service\site\SiteService;

/**
 * 搜索日志 / 热搜词（UIUX-P2-04）
 *
 * 补齐「能力→入口」缺口：`wisecms_search_log` 由前台搜索持续写入
 * （`SearchService::search()` → `logSearch()`），`getHotKeywords()` 也已被前台搜索页
 * 与 `api/Search::hot` 消费 —— 但**后台此前没有任何查看入口**，运营看不到用户在搜什么。
 *
 *   searchLog/index → 页面（服务端渲染首屏热搜词）+ AJAX 日志列表
 *   searchLog/hot   → 热搜词（站点切换时局部刷新用，避免整页 reload，见 P1-14）
 *
 * ⚠️ 三处已核实的事实：
 *   1. **只读页**：`wisecms_search_log` 只有 `site_id/keyword/result_count/ip/create_time`，
 *      **没有置顶/排序/状态列** → `P2-04` 原文的「手动置顶热搜词」**后端不支持**，
 *      按该任务 DoD「若后端无接口则只读」处理。要支持置顶需动**建表迁移**并出存量升级命令
 *      （本插件不允许 Phinx 增量迁移，见任务清单 §1.1㉜）。
 *   2. **按站点隔离**：`site_id` 由 `logSearch()` 写入（调用方未传时回落当前前台站点）；
 *      库存量数据可用 `wisecms:comment:site` 同款思路回填 —— 但本页只读，故按现状过滤，
 *      历史无归属行为不会出现在任何站点（诚实呈现，不猜测归属）。
 *   3. **热词排序是一次 COUNT 聚合**（`getHotKeywords`），走 `(site_id, keyword)` 索引。
 */
class SearchLog extends BaseController
{
    /**
     * 后台当前站点（P2 站点选择器：会话上下文）
     *
     * ⚠️ `siteId()` **不是** `BaseController` 的方法 —— 各控制器各自定义
     * （`Content`/`Seo`/`Category`… 均内置一份）。漏定义会直接
     * `Call to undefined method ...::siteId()` → 500（实测踩过）。
     */
    protected function siteId(): int
    {
        return (new SiteService())->resolveAdminSiteId();
    }

    /** 页面 + AJAX 日志列表（标准 paginate 契约，与其它列表页一致） */
    public function index()
    {
        $service = new SearchService();
        $siteId  = $this->siteId();

        if ($this->request->isAjax()) {
            return $this->success('', $service->getLogPage([
                'page'    => $this->request->param('page', 1),
                'limit'   => $this->request->param('limit', 15),
                'site_id' => $siteId,
                'keyword' => $this->request->param('keyword', ''),
            ]));
        }

        // 热词交给前端渲染（容器 + data 属性下发）：
        // 只保留**一套**渲染实现，站点切换后的局部重渲染与首屏天然一致，不会两边漂移
        $this->assign(
            'hotKeywordsJson',
            json_encode($this->rankHot($service->getHotKeywords(20, $siteId)), JSON_UNESCAPED_UNICODE)
        );

        return $this->fetch('admin/searchLog/index');
    }

    /**
     * 热搜词（当前站点）
     *
     * 单独一个动作是为了**站点切换无刷新**（P1-14）：整页 reload 会丢掉表格状态，
     * 而已渲染的热搜词卡片又必须跟着站点变。
     */
    public function hot()
    {
        $limit = min(50, max(1, (int) $this->request->param('limit', 20)));

        return $this->success('', [
            'hot' => $this->rankHot((new SearchService())->getHotKeywords($limit, $this->siteId())),
        ]);
    }

    /**
     * 给热词补上名次（服务端算，避免模板里写 `{$i+1}` 这类算术，
     * 也保证首屏与站点切换后的 JS 重渲染名次口径一致）
     *
     * @param array $hot `[{keyword, count}, ...]`（已按次数倒序）
     * @return array 追加 `rank` 键
     */
    protected function rankHot(array $hot): array
    {
        $ranked = [];
        foreach (array_values($hot) as $index => $item) {
            $item['rank'] = $index + 1;
            $ranked[] = $item;
        }

        return $ranked;
    }
}
