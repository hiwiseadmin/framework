<?php
namespace plugin\wisecms\field\textarea;

use plugin\wisecms\field\base\FieldBase;

class Output extends FieldBase
{
    /**
     * 输出处理：换行符 → <br> 标签
     */
    public function outputFilter($value, array $fieldInfo = [])
    {
        if (empty($value)) {
            return '';
        }
        return nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
    }
}
