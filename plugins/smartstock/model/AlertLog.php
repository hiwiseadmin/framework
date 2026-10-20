<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class AlertLog extends BaseModel
{
    protected $name = 'smartstock_alert_log';

    // 时间戳
    protected $createTime = 'create_time';
    protected $updateTime = false;
    protected $deleteTime = false;

    public function rule()
    {
        return $this->belongsTo(AlertRule::class, 'rule_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function getIsReadTextAttr($value, $data)
    {
        return ($data['is_read'] ?? 0) ? '已读' : '未读';
    }

    public function getAlertTypeTextAttr($value, $data)
    {
        $typeMap = [1 => '低库存预警', 2 => '超库存预警', 3 => '保质期预警'];
        return $typeMap[$data['alert_type']] ?? '未知';
    }

    /**
     * 标记为已读
     */
    public function markAsRead(): bool
    {
        $this->is_read = 1;
        return $this->save();
    }
}
