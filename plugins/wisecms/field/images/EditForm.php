<?php
namespace plugin\wisecms\field\images;

use plugin\wisecms\field\base\FieldBase;

class EditForm extends FieldBase
{
    /**
     * 编辑字段时的表单（缩略图尺寸回显）
     */
    public static function getEditForm(array $setting = []): string
    {
        $thumbSize = $setting['thumb_size'] ?? 80;

        $html = <<<HTML
<div class="mb-3">
    <label class="form-label">缩略图尺寸</label>
    <div class="input-group">
        <input type="number" name="setting[thumb_size]" value="{$thumbSize}" class="form-control" min="20">
        <span class="input-group-text">px</span>
    </div>
    <div class="form-text">已上传图片缩略图的边长（正方形）。</div>
</div>
HTML;
        return $html;
    }
}
