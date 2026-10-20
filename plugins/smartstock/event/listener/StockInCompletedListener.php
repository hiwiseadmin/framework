<?php
namespace plugin\smartstock\event\listener;

use think\facade\Event;
use plugin\smartstock\event\StockChangedEvent;
use plugin\smartstock\model\StockIn;
use plugin\smartstock\model\StockInItem;
use plugin\smartstock\model\Stock;
use think\facade\Db;

/**
 * 入库完成监听器
 *
 * 监听 workflow.smartstock_stock_in.complete 事件。
 * 入库单流转到 completed 状态后，将明细中的商品数量增加到对应仓库库存。
 */
class StockInCompletedListener
{
    /**
     * 处理入库完成事件
     *
     * @param array $eventData 工作流转换事件数据，包含 workfow/transition/subject/context
     */
    public function handle($eventData): void
    {
        // 从 subject 提取入库单ID（防御式解析）
        $subject = $eventData['subject'] ?? '';
        $parts = explode(':', $subject);
        $stockInId = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($stockInId <= 0) {
            return;
        }

        $stockIn = StockIn::find($stockInId);
        if (!$stockIn) {
            return;
        }

        // 幂等保护：已完成(2)的单据不重复入账
        if ((int) $stockIn->status === 2) {
            return;
        }

        $items = StockInItem::where('stock_in_id', $stockInId)->select();

        Db::startTrans();
        try {
            foreach ($items as $item) {
                // 查找或创建库存记录（行级锁防并发）
                $stock = Stock::where([
                    'product_id'   => $item->product_id,
                    'warehouse_id' => $stockIn->warehouse_id,
                ])->lock(true)->find();

                if (!$stock) {
                    $stock = new Stock();
                    $stock->product_id   = $item->product_id;
                    $stock->warehouse_id = $stockIn->warehouse_id;
                    $stock->quantity     = 0;
                }

                $beforeQuantity = $stock->quantity;
                $stock->quantity = $beforeQuantity + $item->quantity;
                $stock->save();

                // 触发库存变更事件
                Event::trigger(new StockChangedEvent(
                    $item->product_id,
                    $stockIn->warehouse_id,
                    $beforeQuantity,
                    $stock->quantity,
                    1, // 入库
                    'stock_in',
                    $stockIn->order_no,
                    $stockIn->operator_id ?? 0,
                    '入库操作'
                ));
            }

            // 更新入库单冗余状态字段
            $stockIn->status = 2;
            $stockIn->save();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }
}
