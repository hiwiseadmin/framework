<?php
namespace plugin\wisecms\field\datetime;

use plugin\wisecms\field\base\FieldBase;

class Output extends FieldBase
{
    /**
     * 输出处理：Unix 时间戳 → 格式化日期字符串
     */
    public function outputFilter($value, array $fieldInfo = [])
    {
        if (empty($value)) {
            return '';
        }
        $ts = is_numeric($value) ? (int)$value : strtotime($value);
        return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '';
    }
}
