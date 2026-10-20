<?php
namespace plugin\wisecms\field\file;

use plugin\wisecms\field\base\FieldBase;

class AddForm extends FieldBase
{
    /**
     * 添加字段时的表单（允许的文件类型）
     */
    public static function getAddForm(array $setting = []): string
    {
        $html = <<<HTML
<div class="mb-3">
    <label class="form-label">允许的文件类型</label>
    <input type="text" name="setting[allowed_ext]" class="form-control" placeholder="例如：jpg,png,pdf,zip">
    <div class="form-text">逗号分隔的扩展名（不含点），留空则不限制。</div>
</div>
HTML;
        return $html;
    }
}
