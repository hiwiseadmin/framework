<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class StockOut extends BaseModel
{
    protected $name = 'smartstock_stock_out';

    /**
     * 关联出库明细
     */
    public function items()
    {
        return $this->hasMany(StockOutItem::class, 'stock_out_id');
    }

    /**
     * 关联仓库
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * 出库类型文本
     */
    public function getTypeTextAttr($value, $data)
    {
        $types = config('smartstock.app.stock_out_types', []);
        return $types[$data['type']] ?? '未知';
    }

    /**
     * 状态文本
     */
    public function getStatusTextAttr($value, $data)
    {
        $statusMap = [0 => '草稿', 1 => '已确认', 2 => '已完成', 3 => '已取消'];
        return $statusMap[$data['status']] ?? '未知';
    }

    public function search($params = [])
    {
        $query = $this;
        if (!empty($params['order_no'])) {
            $query->whereLike('order_no', "%{$params['order_no']}%");
        }
        if (!empty($params['type'])) {
            $query->where('type', $params['type']);
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
