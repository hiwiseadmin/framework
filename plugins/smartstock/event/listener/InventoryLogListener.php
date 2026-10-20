<?php
namespace plugin\smartstock\event\listener;

use plugin\smartstock\event\StockChangedEvent;
use plugin\smartstock\model\InventoryLog;

/**
 * 库存流水记录监听器
 *
 * 监听 StockChangedEvent 事件，将每次库存变更写入 inventory_log 表。
 * 库存流水表为只追加表，不支持修改和删除。
 */
class InventoryLogListener
{
    /**
     * 处理库存变更事件
     */
    public function handle(StockChangedEvent $event): void
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
}
