<?php
/**
 * SmartStock 插件配置
 *
 * 获取方式：config('smartstock.app.xxx')
 */

return [
    // 分页配置
    'page_size' => 20,

    // 预警默认配置
    'alert' => [
        'min_quantity'    => 10,      // 默认低库存阈值
        'max_quantity'    => 1000,    // 默认超库存阈值
        'expiry_days'     => 30,      // 默认保质期预警天数
        'check_interval'  => 3600,    // 预警检测间隔（秒）
    ],

    // 单号前缀
    'order_no' => [
        'stock_in'  => 'IN',
        'stock_out' => 'OUT',
        'transfer'  => 'TF',
        'check'     => 'CK',
    ],

    // 入库类型枚举
    'stock_in_types' => [
        1 => '采购入库',
        2 => '退货入库',
        3 => '调拨入库',
        4 => '盘盈入库',
        5 => '其他入库',
    ],

    // 出库类型枚举
    'stock_out_types' => [
        1 => '销售出库',
        2 => '报损出库',
        3 => '调拨出库',
        4 => '盘亏出库',
        5 => '其他出库',
    ],

    // 出库策略
    'stock_out_strategy' => 'fifo',
];
