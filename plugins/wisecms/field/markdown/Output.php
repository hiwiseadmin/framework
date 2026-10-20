<?php
namespace plugin\wisecms\field\markdown;

use plugin\wisecms\field\base\FieldBase;

class Output extends FieldBase
{
    /**
     * 输出处理：Markdown 文本 → HTML 渲染
     */
    public function outputFilter($value, array $fieldInfo = [])
    {
        if (empty($value)) {
            return '';
        }

        // 使用 Parsedown 渲染（如未安装则保留原文）
        if (class_exists(\Parsedown::class)) {
            $parsedown = new \Parsedown();
            $parsedown->setSafeMode(true); // 过滤 XSS
            return $parsedown->text($value);
        }

        // 降级：简易 Markdown 转义
        return '<pre>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
}
