<?php
namespace plugin\smartstock\validate;

use think\Validate;

class ProductValidate extends Validate
{
    protected $rule = [
        'name'          => 'require|max:200',
        'sku_code'      => 'max:64',
        'category_id'   => 'integer',
        'unit_id'       => 'integer',
        'barcode'       => 'max:64',
        'spec_summary'  => 'max:500',
        'cost_price'    => 'float',
        'selling_price' => 'float',
        'status'        => 'in:0,1',
    ];

    protected $message = [
        'name.require'      => '商品名称不能为空',
        'name.max'          => '商品名称不能超过200个字符',
        'sku_code.max'      => 'SKU编码不能超过64个字符',
        'category_id.integer'=> '分类ID必须是整数',
        'unit_id.integer'   => '单位ID必须是整数',
        'barcode.max'       => '条码不能超过64个字符',
        'cost_price.float'  => '成本价必须是数字',
        'selling_price.float'=> '销售价必须是数字',
        'status.in'         => '状态值不正确',
    ];

    protected $scene = [
        'save' => ['name', 'sku_code', 'category_id', 'unit_id', 'barcode', 'spec_summary', 'cost_price', 'selling_price', 'status'],
    ];
}
