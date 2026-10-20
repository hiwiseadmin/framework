<?php
/**
 * SmartStock 事件监听定义
 */

use plugin\smartstock\event\StockChangedEvent;
use plugin\smartstock\event\listener\InventoryLogListener;
use plugin\smartstock\event\listener\AlertCheckListener;
use plugin\smartstock\event\listener\StockInCompletedListener;
use plugin\smartstock\event\listener\StockOutCompletedListener;
use plugin\smartstock\event\listener\TransferCompletedListener;
use plugin\smartstock\event\listener\CheckResolvedListener;

return [
    'bind' => [
    ],
    'listen' => [
        // 工作流事件 -> 业务逻辑联动
        'workflow.smartstock_stock_in.complete' => [
            StockInCompletedListener::class,
        ],
        'workflow.smartstock_stock_out.complete' => [
            StockOutCompletedListener::class,
        ],
        'workflow.smartstock_transfer.complete' => [
            TransferCompletedListener::class,
        ],
        'workflow.smartstock_check.resolve' => [
            CheckResolvedListener::class,
        ],
        // 库存变更事件 -> 流水记录 + 预警检测
        StockChangedEvent::class => [
            InventoryLogListener::class,
            AlertCheckListener::class,
        ],
    ],
    'subscribe' => [
    ],
];
