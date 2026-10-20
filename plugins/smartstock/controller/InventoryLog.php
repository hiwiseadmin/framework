<?php
/**
 * 库存流水查询控制器
 *
 * 只读查询，提供库存变更流水记录的查询功能。
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\controller;

use plugin\smartstock\model\Warehouse;
use plugin\smartstock\service\InventoryLogService;
class InventoryLog extends Base
{
    /**
     * 库存流水查询页
     */
    public function index()
    {
        $warehouses = Warehouse::where('status', 1)->order('sort_order', 'asc')->select();

        return $this->assign([
            'warehouses'   => $warehouses,
            'change_types' => [
                1 => '入库',
                2 => '出库',
                3 => '调拨入',
                4 => '调拨出',
                5 => '盘点调整',
                6 => '初始化',
            ],
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

        $params['pageNumber'] = $page;
        $params['pageSize']   = $pageSize;

        $service = new InventoryLogService();
        $list    = $service->search($params);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['product_name']   = $item->product ? $item->product->name : '';
            $row['warehouse_name'] = $item->warehouse ? $item->warehouse->name : '';
            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }
}
