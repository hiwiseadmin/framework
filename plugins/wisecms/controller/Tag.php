<?php
namespace plugin\wisecms\controller;

use plugin\wisecms\exception\WisecmsException;
use plugin\BaseController;
use plugin\wisecms\service\content\TagService;
use plugin\wisecms\service\site\SiteService;
/**
 * 后台标签管理（P2：标签词表按站点隔离）
 *
 * 列表只显示本站标签；创建落在本站；编辑/删除先校验归属（越界按「标签不存在」拒绝）。
 */
class Tag extends BaseController
{
    protected TagService $tagService;

    protected function initPlugin(): void
    {
        $this->tagService = new TagService();
    }

    /**
     * 后台当前站点（会话上下文，见 SiteService::resolveAdminSiteId）
     */
    protected function siteId(): int
    {
        return (new SiteService())->resolveAdminSiteId();
    }

    public function index()
    {
        $params = [
            'site_id' => $this->siteId(),
            'keyword' => $this->request->param('keyword', ''),
            'page'    => (int)$this->request->param('page', 1),
            'limit'   => (int)$this->request->param('limit', 20),
        ];

        $list = $this->tagService->getList($params);

        if ($this->request->isAjax()) {
            return $this->success('', $list);
        }

        $this->assign('list', $list);
        return $this->fetch('admin/tag/index');
    }

    public function create()
    {
        $name = $this->request->post('name', '');
        if (empty($name)) {
            return $this->error('标签名不能为空');
        }
        try {
            // 标签归属由服务端注入（站点内唯一）
            $tag = $this->tagService->create($name, $this->siteId());
            return $this->success('标签创建成功', $tag);
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }

    public function edit()
    {
        $id = (int) $this->request->post('id');
        $data = [
            'name' => $this->request->post('name'),
            'seo_title' => $this->request->post('seo_title', ''),
            'seo_keywords' => $this->request->post('seo_keywords', ''),
            'seo_description' => $this->request->post('seo_description', ''),
        ];
        try {
            // 站点归属校验：只能编辑本站标签
            $this->tagService->assertSiteOwned($id, $this->siteId());
            $tag = $this->tagService->update($id, $data);
            return $this->success('标签更新成功', $tag);
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }

    public function delete()
    {
        $id = (int) $this->request->post('id');
        try {
            // 站点归属校验：只能删除本站标签
            $this->tagService->assertSiteOwned($id, $this->siteId());
            $this->tagService->delete($id);
            return $this->success('标签删除成功');
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }

    /**
     * 批量删除标签（UIUX-P1-19，站点过滤 + 单条容错）
     */
    public function batchDelete()
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids/a', [])))));
        if (empty($ids)) {
            return $this->error('请选择要删除的标签');
        }

        // 站点过滤：只删本站标签（越界静默剔除）
        $owned = \plugin\wisecms\model\Tag::whereIn('id', $ids)->where('site_id', $this->siteId())->column('id');
        if (empty($owned)) {
            return $this->error('所选标签均不属于当前站点');
        }

        $failed = 0;
        foreach ($owned as $id) {
            try {
                $this->tagService->delete((int) $id);
            } catch (\Throwable $e) {
                $failed++;
                cms_log('error', '标签批量删除失败: ' . $e->getMessage(), ['tag_id' => $id]);
            }
        }

        $ok = count($owned) - $failed;
        if ($failed > 0) {
            return $this->error("已删除 {$ok} 个标签，{$failed} 个失败（详情见系统日志）");
        }
        return $this->success("已删除 {$ok} 个标签");
    }
}
