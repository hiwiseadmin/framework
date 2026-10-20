<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class Transfer extends BaseModel
{
    protected $name = 'smartstock_transfer';

    /**
     * 关联调拨明细
     */
    public function items()
    {
        return $this->hasMany(TransferItem::class, 'transfer_id');
    }

    /**
     * 关联调出仓库
     */
    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /**
     * 关联调入仓库
     */
    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    /**
     * 状态文本
     */
    public function getStatusTextAttr($value, $data)
    {
        $statusMap = [0 => '草稿', 1 => '已审核', 2 => '调拨中', 3 => '已完成', 4 => '已取消'];
        return $statusMap[$data['status']] ?? '未知';
    }

    public function search($params = [])
    {
        $query = $this;
        if (!empty($params['order_no'])) {
            $query->whereLike('order_no', "%{$params['order_no']}%");
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }
        return $query->order('id', 'desc');
    }
}
