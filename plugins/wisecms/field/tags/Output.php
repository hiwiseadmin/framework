<?php
namespace plugin\wisecms\field\tags;

use plugin\wisecms\field\base\FieldBase;

class Output extends FieldBase
{
    /**
     * 输出处理：逗号分隔字符串 → 数组（带安全转义）
     */
    public function outputFilter($value, array $fieldInfo = [])
    {
        if (empty($value)) {
            return [];
        }

        $tags = is_array($value) ? $value : explode(',', $value);
        $result = [];
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ($tag === '') continue;
            $result[] = [
                'name' => $tag,
                'safe_name' => htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'),
            ];
        }

        return $result;
    }
}
