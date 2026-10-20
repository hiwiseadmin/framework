<?php
namespace plugin\smartstock\event\listener;

use think\facade\Event;
use plugin\smartstock\event\StockChangedEvent;
use plugin\smartstock\model\Check;
use plugin\smartstock\model\CheckItem;
use plugin\smartstock\model\Stock;
use think\facade\Db;

/**
 * 盘点差异处理监听器
 *
 * 监听 workflow.smartstock_check.resolve 事件。
 * 盘点差异确认后，自动根据盘盈/盘亏调整实际库存。
 */
class CheckResolvedListener
{
    /**
     * 处理盘点差异确认事件
     *
     * @param array $eventData 工作流转换事件数据，包含 workfow/transition/subject/context
     */
    public function handle($eventData): void
    {
        // 从 subject 提取盘点单ID（防御式解析）
        $subject = $eventData['subject'] ?? '';
        $parts = explode(':', $subject);
        $checkId = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($checkId <= 0) {
            return;
        }

        $check = Check::find($checkId);
        if (!$check) {
            return;
        }

        // 幂等保护：已解决(3)的盘点单不重复调整
        if ((int) $check->status === 3) {
            return;
        }

        $items = CheckItem::where('check_id', $checkId)->select();

        Db::startTrans();
        try {
            foreach ($items as $item) {
                $diff = $item->diff_quantity;
                if ($diff == 0) {
                    continue;
                }

                $stock = Stock::where([
                    'product_id'   => $item->product_id,
                    'warehouse_id' => $check->warehouse_id,
                ])->lock(true)->find();

                if (!$stock) {
                    $stock = new Stock();
                    $stock->product_id   = $item->product_id;
                    $stock->warehouse_id = $check->warehouse_id;
                    $stock->quantity     = 0;
                }

                $beforeQuantity = $stock->quantity;
                $stock->quantity = $item->actual_quantity;
                $stock->save();

                // 变更类型：盘盈/盘亏均记为 5，通过 change_quantity 正负区分
                $changeType = 5;
                $remark     = $diff > 0 ? '盘盈调整' : '盘亏调整';

                Event::trigger(new StockChangedEvent(
                    $item->product_id,
                    $check->warehouse_id,
                    $beforeQuantity,
                    $stock->quantity,
                    $changeType,
                    'check',
                    $check->order_no,
                    $check->operator_id ?? 0,
                    $remark
                ));
            }

            // 更新盘点单冗余状态
            $check->status = 3;
            $check->save();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }
}
