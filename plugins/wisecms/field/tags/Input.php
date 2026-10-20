<?php
namespace plugin\wisecms\field\tags;

use plugin\wisecms\field\base\FieldBase;

class Input extends FieldBase
{
    /**
     * 输入处理：去空格、去重、去空，标准化逗号分隔
     * 输入: "标签1, 标签2, , 标签1, 标签3" → 输出: "标签1,标签2,标签3"
     */
    public function inputFilter($value, array $fieldInfo = [])
    {
        if (empty($value) || !is_string($value)) {
            return '';
        }

        $tags = explode(',', $value);
        $tags = array_map('trim', $tags);
        $tags = array_filter($tags, fn($t) => $t !== '');
        $tags = array_unique($tags);

        return implode(',', $tags);
    }
}
