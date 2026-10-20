<?php
namespace plugin\smartstock\model;

use plugin\BaseModel;

class ProductSpec extends BaseModel
{
    protected $name = 'smartstock_product_spec';

    /**
     * 关联规格值
     */
    public function specValues()
    {
        return $this->hasMany(ProductSpecValue::class, 'spec_id');
    }

    /**
     * 选项获取器：JSON 转数组
     */
    public function getOptionsArrAttr($value, $data)
    {
        if (!empty($data['options'])) {
            $decoded = json_decode($data['options'], true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * 选项修改器：数组转 JSON
     */
    public function setOptionsAttr($value)
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return $value;
    }

    /**
     * 状态获取器
     */
    public function getStatusTextAttr($value, $data)
    {
        $statusMap = [0 => '禁用', 1 => '启用'];
        return $statusMap[$data['status']] ?? '未知';
    }
}
