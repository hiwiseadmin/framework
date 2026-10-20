<?php
namespace plugin\wisecms\field\checkbox;

use plugin\wisecms\field\base\FieldBase;

class EditForm extends FieldBase
{
    /**
     * 编辑字段时的表单（选项回显）
     */
    public static function getEditForm(array $setting = []): string
    {
        $optionsText = htmlspecialchars(
            self::optionsToText(self::normalizeOptions($setting['options'] ?? [])),
            ENT_QUOTES,
            'UTF-8'
        );

        $html = <<<HTML
<div class="mb-3">
    <label class="form-label">选项列表</label>
    <textarea name="setting[options]" class="form-control" rows="6" placeholder="每行一个选项，格式：值|显示文本">{$optionsText}</textarea>
    <div class="form-text">每行一个选项，用竖线「|」分隔值与显示文本；省略竖线时值与文本相同。</div>
</div>
HTML;
        return $html;
    }
}
