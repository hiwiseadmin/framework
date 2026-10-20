<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class StockInItem extends BaseModel
{
    protected $name = 'smartstock_stock_in_item';

    // 不使用时间戳
    protected $autoWriteTimestamp = false;

    // 不使用软删除
    protected $deleteTime = false;

    /**
     * 关联入库单
     */
    public function stockIn()
    {
        return $this->belongsTo(StockIn::class, 'stock_in_id');
    }

    /**
     * 关联商品
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
