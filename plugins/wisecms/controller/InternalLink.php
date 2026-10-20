<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\model\InternalLink as InternalLinkModel;
use plugin\wisecms\service\seo\InternalLinkService;
use plugin\wisecms\service\site\SiteService;

/**
 * 内链管理（UIUX-P1-21）
 *
 * InternalLinkService 的列表 / 新增 / 删除 / 重建。内链规则有 `site_id`，
 * 按「后台当前站点」隔离（与内容/栏目等其他页一致）。
 *
 *   internalLink/index   → 列表（标准 paginate 契约）
 *   internalLink/create  → 新增（POST keyword/target_url/target_title）
 *   internalLink/delete  → 删除（POST id）
 *   internalLink/rebuild → 从内容库重建（POST，L3 确认 + loading，同步遍历）
 */
class InternalLink extends BaseController
{
    protected InternalLinkService $service;

    protected function initPlugin(): void
    {
        $this->service = new InternalLinkService();
    }

    /** 后台当前站点（会话上下文，与 Content::siteId 同源） */
    protected function siteId(): int
    {
        return (new SiteService())->resolveAdminSiteId();
    }

    public function index()
    {
        $list = InternalLinkModel::where('site_id', $this->siteId())
            ->order('id', 'desc')
            ->paginate(20);
        $arr  = $list->toArray();

        if ($this->request->isAjax()) {
            return $this->success('', $arr);
        }

        $this->assign('list', $arr);
        return $this->fetch('admin/internalLink/index');
    }

    public function create()
    {
        if (!$this->request->isPost()) {
            return $this->error('仅支持 POST');
        }

        $keyword     = trim((string) $this->request->post('keyword', ''));
        $targetUrl   = trim((string) $this->request->post('target_url', ''));
        $targetTitle = trim((string) $this->request->post('target_title', ''));

        if ($keyword === '' || $targetUrl === '') {
            return $this->error('关键词和链接地址不能为空');
        }

        $this->service->create($keyword, $targetUrl, $targetTitle, $this->siteId());
        return $this->success('内链规则已添加');
    }

    public function delete()
    {
        $this->service->delete((int) $this->request->post('id'));
        return $this->success('已删除');
    }

    /** 从内容库重建：遍历已发布内容的 keywords，去重后写入内链规则 */
    public function rebuild()
    {
        try {
            $count = $this->service->rebuildFromContent($this->siteId());
            return $this->success("重建完成，新增 {$count} 条内链规则", ['count' => $count]);
        } catch (\Exception $e) {
            cms_log('error', '内链重建失败: ' . $e->getMessage());
            return $this->error('内链重建失败，请查看系统日志');
        }
    }
}
