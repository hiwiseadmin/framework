<?php
/**
 * 库存流水服务
 *
 * 职责：
 * - 流水记录写入
 * - 流水多条件查询
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\service;

use plugin\smartstock\model\InventoryLog;
use plugin\smartstock\event\StockChangedEvent;

class InventoryLogService
{
    /**
     * 写入库存流水记录
     *
     * @param StockChangedEvent $event 库存变更事件
     * @return void
     */
    public function log(StockChangedEvent $event): void
    {
        $log = new InventoryLog();
        $log->save([
            'product_id'      => $event->productId,
            'warehouse_id'    => $event->warehouseId,
            'change_type'     => $event->changeType,
            'change_quantity' => $event->after - $event->before,
            'before_quantity' => $event->before,
            'after_quantity'  => $event->after,
            'reference_type'  => $event->referenceType,
            'reference_no'    => $event->referenceNo,
            'remark'          => $event->remark,
            'operator_id'     => $event->operatorId,
        ]);
    }

    /**
     * 多条件查询流水记录
     *
     * @param array $params 查询参数
     *   - product_id:   商品ID
     *   - warehouse_id: 仓库ID
     *   - change_type:  变更类型
     *   - start_time:   开始时间
     *   - end_time:     结束时间
     *   - reference_type: 来源类型
     * @return \think\Paginator
     */
    public function search(array $params): \think\Paginator
    {
        $query = InventoryLog::with(['product', 'warehouse']);

        if (!empty($params['product_id'])) {
            $query->where('product_id', $params['product_id']);
        }
        if (!empty($params['warehouse_id'])) {
            $query->where('warehouse_id', $params['warehouse_id']);
        }
        if (!empty($params['change_type'])) {
            $query->where('change_type', $params['change_type']);
        }
        if (!empty($params['reference_type'])) {
            $query->where('reference_type', $params['reference_type']);
        }
        if (!empty($params['start_time'])) {
            $query->where('create_time', '>=', $params['start_time']);
        }
        if (!empty($params['end_time'])) {
            $query->where('create_time', '<=', $params['end_time']);
        }

        $page     = $params['pageNumber'] ?? 1;
        $pageSize = $params['pageSize'] ?? 20;

        return $query->order('id', 'desc')->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);
    }
}
