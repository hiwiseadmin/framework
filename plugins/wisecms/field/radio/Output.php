<?php
namespace plugin\wisecms\field\radio;

use plugin\wisecms\field\base\FieldBase;

class Output extends FieldBase
{
    /**
     * 输出处理：key → {key, label} 映射
     */
    public function outputFilter($value, array $fieldInfo = [])
    {
        $opts = $fieldInfo['setting']['options'] ?? $this->fieldInfo['setting']['options'] ?? [];

        return [
            'key'   => $value,
            'label' => $opts[$value] ?? $value,
        ];
    }
}
