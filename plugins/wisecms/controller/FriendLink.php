<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\model\FriendLink as FriendLinkModel;
use plugin\wisecms\service\content\FriendLinkService;
use plugin\wisecms\service\site\SiteService;

/**
 * 后台友情链接管理（B-1）
 *
 * 继承 plugin\BaseController，自动获得 RBAC 权限校验与 CSRF 校验（框架统一提供）。
 * 站点归属不由前端传入：P2 起跟随后台站点选择器（{@see SiteService::resolveAdminSiteId()}）。
 */
class FriendLink extends BaseController
{
    use PageFallbackTrait;

    protected FriendLinkService $service;

    protected function initPlugin(): void
    {
        $this->service = new FriendLinkService();
    }

    /**
     * 后台当前站点（P2：跟随后台站点选择器）
     *
     * 原实现一期把站点写死为 1（`DEFAULT_SITE_ID` 常量），多站点下友链会全部落到站点 1。
     */
    protected function siteId(): int
    {
        return (new SiteService())->resolveAdminSiteId();
    }

    /**
     * 列表
     */
    public function index()
    {
        $params = [
            'site_id'    => $this->siteId(),
            'keyword'    => $this->request->param('keyword', ''),
            'status'     => $this->request->param('status', ''),
            'group_name' => $this->request->param('group_name', ''),
            'page'       => (int) $this->request->param('page', 1),
            'limit'      => (int) $this->request->param('limit', 20),
        ];

        $list = $this->service->getList($params);

        if ($this->request->isAjax()) {
            return $this->success('', $list);
        }

        $this->assign('list', $list);
        $this->assign('params', $params);
        $this->assign('groups', $this->service->groups($this->siteId()));

        return $this->fetch('admin/friend_link/index');
    }

    /**
     * 新增（GET 表单 / POST 保存）
     */
    public function create()
    {
        if ($this->request->isPost()) {
            $name = trim((string) $this->request->post('name', ''));
            if ($name === '') {
                return $this->error('链接名称不能为空');
            }

            try {
                $data = $this->request->post();
                // P2：站点归属由服务端注入（覆盖表单可能伪造的 site_id）
                $data['site_id'] = $this->siteId();
                $link = $this->service->create($data);

                return $this->success('友情链接创建成功', $link);
            } catch (\Exception $e) {
                cms_log('error', '[wisecms] 友情链接创建失败: ' . $e->getMessage(), ['name' => $name]);
                return $this->error($e->getMessage());
            }
        }

        $this->assign('link', []);
        $this->assign('groups', $this->service->groups($this->siteId()));

        return $this->fetch('admin/friend_link/form');
    }

    /**
     * 编辑（GET 表单 / POST 保存）
     */
    public function edit()
    {
        $id = (int) $this->request->param('id', 0);
        $link = FriendLinkModel::find($id);
        if (!$link) {
            return $this->pageFallback('友情链接不存在', plugin_url('wisecms/friendLink/index'));
        }

        if ($this->request->isPost()) {
            $name = trim((string) $this->request->post('name', ''));
            if ($name === '') {
                return $this->error('链接名称不能为空');
            }

            try {
                $link = $this->service->update($id, $this->request->post());

                return $this->success('友情链接更新成功', $link);
            } catch (\Exception $e) {
                cms_log('error', '[wisecms] 友情链接更新失败: ' . $e->getMessage(), ['id' => $id]);
                return $this->error($e->getMessage());
            }
        }

        $row = $link->toArray();
        // 时间窗口列已统一为 DATETIME（NULL = 不限），展示格式与表单输入 `Y-m-d H:i` 对齐
        $row['start_time_text'] = empty($row['start_time']) ? '' : date('Y-m-d H:i', cms_timestamp($row['start_time']));
        $row['end_time_text']   = empty($row['end_time']) ? '' : date('Y-m-d H:i', cms_timestamp($row['end_time']));

        $this->assign('link', $row);
        $this->assign('groups', $this->service->groups($this->siteId()));

        return $this->fetch('admin/friend_link/form');
    }

    /**
     * 删除
     */
    public function delete()
    {
        $id = (int) $this->request->post('id', 0);
        try {
            $this->service->delete($id);
            return $this->success('友情链接删除成功');
        } catch (\Exception $e) {
            cms_log('error', '[wisecms] 友情链接删除失败: ' . $e->getMessage(), ['id' => $id]);
            return $this->error($e->getMessage());
        }
    }

    /**
     * 批量删除
     */
    public function batchDelete()
    {
        $ids = (array) $this->request->post('ids', []);
        try {
            $count = $this->service->batchDelete($ids);
            return $this->success("已删除 {$count} 条友情链接");
        } catch (\Exception $e) {
            cms_log('error', '[wisecms] 友情链接批量删除失败: ' . $e->getMessage());
            return $this->error($e->getMessage());
        }
    }

    /**
     * 切换上下架
     */
    public function toggleStatus()
    {
        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 1);

        try {
            $this->service->toggleStatus($id, $status);
            return $this->success('状态更新成功');
        } catch (\Exception $e) {
            cms_log('error', '[wisecms] 友情链接状态更新失败: ' . $e->getMessage(), ['id' => $id]);
            return $this->error($e->getMessage());
        }
    }

    /**
     * 批量排序
     */
    public function sort()
    {
        $orders = $this->request->post('orders', []);
        if (is_string($orders)) {
            $decoded = json_decode($orders, true);
            $orders  = is_array($decoded) ? $decoded : [];
        }

        try {
            $this->service->sort((array) $orders);
            return $this->success('排序已更新');
        } catch (\Exception $e) {
            cms_log('error', '[wisecms] 友情链接排序失败: ' . $e->getMessage());
            return $this->error($e->getMessage());
        }
    }
}
