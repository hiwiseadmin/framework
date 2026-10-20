<?php
namespace plugin\wisecms\field\text;

use plugin\wisecms\field\base\FieldBase;

class Form extends FieldBase
{
    public function getFormHtml(array $fieldInfo, $value = ''): string
    {
        $required = $fieldInfo['is_required'] ? 'required' : '';
        $safeValue    = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $placeholder  = htmlspecialchars($fieldInfo['tips'] ?? "请输入{$fieldInfo['name']}", ENT_QUOTES, 'UTF-8');
        $maxlength    = $fieldInfo['length'] ? "maxlength=\"{$fieldInfo['length']}\"" : '';
        return "<input type=\"text\" name=\"{$fieldInfo['field']}\" value=\"{$safeValue}\" class=\"form-control\" {$required} {$maxlength} placeholder=\"{$placeholder}\">";
    }

    public function getValidateRules(array $fieldInfo): array
    {
        $rules = [];
        if ($fieldInfo['is_required']) $rules[] = 'require';
        if ($fieldInfo['length']) $rules[] = "max:{$fieldInfo['length']}";
        if ($fieldInfo['is_unique']) $rules[] = 'unique';
        return $rules;
    }
}
