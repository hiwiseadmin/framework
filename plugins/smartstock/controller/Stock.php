<?php
/**
 * 库存查询控制器
 *
 * 提供按商品+仓库多维度库存查询，支持手动调整库存。
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\controller;


use wise\support\context\ContextualLogger;
use plugin\smartstock\model\Stock as StockModel;
use plugin\smartstock\model\Warehouse;
use plugin\smartstock\service\StockService;
use app\admin\facade\CurrentAdmin;
class Stock extends Base
{
    /**
     * 库存查询页
     */
    public function index()
    {
        $warehouses = Warehouse::where('status', 1)->order('sort_order', 'asc')->select();
        return $this->assign([
            'warehouses' => $warehouses,
        ])->fetch();
    }

    /**
     * Bootstrap Table 分页数据
     */
    public function getList()
    {
        $params   = $this->buildQueryParams();
        $page     = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 20);

        $query = (new StockModel())->search($params)
            ->with(['product', 'warehouse']);

        $list = $query->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['product_name']      = $item->product ? $item->product->name : '';
            $row['product_sku']       = $item->product ? $item->product->sku_code : '';
            $row['warehouse_name']    = $item->warehouse ? $item->warehouse->name : '';
            $row['available_quantity'] = $item->available_quantity;
            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    /**
     * 手动调整库存
     */
    public function adjust()
    {
        $data = $this->request->post();

        $productId   = $data['product_id'] ?? 0;
        $warehouseId = $data['warehouse_id'] ?? 0;
        $newQuantity = $data['new_quantity'] ?? null;
        $reason      = $data['reason'] ?? '';

        if (empty($productId) || empty($warehouseId) || $newQuantity === null) {
            return $this->error('参数不完整');
        }

        // 严格数值校验：拒绝 'abc'、'1e3' 等非法输入，避免强转静默清零库存
        if (!is_numeric($newQuantity) || $newQuantity < 0) {
            return $this->error('库存数量必须是不小于0的数字');
        }

        $reason = mb_substr((string)$reason, 0, 200);

        try {
            $stockService = new StockService();
            $stockService->updateStock(
                (int)$productId,
                (int)$warehouseId,
                (int)$newQuantity,
                [
                    'referenceType' => 'stock',
                    'referenceNo'   => '',
                    'operatorId'    => CurrentAdmin::getId(),
                    'remark'        => $reason ?: '手动调整库存',
                ]
            );

            return $this->success('库存调整成功');
        } catch (\Exception $e) {
            ContextualLogger::error('Stock adjust error: ' . $e->getMessage());
            return $this->error('调整失败，请稍后重试');
        }
    }
}
