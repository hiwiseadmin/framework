<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class ProductCategory extends BaseModel
{
    protected $name = 'smartstock_product_category';

    /**
     * 关联父级分类
     */
    public function parent()
    {
        return $this->belongsTo(ProductCategory::class, 'parent_id');
    }

    /**
     * 关联子级分类
     */
    public function children()
    {
        return $this->hasMany(ProductCategory::class, 'parent_id');
    }

    /**
     * 关联商品
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
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

        if (!empty($params['name'])) {
            $query->whereLike('name', "%{$params['name']}%");
        }
        if (!empty($params['parent_id'])) {
            $query->where('parent_id', $params['parent_id']);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }

        return $query->order('sort_order', 'asc')->order('id', 'asc');
    }
}
