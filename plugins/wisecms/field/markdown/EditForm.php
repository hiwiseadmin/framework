<?php
namespace plugin\wisecms\field\markdown;

use plugin\wisecms\field\base\FieldBase;

class EditForm extends FieldBase
{
    /**
     * 编辑字段时的表单（编辑器高度回显）
     */
    public static function getEditForm(array $setting = []): string
    {
        $height = $setting['height'] ?? 400;

        $html = <<<HTML
<div class="mb-3">
    <label class="form-label">编辑器高度</label>
    <div class="input-group">
        <input type="number" name="setting[height]" value="{$height}" class="form-control" min="100">
        <span class="input-group-text">px</span>
    </div>
</div>
HTML;
        return $html;
    }
}
