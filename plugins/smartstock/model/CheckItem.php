<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class CheckItem extends BaseModel
{
    protected $name = 'smartstock_check_item';

    // 不使用时间戳
    protected $autoWriteTimestamp = false;

    // 不使用软删除
    protected $deleteTime = false;

    /**
     * 关联盘点单
     */
    public function check()
    {
        return $this->belongsTo(Check::class, 'check_id');
    }

    /**
     * 关联商品
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
