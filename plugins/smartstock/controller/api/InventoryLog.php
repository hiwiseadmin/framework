<?php
namespace plugin\smartstock\controller\api;

use plugin\ApiController;
use plugin\smartstock\model\InventoryLog as InventoryLogModel;

/**
 * SmartStock 库存流水 API 控制器
 *
 * GET /api/smartstock/inventory/log — 库存流水
 *
 * @author 齐活林 (Qi)
 * @date   2026-06-19
 */
class InventoryLog extends ApiController
{
    /**
     * 中间件：JWT 认证
     *
     * @var array
     */
    protected $middleware = [
        \wise\member\middleware\JwtAuth::class,
    ];

    /**
     * 库存流水查询
     *
     * GET /api/smartstock/inventory/log
     *
     * @query product_id    int
     * @query warehouse_id  int
     * @query change_type   int    1入库 2出库 3调拨 4盘点 5调整
     * @query start_time    string Y-m-d H:i:s
     * @query end_time      string Y-m-d H:i:s
     * @query page          int
     * @query pageSize      int
     */
    public function log()
    {
        $productId   = $this->request->param('product_id/d', 0);
        $warehouseId = $this->request->param('warehouse_id/d', 0);
        $changeType  = $this->request->param('change_type/d', 0);
        $startTime   = $this->request->param('start_time', '');
        $endTime     = $this->request->param('end_time', '');
        $page        = $this->request->param('page/d', 1);
        $pageSize    = $this->request->param('pageSize/d', 20);

        $query = InventoryLogModel::with(['product', 'warehouse']);

        if ($productId > 0) {
            $query->where('product_id', $productId);
        }
        if ($warehouseId > 0) {
            $query->where('warehouse_id', $warehouseId);
        }
        if ($changeType > 0) {
            $query->where('change_type', $changeType);
        }
        if (!empty($startTime)) {
            $query->where('create_time', '>=', $startTime);
        }
        if (!empty($endTime)) {
            $query->where('create_time', '<=', $endTime);
        }

        $list = $query->order('id', 'desc')->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);

        $rows = [];
        foreach ($list->items() as $item) {
            $rows[] = [
                'id'              => $item->id,
                'product_id'      => $item->product_id,
                'product_name'    => $item->product->name ?? '',
                'warehouse_id'    => $item->warehouse_id,
                'warehouse_name'  => $item->warehouse->name ?? '',
                'change_type'     => $item->change_type,
                'change_quantity' => $item->change_quantity,
                'before_quantity' => $item->before_quantity,
                'after_quantity'  => $item->after_quantity,
                'reference_type'  => $item->reference_type,
                'reference_no'    => $item->reference_no,
                'remark'          => $item->remark,
                'create_time'     => $item->create_time,
            ];
        }

        return $this->success('ok', [
            'total' => $list->total(),
            'page'  => $page,
            'rows'  => $rows,
        ]);
    }
}
