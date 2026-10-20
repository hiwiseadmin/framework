<?php
namespace plugin\shortlink\controller;

use plugin\shortlink\model\Group as GroupModel;
use plugin\shortlink\validate\Group as GroupValidate;

class Group extends Base
{
    public function index()
    {
        return $this->fetch('group/list');
    }

    public function getList()
    {
        $params = $this->buildQueryParams();
        $query = GroupModel::order('sort_order asc, id desc');
        if (!empty($params['query']['keyword'])) {
            $query->where('name|ident', 'like', "%{$params['query']['keyword']}%");
        }
        $list = $query->paginate(['list_rows' => $params['pageSize'] ?? 20, 'page' => $params['pageNumber'] ?? 1]);
        return $this->success('', ['total' => $list->total(), 'rows' => $list->items()]);
    }

    public function save()
    {
        $data = $this->request->post();
        $validate = new GroupValidate();
        if (!$validate->scene('save')->check($data)) {
            return $this->error($validate->getError());
        }

        $id = $data['id'] ?? 0;
        if ($id > 0) {
            $group = GroupModel::find($id);
            if (!$group) return $this->error('分组不存在');
            if (!$this->canManage((int) $group->admin_id) && $group->admin_id != 0) {
                return $this->error('无权修改该分组');
            }
        } else {
            $group = new GroupModel();
        }
        $group->name = $data['name'];
        $group->ident = $data['ident'] ?? '';
        $group->description = $data['description'] ?? '';
        $group->sort_order = $data['sort_order'] ?? 0;
        $group->status = $data['status'] ?? GroupModel::STATUS_ACTIVE;
        $group->admin_id = $this->getAdminId();
        $group->save();
        return $this->success('保存成功');
    }

    public function delete()
    {
        $id = $this->request->post('id/d', 0);
        if ($id <= 0) return $this->error('ID 不能为空');
        $group = GroupModel::find($id);
        if (!$group) return $this->error('分组不存在');
        if (!$this->canManage((int) $group->admin_id) && $group->admin_id != 0) {
            return $this->error('无权删除该分组');
        }
        GroupModel::destroy($id);
        return $this->success('删除成功');
    }

    public function getAll()
    {
        $groups = GroupModel::where('status', GroupModel::STATUS_ACTIVE)->field('id, name, ident')->select();
        return $this->success('', $groups);
    }
}
