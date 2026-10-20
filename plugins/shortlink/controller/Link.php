<?php
namespace plugin\shortlink\controller;

use plugin\shortlink\service\ShortLinkService;
use plugin\shortlink\model\Group;
use plugin\shortlink\model\Link as LinkModel;
use plugin\shortlink\validate\Link as LinkValidate;

class Link extends Base
{
    public function index()
    {
        $groups = Group::where('status', Group::STATUS_ACTIVE)->select();
        return $this->fetch('link/list', ['groups' => $groups]);
    }

    public function getList()
    {
        $params = $this->buildQueryParams();
        $query = LinkModel::search($params['query'] ?? []);
        $list = $query->paginate(['list_rows' => $params['pageSize'] ?? 20, 'page' => $params['pageNumber'] ?? 1]);
        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['short_url'] = $item->short_url;
            $rows[] = $row;
        }
        return $this->success('OK', ['total' => $list->total(), 'rows' => $rows]);
    }

    public function form()
    {
        $id = $this->request->param('id/d', 0);
        $link = null;
        if ($id > 0) {
            $link = LinkModel::find($id);
        }
        $groups = Group::where('status', Group::STATUS_ACTIVE)->select();
        return $this->fetch('link/form', ['link' => $link ?? $id, 'groups' => $groups]);
    }

    public function save()
    {
        $data = $this->request->post();
        $validate = new LinkValidate();
        if (!$validate->scene('save')->check($data)) {
            return $this->error($validate->getError());
        }
        $id = $data['id'] ?? 0;
        $adminId = $this->getAdminId();
        $service = new ShortLinkService();
        try {
            if ($id > 0) {
                $link = $service->update($id, $data);
            } else {
                $link = $service->create($data, $adminId);
            }
            return $this->success('保存成功', ['id' => $link->id]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function delete()
    {
        $ids = $this->request->post('ids', '');
        if (empty($ids)) {
            return $this->error('请选择要删除的链接');
        }
        $ids = is_array($ids) ? $ids : explode(',', $ids);
        $adminId = $this->getAdminId();
        // 权限校验：非超管时检查是否所有链接都属于当前管理员
        if (!$this->isSuperAdmin()) {
            $count = LinkModel::whereIn('id', $ids)
                ->where('admin_id', '<>', $adminId)
                ->count();
            if ($count > 0) {
                return $this->error('无权删除他人的短链接');
            }
        }
        (new ShortLinkService())->delete($ids);
        return $this->success('删除成功');
    }

    public function toggle()
    {
        $id = $this->request->post('id/d', 0);
        $status = $this->request->post('status/d', 1);
        if ($id <= 0) return $this->error('ID 不能为空');
        try {
            $link = LinkModel::find($id);
            if (!$link) return $this->error('短链接不存在');
            if (!$this->canManage((int) $link->admin_id)) {
                return $this->error('无权操作该短链接');
            }
            $link = (new ShortLinkService())->setStatus($id, $status);
            return $this->success('操作成功', ['id' => $link->id, 'status' => $link->status]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getGroups()
    {
        $groups = Group::where('status', Group::STATUS_ACTIVE)->field('id, name, ident')->select();
        return $this->success('ok', $groups);
    }

    public function batchCreate()
    {
        $urls = $this->request->post('urls/a', []);
        $options = $this->request->post('options/a', []);
        $adminId = $this->getAdminId();
        if (empty($urls)) return $this->error('请提供链接列表');
        try {
            $links = (new ShortLinkService())->batchCreate($urls, $options, $adminId);
            $result = array_map(function ($link) {
                return ['id' => $link->id, 'code' => $link->code, 'short_url' => $link->short_url];
            }, $links);
            return $this->success('批量创建成功', $result);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
