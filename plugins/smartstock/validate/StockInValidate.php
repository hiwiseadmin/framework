<?php
/**
 * 入库单验证器
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\validate;

use think\Validate;

class StockInValidate extends Validate
{
    protected $rule = [
        'warehouse_id' => 'require|integer|>:0',
        'type'         => 'require|integer|>:0',
        'order_date'   => 'date',
        'remark'       => 'max:500',
        'total_amount' => 'float',
    ];

    protected $message = [
        'warehouse_id.require' => '入库仓库不能为空',
        'warehouse_id.integer' => '入库仓库参数错误',
        'warehouse_id.>'       => '请选择有效仓库',
        'type.require'         => '入库类型不能为空',
        'type.integer'         => '入库类型参数错误',
        'order_date.date'      => '日期格式不正确',
        'remark.max'           => '备注不能超过500个字符',
        'total_amount.float'   => '总金额格式不正确',
    ];

    protected $scene = [
        'save' => ['warehouse_id', 'type', 'order_date', 'remark', 'total_amount'],
    ];
}
