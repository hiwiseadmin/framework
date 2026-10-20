<?php
namespace plugin\wisecms\field\number;

use plugin\wisecms\field\base\FieldBase;

class Input extends FieldBase
{
    /**
     * 输入处理：字符串 → 数值类型转换 + 边界裁剪
     */
    public function inputFilter($value, array $fieldInfo = [])
    {
        if ($value === '' || $value === null) {
            return 0;
        }

        $num = is_float($value + 0) ? floatval($value) : intval($value);

        // 边界裁剪
        $setting = $fieldInfo['setting'] ?? $this->fieldInfo['setting'] ?? [];
        if (isset($setting['min']) && $num < $setting['min']) {
            $num = $setting['min'];
        }
        if (isset($setting['max']) && $num > $setting['max']) {
            $num = $setting['max'];
        }

        return $num;
    }
}
