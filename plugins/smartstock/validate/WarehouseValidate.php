<?php
namespace plugin\smartstock\validate;

use think\Validate;

class WarehouseValidate extends Validate
{
    protected $rule = [
        'name'          => 'require|max:100',
        'code'          => 'require|max:32',
        'address'       => 'max:500',
        'contact_person'=> 'max:50',
        'contact_phone' => 'max:20',
        'sort_order'    => 'integer',
        'status'        => 'in:0,1',
    ];

    protected $message = [
        'name.require'  => '仓库名称不能为空',
        'name.max'      => '仓库名称不能超过100个字符',
        'code.require'  => '仓库编码不能为空',
        'code.max'      => '仓库编码不能超过32个字符',
        'address.max'   => '仓库地址不能超过500个字符',
        'contact_person.max' => '联系人不能超过50个字符',
        'contact_phone.max'  => '联系电话不能超过20个字符',
        'sort_order.integer' => '排序必须是整数',
        'status.in'         => '状态值不正确',
    ];

    protected $scene = [
        'save' => ['name', 'code', 'address', 'contact_person', 'contact_phone', 'sort_order', 'status'],
    ];
}
