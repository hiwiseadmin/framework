<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class AlertRule extends BaseModel
{
    protected $name = 'smartstock_alert_rule';

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function alertLogs()
    {
        return $this->hasMany(AlertLog::class, 'rule_id');
    }

    public function getStatusTextAttr($value, $data)
    {
        $statusMap = [0 => '禁用', 1 => '启用'];
        return $statusMap[$data['status']] ?? '未知';
    }

    /**
     * 获取告警类型文本
     */
    public function getAlertTypeTextAttr($value, $data)
    {
        $typeMap = [1 => '低库存预警', 2 => '超库存预警', 3 => '保质期预警'];
        return $typeMap[$data['alert_type']] ?? '未知';
    }
}
