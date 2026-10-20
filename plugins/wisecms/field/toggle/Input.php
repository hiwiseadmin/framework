<?php
namespace plugin\wisecms\field\toggle;

use plugin\wisecms\field\base\FieldBase;

class Input extends FieldBase
{
    /**
     * 输入处理：turn off 时 $_POST 中不存在 → 统一转为 0/1
     */
    public function inputFilter($value, array $fieldInfo = [])
    {
        return $value ? 1 : 0;
    }
}
