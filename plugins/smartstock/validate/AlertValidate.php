<?php
namespace plugin\smartstock\validate;

use think\Validate;

/**
 * 预警规则验证器
 */
class AlertValidate extends Validate
{
    protected $rule = [
        'name'         => 'require|max:100',
        'alert_type'   => 'require|in:1,2,3',
        'product_id'   => 'require|integer',
        'warehouse_id' => 'require|integer',
        'min_quantity' => 'requireIf:alert_type,1|integer|>=:0',
        'max_quantity' => 'requireIf:alert_type,2|integer|>=:0',
        'expiry_days'  => 'requireIf:alert_type,3|integer|>=:1',
        'status'       => 'require|in:0,1',
    ];

    protected $message = [
        'name.require'            => '规则名称不能为空',
        'name.max'                => '规则名称不能超过100个字符',
        'alert_type.require'      => '预警类型不能为空',
        'alert_type.in'           => '预警类型不正确',
        'product_id.require'      => '适用商品不能为空',
        'product_id.integer'      => '适用商品格式不正确',
        'warehouse_id.require'    => '适用仓库不能为空',
        'warehouse_id.integer'    => '适用仓库格式不正确',
        'min_quantity.requireIf'  => '最低库存数不能为空',
        'min_quantity.integer'    => '最低库存数必须是整数',
        'min_quantity.>='         => '最低库存数不能小于0',
        'max_quantity.requireIf'  => '最高库存数不能为空',
        'max_quantity.integer'    => '最高库存数必须是整数',
        'max_quantity.>='         => '最高库存数不能小于0',
        'expiry_days.requireIf'   => '保质期预警天数不能为空',
        'expiry_days.integer'     => '保质期预警天数必须是整数',
        'expiry_days.>='          => '保质期预警天数至少为1',
        'status.require'          => '状态不能为空',
        'status.in'               => '状态值不正确',
    ];
}
