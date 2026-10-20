<?php
namespace plugin\wisecms\field\images;

use plugin\wisecms\field\base\FieldBase;

class Output extends FieldBase
{
    /**
     * 输出处理：逗号分隔字符串 → 数组 + URL 补全为绝对路径
     */
    public function outputFilter($value, array $fieldInfo = [])
    {
        if (empty($value)) {
            return [];
        }

        $urls = is_array($value) ? $value : explode(',', $value);
        $baseUrl = request()->domain();

        $result = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '') continue;
            // 相对路径补全
            if (!preg_match('/^(http|https):\/\//i', $url)) {
                $url = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
            }
            $result[] = $url;
        }

        return $result;
    }
}
