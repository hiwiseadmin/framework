<?php
namespace plugin\wisecms\field\image;

use plugin\wisecms\field\base\FieldBase;

class EditForm extends FieldBase
{
    /**
     * 编辑字段时的表单（预览图高度回显）
     */
    public static function getEditForm(array $setting = []): string
    {
        $previewHeight = $setting['preview_height'] ?? 150;

        $html = <<<HTML
<div class="mb-3">
    <label class="form-label">预览图最大高度</label>
    <div class="input-group">
        <input type="number" name="setting[preview_height]" value="{$previewHeight}" class="form-control" min="20">
        <span class="input-group-text">px</span>
    </div>
    <div class="form-text">内容表单里已选图片预览缩略图的最大高度。</div>
</div>
HTML;
        return $html;
    }
}
