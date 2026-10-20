<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class Warehouse extends BaseModel
{
    protected $name = 'smartstock_warehouse';

    /**
     * 关联库存
     */
    public function stocks()
    {
        return $this->hasMany(Stock::class, 'warehouse_id');
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
        if (!empty($params['code'])) {
            $query->whereLike('code', "%{$params['code']}%");
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }

        return $query->order('sort_order', 'asc')->order('id', 'asc');
    }
}
