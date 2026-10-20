<?php
namespace plugin\wisecms\field\checkbox;

use plugin\wisecms\field\base\FieldBase;

class Output extends FieldBase
{
    /**
     * 输出处理：逗号分隔字符串 → 数组，并按需映射 key → label
     */
    public function outputFilter($value, array $fieldInfo = [])
    {
        if (empty($value)) {
            return [];
        }

        $keys  = is_array($value) ? $value : explode(',', $value);
        $opts  = $fieldInfo['setting']['options'] ?? $this->fieldInfo['setting']['options'] ?? [];

        $result = [];
        foreach ($keys as $k) {
            $k = trim($k);
            if ($k === '') continue;
            $result[] = [
                'key'   => $k,
                'label' => $opts[$k] ?? $k,
            ];
        }

        return $result;
    }
}
