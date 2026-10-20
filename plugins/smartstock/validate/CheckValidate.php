<?php
/**
 * 盘点单验证器
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\validate;

use think\Validate;

class CheckValidate extends Validate
{
    protected $rule = [
        'warehouse_id' => 'require|integer|>:0',
        'remark'       => 'max:500',
    ];

    protected $message = [
        'warehouse_id.require' => '盘点仓库不能为空',
        'warehouse_id.integer' => '盘点仓库参数错误',
        'warehouse_id.>'       => '请选择有效仓库',
        'remark.max'           => '备注不能超过500个字符',
    ];

    protected $scene = [
        'save' => ['warehouse_id', 'remark'],
    ];
}
