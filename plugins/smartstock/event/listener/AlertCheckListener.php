<?php
namespace plugin\smartstock\event\listener;

use plugin\smartstock\event\StockChangedEvent;
use plugin\smartstock\model\Stock;
use plugin\smartstock\model\AlertRule;
use plugin\smartstock\model\AlertLog;
use plugin\smartstock\model\Product;
use app\admin\model\Admin;
use wise\notification\facade\Notification;

/**
 * 预警检测监听器
 *
 * 监听 StockChangedEvent 事件，自动检测库存是否触发预警规则。
 * 支持低库存预警和超库存预警两种类型。
 * 触发后通过 wise-notification 包自动推送站内通知给指定管理员。
 */
class AlertCheckListener
{
    /**
     * 处理库存变更事件
     */
    public function handle(StockChangedEvent $event): void
    {
        $stock = Stock::where([
            'product_id'   => $event->productId,
            'warehouse_id' => $event->warehouseId,
        ])->find();

        if (!$stock) {
            return;
        }

        // 查询适用的预警规则（全局规则 product_id=0, warehouse_id=0 或特定规则）
        $rules = AlertRule::where('status', 1)
            ->where(function ($query) use ($event) {
                $query->where('product_id', $event->productId)
                    ->whereOr('product_id', 0);
            })
            ->where(function ($query) use ($event) {
                $query->where('warehouse_id', $event->warehouseId)
                    ->whereOr('warehouse_id', 0);
            })
            ->select();

        foreach ($rules as $rule) {
            $this->checkRule($rule, $stock, $event->productId, $event->warehouseId);
        }
    }

    /**
     * 检查单个预警规则
     */
    protected function checkRule(AlertRule $rule, Stock $stock, int $productId, int $warehouseId): void
    {
        $triggered      = false;
        $message        = '';
        $thresholdValue = 0;

        if ($rule->alert_type == 1 && $rule->min_quantity > 0) {
            // 低库存预警
            if ($stock->quantity <= $rule->min_quantity) {
                $triggered      = true;
                $thresholdValue = $rule->min_quantity;
                $message        = "商品库存不足：当前库存 {$stock->quantity}，低于最低阈值 {$rule->min_quantity}";
            }
        } elseif ($rule->alert_type == 2 && $rule->max_quantity > 0) {
            // 超库存预警
            if ($stock->quantity >= $rule->max_quantity) {
                $triggered      = true;
                $thresholdValue = $rule->max_quantity;
                $message        = "商品库存超限：当前库存 {$stock->quantity}，高于最高阈值 {$rule->max_quantity}";
            }
        } elseif ($rule->alert_type == 3 && $rule->expiry_days > 0) {
            // 保质期预警
            $product = Product::find($productId);
            if (!$product || !$product->expiry_date) {
                return;
            }
            $expiryTimestamp = strtotime($product->expiry_date);
            $daysLeft        = ($expiryTimestamp - time()) / 86400;
            if ($daysLeft <= $rule->expiry_days) {
                $triggered      = true;
                $thresholdValue = $rule->expiry_days;
                $message        = sprintf(
                    '商品 "%s" 将于 %d 天后过期（到期日：%s），当前库存：%d',
                    $product->name,
                    max(0, (int)$daysLeft),
                    $product->expiry_date,
                    $stock->quantity
                );
            } else {
                return;
            }
        } elseif ($rule->alert_type == 3) {
            return;
        }

        if ($triggered) {
            // 检查是否已有未读预警（去重）
            $exists = AlertLog::where([
                'rule_id'     => $rule->id,
                'product_id'  => $productId,
                'warehouse_id'=> $warehouseId,
                'is_read'     => 0,
            ])->find();

            if (!$exists) {
                AlertLog::create([
                    'rule_id'          => $rule->id,
                    'product_id'       => $productId,
                    'warehouse_id'     => $warehouseId,
                    'alert_type'       => $rule->alert_type,
                    'current_quantity' => $stock->quantity,
                    'threshold_value'  => $thresholdValue,
                    'message'          => $message,
                    'is_read'          => 0,
                ]);

                // 推送站内通知给规则指定的用户
                $this->dispatchNotifications($rule, $message);
            }
        }
    }

    /**
     * 通过 wise-notification DatabaseChannel 推送站内通知
     *
     * @param AlertRule $rule    预警规则
     * @param string    $message 预警消息内容
     */
    protected function dispatchNotifications(AlertRule $rule, string $message): void
    {
        $notifyUsers = $rule->notify_users;

        if (empty($notifyUsers)) {
            return;
        }

        // 支持 JSON 字符串或数组
        if (is_string($notifyUsers)) {
            $notifyUsers = json_decode($notifyUsers, true);
        }

        if (!is_array($notifyUsers) || empty($notifyUsers)) {
            return;
        }

        $typeLabels = [1 => '低库存预警', 2 => '超库存预警', 3 => '保质期预警'];
        $typeLabel  = $typeLabels[$rule->alert_type] ?? '预警';

        $admins = Admin::whereIn('id', $notifyUsers)
            ->where('status', 1)
            ->select();

        if ($admins->isEmpty()) {
            return;
        }

        try {
            foreach ($admins as $admin) {
                Notification::channel('database')
                    ->sync()
                    ->send(
                        $admin,
                        Notification::createMessage(
                            title: "【{$typeLabel}】{$rule->name}",
                            content: $message,
                            data: [
                                'type'      => 'smartstock_alert',
                                'rule_id'   => $rule->id,
                                'alert_type'=> $rule->alert_type,
                                'product_id'=> $rule->product_id,
                            ],
                        )
                    );
            }
        } catch (\Throwable $e) {
            // 通知发送失败不影响主流程（日志写入已完成）
            // 可在此记录发送失败日志
        }
    }
}
