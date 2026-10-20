<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class Stock extends BaseModel
{
    protected $name = 'smartstock_stock';

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
     * 关联仓库
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * 可用库存获取器（实际库存 - 锁定库存）
     */
    public function getAvailableQuantityAttr($value, $data)
    {
        return ($data['quantity'] ?? 0) - ($data['locked_quantity'] ?? 0);
    }

    /**
     * 搜索方法
     */
    public function search($params = [])
    {
        $query = $this;

        if (!empty($params['product_id'])) {
            $query->where('product_id', $params['product_id']);
        }
        if (!empty($params['warehouse_id'])) {
            $query->where('warehouse_id', $params['warehouse_id']);
        }
        if (!empty($params['keyword'])) {
            $query->whereHas('product', function ($q) use ($params) {
                $q->whereLike('name', "%{$params['keyword']}%")
                  ->whereOrLike('sku_code', "%{$params['keyword']}%");
            });
        }
        if (isset($params['low_stock']) && $params['low_stock']) {
            $query->whereColumn('quantity', '<=', 'alert_min')->where('alert_min', '>', 0);
        }
        if (isset($params['over_stock']) && $params['over_stock']) {
            $query->whereColumn('quantity', '>=', 'alert_max')->where('alert_max', '>', 0);
        }

        return $query->order('id', 'desc');
    }
}
