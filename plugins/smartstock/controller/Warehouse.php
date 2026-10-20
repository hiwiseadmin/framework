<?php
namespace plugin\smartstock\controller;


use wise\support\context\ContextualLogger;
use plugin\smartstock\model\Warehouse as WarehouseModel;
use plugin\smartstock\validate\WarehouseValidate;
use plugin\smartstock\service\WarehouseService;
class Warehouse extends Base
{
    /**
     * 仓库列表页
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
        $params = $this->buildQueryParams();
        $page   = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 10);

        $query = (new WarehouseModel())->search($params);

        $list = $query->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['status_text'] = $item->status_text;
            $rows[] = $row;
        }

        return $this->success('', [
            'total' => $list->total(),
            'rows'  => $rows,
        ]);
    }

    /**
     * 新增/编辑仓库
     */
    public function save()
    {
        $data = $this->request->post();

        // 验证
        $validate = new WarehouseValidate();
        if (!$validate->scene('save')->check($data)) {
            return $this->error($validate->getError());
        }

        $id = $data['id'] ?? 0;

        // 检查编码唯一性
        $warehouseService = new WarehouseService();
        if (!$warehouseService->isCodeUnique($data['code'] ?? '', $id)) {
            return $this->error('仓库编码已存在');
        }

        try {
            // 仅允许白名单字段
            $allowFields = ['name', 'code', 'address', 'contact', 'phone', 'status', 'sort_order', 'remark'];
            $saveData = array_intersect_key($data, array_flip($allowFields));

            if ($id > 0) {
                $warehouse = WarehouseModel::find($id);
                if (!$warehouse) {
                    return $this->error('仓库不存在');
                }
                $warehouse->save($saveData);
            } else {
                $warehouse = new WarehouseModel();
                $warehouse->save($saveData);
            }

            return $this->success('保存成功', ['id' => $warehouse->id]);
        } catch (\Exception $e) {
            ContextualLogger::error('Warehouse save error: ' . $e->getMessage());
            return $this->error('保存失败，请稍后重试');
        }
    }

    /**
     * 删除仓库（软删除）
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
            WarehouseModel::destroy($ids);
            return $this->success('删除成功');
        } catch (\Exception $e) {
            ContextualLogger::error('Warehouse delete error: ' . $e->getMessage());
            return $this->error('删除失败，请稍后重试');
        }
    }

    /**
     * 获取启用的仓库列表（用于下拉选择器）
     */
    public function getEnabled()
    {
        $warehouseService = new WarehouseService();
        $list = $warehouseService->getEnabledList();
        return $this->success('ok', $list);
    }
}
