<?php
/**
 * 调拨单验证器
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\validate;

use think\Validate;

class TransferValidate extends Validate
{
    protected $rule = [
        'from_warehouse_id' => 'require|integer|>:0|checkWarehouseDifferent',
        'to_warehouse_id'   => 'require|integer|>:0',
        'order_date'        => 'date',
        'remark'            => 'max:500',
    ];

    protected $message = [
        'from_warehouse_id.require' => '调出仓库不能为空',
        'from_warehouse_id.integer' => '调出仓库参数错误',
        'to_warehouse_id.require'   => '调入仓库不能为空',
        'to_warehouse_id.integer'   => '调入仓库参数错误',
        'order_date.date'           => '日期格式不正确',
        'remark.max'                => '备注不能超过500个字符',
        'from_warehouse_id.checkWarehouseDifferent' => '调出仓库和调入仓库不能相同',
    ];

    protected $scene = [
        'save' => ['from_warehouse_id', 'to_warehouse_id', 'order_date', 'remark'],
    ];

    /**
     * 校验调出仓库与调入仓库不能相同
     */
    protected function checkWarehouseDifferent($value, $rule, $data): bool|string
    {
        if (isset($data['to_warehouse_id']) && $value == $data['to_warehouse_id']) {
            return '调出仓库和调入仓库不能相同';
        }
        return true;
    }
}
