<?php
namespace plugin\shortlink\validate;

use think\Validate;

class Group extends Validate
{
    protected $rule = [
        'name'        => 'require|max:100',
        'ident'       => 'max:100|alphaDash',
        'description' => 'max:255',
        'sort_order'  => 'integer',
        'status'      => 'in:0,1',
    ];

    protected $message = [
        'name.require'      => '分组名称不能为空',
        'name.max'          => '分组名称长度不能超过 100 个字符',
        'ident.max'         => '分组标识长度不能超过 100 个字符',
        'ident.alphaDash'   => '分组标识只能包含字母、数字、下划线或中划线',
        'description.max'   => '分组描述长度不能超过 255 个字符',
        'sort_order.integer' => '排序值必须为整数',
        'status.in'         => '状态值不正确',
    ];

    protected $scene = [
        'save' => ['name', 'ident', 'description', 'sort_order', 'status'],
    ];
}
