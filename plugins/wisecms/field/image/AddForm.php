<?php
namespace plugin\wisecms\field\image;

use plugin\wisecms\field\base\FieldBase;

class AddForm extends FieldBase
{
    /**
     * 添加字段时的表单（预览图高度）
     */
    public static function getAddForm(array $setting = []): string
    {
        $html = <<<HTML
<div class="mb-3">
    <label class="form-label">预览图最大高度</label>
    <div class="input-group">
        <input type="number" name="setting[preview_height]" value="150" class="form-control" min="20">
        <span class="input-group-text">px</span>
    </div>
    <div class="form-text">内容表单里已选图片预览缩略图的最大高度。</div>
</div>
HTML;
        return $html;
    }
}
