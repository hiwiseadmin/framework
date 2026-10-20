<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class Check extends BaseModel
{
    protected $name = 'smartstock_check';

    /**
     * 关联盘点明细
     */
    public function items()
    {
        return $this->hasMany(CheckItem::class, 'check_id');
    }

    /**
     * 关联仓库
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * 状态文本
     */
    public function getStatusTextAttr($value, $data)
    {
        $statusMap = [0 => '计划中', 1 => '盘点中', 2 => '已完成', 3 => '已处理差异'];
        return $statusMap[$data['status']] ?? '未知';
    }

    public function search($params = [])
    {
        $query = $this;
        if (!empty($params['order_no'])) {
            $query->whereLike('order_no', "%{$params['order_no']}%");
        }
        if (!empty($params['warehouse_id'])) {
            $query->where('warehouse_id', $params['warehouse_id']);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }
        return $query->order('id', 'desc');
    }
}
