<?php
namespace plugin\smartstock\event\listener;

use think\facade\Event;
use plugin\smartstock\event\StockChangedEvent;
use plugin\smartstock\model\StockOut;
use plugin\smartstock\model\StockOutItem;
use plugin\smartstock\model\Stock;
use think\facade\Db;

/**
 * 出库完成监听器
 *
 * 监听 workflow.smartstock_stock_out.complete 事件。
 * 出库单流转到 completed 状态后，从对应仓库库存中扣减明细中的商品数量。
 */
class StockOutCompletedListener
{
    /**
     * 处理出库完成事件
     *
     * @param array $eventData 工作流转换事件数据，包含 workfow/transition/subject/context
     */
    public function handle($eventData): void
    {
        // 从 subject 提取出库单ID（防御式解析）
        $subject = $eventData['subject'] ?? '';
        $parts = explode(':', $subject);
        $stockOutId = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($stockOutId <= 0) {
            return;
        }

        $stockOut = StockOut::find($stockOutId);
        if (!$stockOut) {
            return;
        }

        // 幂等保护：已完成(2)的单据不重复扣减
        if ((int) $stockOut->status === 2) {
            return;
        }

        $items = StockOutItem::where('stock_out_id', $stockOutId)->select();

        Db::startTrans();
        try {
            foreach ($items as $item) {
                $stock = Stock::where([
                    'product_id'   => $item->product_id,
                    'warehouse_id' => $stockOut->warehouse_id,
                ])->lock(true)->find();

                // 可用库存 = 总库存 - 已锁定，出库不允许动用锁定库存
                $available = $stock ? $stock->quantity - $stock->locked_quantity : 0;
                if (!$stock || $available < $item->quantity) {
                    throw new \Exception("商品 {$item->product_id} 库存不足（可用 {$available}），无法出库");
                }

                $beforeQuantity = $stock->quantity;
                $stock->quantity = $beforeQuantity - $item->quantity;
                $stock->save();

                // 触发库存变更事件
                Event::trigger(new StockChangedEvent(
                    $item->product_id,
                    $stockOut->warehouse_id,
                    $beforeQuantity,
                    $stock->quantity,
                    2, // 出库
                    'stock_out',
                    $stockOut->order_no,
                    $stockOut->operator_id ?? 0,
                    '出库操作'
                ));
            }

            // 更新出库单冗余状态字段
            $stockOut->status = 2;
            $stockOut->save();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }
}
