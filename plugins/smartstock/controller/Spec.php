<?php
/**
 * 规格模板控制器
 *
 * 提供产品规格模板的 CRUD 功能。
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\controller;


use wise\support\context\ContextualLogger;
use plugin\smartstock\model\ProductSpec;
class Spec extends Base
{
    /**
     * 规格模板列表页
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

        $query = (new ProductSpec());
        if (!empty($params['name'])) {
            $query->whereLike('name', "%{$params['name']}%");
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }

        $list = $query->order('id', 'asc')->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['status_text'] = $item->status_text;
            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    /**
     * 新增/编辑规格模板
     */
    public function save()
    {
        $data = $this->request->post();
        $id   = $data['id'] ?? 0;

        if (empty($data['name'])) {
            return $this->error('规格名称不能为空');
        }

        try {
            // 处理 options
            if (isset($data['options_raw']) && is_string($data['options_raw'])) {
                $options = array_filter(explode(',', $data['options_raw']));
                $data['options'] = json_encode($options, JSON_UNESCAPED_UNICODE);
                unset($data['options_raw']);
            }

            if ($id > 0) {
                $spec = ProductSpec::find($id);
                if (!$spec) {
                    return $this->error('规格模板不存在');
                }
                $spec->save($data);
            } else {
                $spec = new ProductSpec();
                $spec->save($data);
            }

            return $this->success('保存成功', ['id' => $spec->id]);
        } catch (\Exception $e) {
            ContextualLogger::error('Spec save error: ' . $e->getMessage());
            return $this->error('保存失败，请稍后重试');
        }
    }

    /**
     * 删除规格模板
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
            ProductSpec::destroy($ids);
            return $this->success('删除成功');
        } catch (\Exception $e) {
            ContextualLogger::error('Spec delete error: ' . $e->getMessage());
            return $this->error('删除失败，请稍后重试');
        }
    }
}
