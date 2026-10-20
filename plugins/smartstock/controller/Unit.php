<?php
/**
 * 计量单位控制器
 *
 * 提供计量单位的 CRUD 功能。
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\controller;


use wise\support\context\ContextualLogger;
use plugin\smartstock\model\Unit as UnitModel;
class Unit extends Base
{
    /**
     * 计量单位列表页
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * Bootstrap Table 分页数据
     */
    public function getList()
    {
        $params   = $this->buildQueryParams();
        $page     = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 10);

        $query = (new UnitModel())->search($params);

        $list = $query->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['status_text'] = $item->status_text;
            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    /**
     * 新增/编辑计量单位
     */
    public function save()
    {
        $data = $this->request->post();
        $id   = $data['id'] ?? 0;

        // 基本验证
        if (empty($data['name'])) {
            return $this->error('单位名称不能为空');
        }

        try {
            // 仅允许白名单字段
            $allowFields = ['name', 'status', 'sort_order'];
            $saveData = array_intersect_key($data, array_flip($allowFields));

            if ($id > 0) {
                $unit = UnitModel::find($id);
                if (!$unit) {
                    return $this->error('单位不存在');
                }
                $unit->save($saveData);
            } else {
                $unit = new UnitModel();
                $unit->save($saveData);
            }

            return $this->success('保存成功', ['id' => $unit->id]);
        } catch (\Exception $e) {
            ContextualLogger::error('Unit save error: ' . $e->getMessage());
            return $this->error('保存失败，请稍后重试');
        }
    }

    /**
     * 删除计量单位
     */
    public function delete()
    {
        $ids = $this->request->post('ids');
        if (empty($ids)) {
            return $this->error('请选择要删除的数据');
        }

        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        try {
            UnitModel::destroy($ids);
            return $this->success('删除成功');
        } catch (\Exception $e) {
            ContextualLogger::error('Unit delete error: ' . $e->getMessage());
            return $this->error('删除失败，请稍后重试');
        }
    }
}
