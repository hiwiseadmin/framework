<?php
namespace plugin\wisecms\field\file;

use plugin\wisecms\field\base\FieldBase;

class EditForm extends FieldBase
{
    /**
     * 编辑字段时的表单（允许的文件类型回显）
     */
    public static function getEditForm(array $setting = []): string
    {
        $allowedExt = htmlspecialchars((string) ($setting['allowed_ext'] ?? ''), ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<div class="mb-3">
    <label class="form-label">允许的文件类型</label>
    <input type="text" name="setting[allowed_ext]" value="{$allowedExt}" class="form-control" placeholder="例如：jpg,png,pdf,zip">
    <div class="form-text">逗号分隔的扩展名（不含点），留空则不限制。</div>
</div>
HTML;
        return $html;
    }
}
