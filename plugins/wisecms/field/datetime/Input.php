<?php
namespace plugin\wisecms\field\datetime;

use plugin\wisecms\field\base\FieldBase;

class Input extends FieldBase
{
    /**
     * 输入处理：浏览器 datetime-local 格式 → Unix 时间戳
     * 输入格式: "2024-01-15T14:30" → 输出: 1705325400
     */
    public function inputFilter($value, array $fieldInfo = [])
    {
        if (empty($value)) {
            return 0;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        $ts = strtotime($value);
        return $ts !== false ? $ts : 0;
    }
}
