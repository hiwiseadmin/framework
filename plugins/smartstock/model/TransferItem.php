<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class TransferItem extends BaseModel
{
    protected $name = 'smartstock_transfer_item';

    // 不使用时间戳
    protected $autoWriteTimestamp = false;

    // 不使用软删除
    protected $deleteTime = false;

    /**
     * 关联调拨单
     */
    public function transfer()
    {
        return $this->belongsTo(Transfer::class, 'transfer_id');
    }

    /**
     * 关联商品
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
