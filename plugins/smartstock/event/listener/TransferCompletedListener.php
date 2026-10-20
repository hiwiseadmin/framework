<?php
namespace plugin\smartstock\event\listener;

use think\facade\Event;
use plugin\smartstock\event\StockChangedEvent;
use plugin\smartstock\model\Transfer;
use plugin\smartstock\model\TransferItem;
use plugin\smartstock\model\Stock;
use think\facade\Db;

/**
 * 调拨完成监听器
 *
 * 监听 workflow.smartstock_transfer.complete 事件。
 * 调拨单流转到 completed 状态后，执行双仓原子操作：
 * - 调出仓库扣减库存
 * - 调入仓库增加库存
 */
class TransferCompletedListener
{
    /**
     * 处理调拨完成事件
     *
     * @param array $eventData 工作流转换事件数据，包含 workfow/transition/subject/context
     */
    public function handle($eventData): void
    {
        // 从 subject 提取调拨单ID（防御式解析）
        $subject = $eventData['subject'] ?? '';
        $parts = explode(':', $subject);
        $transferId = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($transferId <= 0) {
            return;
        }

        $transfer = Transfer::find($transferId);
        if (!$transfer) {
            return;
        }

        // 幂等保护：已完成(3)的单据不重复执行调拨
        if ((int) $transfer->status === 3) {
            return;
        }

        $items = TransferItem::where('transfer_id', $transferId)->select();

        Db::startTrans();
        try {
            foreach ($items as $item) {
                // 调出仓库扣减（行级锁防并发），可用库存 = 总库存 - 锁定
                $fromStock = Stock::where([
                    'product_id'   => $item->product_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                ])->lock(true)->find();

                $fromAvailable = $fromStock ? $fromStock->quantity - $fromStock->locked_quantity : 0;
                if (!$fromStock || $fromAvailable < $item->quantity) {
                    throw new \Exception("仓库 {$transfer->from_warehouse_id} 商品 {$item->product_id} 库存不足（可用 {$fromAvailable}），无法调拨");
                }

                $fromBefore = $fromStock->quantity;
                $fromStock->quantity = $fromBefore - $item->quantity;
                $fromStock->save();

                // 调入仓库增加（行级锁防并发）
                $toStock = Stock::where([
                    'product_id'   => $item->product_id,
                    'warehouse_id' => $transfer->to_warehouse_id,
                ])->lock(true)->find();

                if (!$toStock) {
                    $toStock = new Stock();
                    $toStock->product_id   = $item->product_id;
                    $toStock->warehouse_id = $transfer->to_warehouse_id;
                    $toStock->quantity     = 0;
                }

                $toBefore = $toStock->quantity;
                $toStock->quantity = $toBefore + $item->quantity;
                $toStock->save();

                // 触发调出仓库库存变更事件
                Event::trigger(new StockChangedEvent(
                    $item->product_id,
                    $transfer->from_warehouse_id,
                    $fromBefore,
                    $fromStock->quantity,
                    4, // 调拨出
                    'transfer',
                    $transfer->order_no,
                    $transfer->operator_id ?? 0,
                    '调拨出库'
                ));

                // 触发调入仓库库存变更事件
                Event::trigger(new StockChangedEvent(
                    $item->product_id,
                    $transfer->to_warehouse_id,
                    $toBefore,
                    $toStock->quantity,
                    3, // 调拨入
                    'transfer',
                    $transfer->order_no,
                    $transfer->operator_id ?? 0,
                    '调拨入库'
                ));
            }

            // 更新调拨单冗余状态
            $transfer->status = 3;
            $transfer->save();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }
}
