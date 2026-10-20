<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class ProductSpecValue extends BaseModel
{
    protected $name = 'smartstock_product_spec_value';

    // 时间戳
    protected $autoWriteTimestamp = false;

    // 不使用软删除
    protected $deleteTime = false;

    /**
     * 关联商品
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * 关联规格
     */
    public function spec()
    {
        return $this->belongsTo(ProductSpec::class, 'spec_id');
    }
}
