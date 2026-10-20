<?php
namespace plugin\wisecms\field\textarea;

use plugin\wisecms\field\base\FieldBase;

class EditForm extends FieldBase
{
    /**
     * 编辑字段时的表单（文本域行数回显）
     */
    public static function getEditForm(array $setting = []): string
    {
        $rows = $setting['rows'] ?? 5;

        $html = <<<HTML
<div class="mb-3">
    <label class="form-label">显示行数</label>
    <input type="number" name="setting[rows]" value="{$rows}" class="form-control" min="1" max="50">
    <div class="form-text">文本域的行高（默认 5 行）。</div>
</div>
HTML;
        return $html;
    }
}
