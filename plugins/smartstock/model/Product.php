<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

/**
 * @property string|null $expiry_date
 */
class Product extends BaseModel
{
    protected $name = 'smartstock_product';

    protected $schema = [
        'id'           => 'int',
        'name'         => 'string',
        'sku_code'     => 'string',
        'category_id'  => 'int',
        'unit_id'      => 'int',
        'barcode'      => 'string',
        'spec_summary' => 'string',
        'cost_price'   => 'float',
        'selling_price'=> 'float',
        'image'        => 'string',
        'description'  => 'string',
        'expiry_date'  => 'date',
        'status'       => 'int',
        'create_time'   => 'datetime',
        'update_time'   => 'datetime',
        'delete_time'   => 'datetime',
    ];

    /**
     * 关联商品分类
     */
    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * 关联计量单位
     */
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * 关联规格值
     */
    public function specValues()
    {
        return $this->hasMany(ProductSpecValue::class, 'product_id');
    }

    /**
     * 关联库存
     */
    public function stocks()
    {
        return $this->hasMany(Stock::class, 'product_id');
    }

    /**
     * 状态获取器
     */
    public function getStatusTextAttr($value, $data)
    {
        $statusMap = [0 => '禁用', 1 => '启用'];
        return $statusMap[$data['status']] ?? '未知';
    }

    /**
     * 搜索方法
     */
    public function search($params = [])
    {
        $query = $this;

        if (!empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->whereLike('name', "%{$params['keyword']}%")
                  ->whereOrLike('sku_code', "%{$params['keyword']}%");
            });
        }
        if (!empty($params['name'])) {
            $query->whereLike('name', "%{$params['name']}%");
        }
        if (!empty($params['sku_code'])) {
            $query->whereLike('sku_code', "%{$params['sku_code']}%");
        }
        if (!empty($params['category_id'])) {
            $query->where('category_id', $params['category_id']);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }

        return $query->order('id', 'desc');
    }
}
