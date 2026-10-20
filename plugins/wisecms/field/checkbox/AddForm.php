<?php
namespace plugin\wisecms\field\checkbox;

use plugin\wisecms\field\base\FieldBase;

class AddForm extends FieldBase
{
    /**
     * 添加字段时的表单（选项编辑）
     */
    public static function getAddForm(array $setting = []): string
    {
        $html = <<<HTML
<div class="mb-3">
    <label class="form-label">选项列表</label>
    <textarea name="setting[options]" class="form-control" rows="6" placeholder="每行一个选项，格式：值|显示文本"></textarea>
    <div class="form-text">每行一个选项，用竖线「|」分隔值与显示文本；省略竖线时值与文本相同。</div>
</div>
HTML;
        return $html;
    }
}
