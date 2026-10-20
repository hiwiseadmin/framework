<?php
namespace plugin\wisecms\field\markdown;

use plugin\wisecms\field\base\FieldBase;

class AddForm extends FieldBase
{
    /**
     * 添加字段时的表单（编辑器高度）
     */
    public static function getAddForm(array $setting = []): string
    {
        $html = <<<HTML
<div class="mb-3">
    <label class="form-label">编辑器高度</label>
    <div class="input-group">
        <input type="number" name="setting[height]" value="400" class="form-control" min="100">
        <span class="input-group-text">px</span>
    </div>
</div>
HTML;
        return $html;
    }
}
