<?php
namespace plugin\smartstock\validate;

use think\Validate;

class ProductCategoryValidate extends Validate
{
    protected $rule = [
        'name'      => 'require|max:100',
        'parent_id' => 'integer',
        'sort_order'=> 'integer',
        'status'    => 'in:0,1',
    ];

    protected $message = [
        'name.require'  => '分类名称不能为空',
        'name.max'      => '分类名称不能超过100个字符',
        'parent_id.integer' => '父级分类ID必须是整数',
        'sort_order.integer' => '排序必须是整数',
        'status.in'         => '状态值不正确',
    ];

    protected $scene = [
        'save' => ['name', 'parent_id', 'sort_order', 'status'],
    ];
}
