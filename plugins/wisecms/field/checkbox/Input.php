<?php
namespace plugin\wisecms\field\checkbox;

use plugin\wisecms\field\base\FieldBase;

class Input extends FieldBase
{
    /**
     * 输入处理：POST 数组 → 逗号分隔字符串
     * 输入: ['a', 'b', 'c'] 或 "a,b,c" → 输出: "a,b,c"
     */
    public function inputFilter($value, array $fieldInfo = [])
    {
        if (is_array($value)) {
            return implode(',', array_filter($value, fn($v) => $v !== ''));
        }
        return (string)$value;
    }
}
