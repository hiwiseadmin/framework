<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class Unit extends BaseModel
{
    protected $name = 'smartstock_unit';

    /**
     * 关联商品
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'unit_id');
    }

    public function getStatusTextAttr($value, $data)
    {
        $statusMap = [0 => '禁用', 1 => '启用'];
        return $statusMap[$data['status']] ?? '未知';
    }

    public function search($params = [])
    {
        $query = $this;
        if (!empty($params['name'])) {
            $query->whereLike('name', "%{$params['name']}%");
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }
        return $query->order('id', 'asc');
    }
}
