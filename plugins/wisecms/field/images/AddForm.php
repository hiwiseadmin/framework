<?php
namespace plugin\wisecms\field\images;

use plugin\wisecms\field\base\FieldBase;

class AddForm extends FieldBase
{
    /**
     * 添加字段时的表单（缩略图尺寸）
     */
    public static function getAddForm(array $setting = []): string
    {
        $html = <<<HTML
<div class="mb-3">
    <label class="form-label">缩略图尺寸</label>
    <div class="input-group">
        <input type="number" name="setting[thumb_size]" value="80" class="form-control" min="20">
        <span class="input-group-text">px</span>
    </div>
    <div class="form-text">已上传图片缩略图的边长（正方形）。</div>
</div>
HTML;
        return $html;
    }
}
