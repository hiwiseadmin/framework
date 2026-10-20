<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class StockOutItem extends BaseModel
{
    protected $name = 'smartstock_stock_out_item';

    // 不使用时间戳
    protected $autoWriteTimestamp = false;

    // 不使用软删除
    protected $deleteTime = false;

    /**
     * 关联出库单
     */
    public function stockOut()
    {
        return $this->belongsTo(StockOut::class, 'stock_out_id');
    }

    /**
     * 关联商品
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
