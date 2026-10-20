<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class InventoryLog extends BaseModel
{
    protected $name = 'smartstock_inventory_log';

    // 不使用软删除（只追加表）
    protected $deleteTime = false;

    // 不可修改
    protected $readonly = ['create_time'];

    // 只追加，不允许更新
    public static function onBeforeUpdate($model)
    {
        return false;
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
