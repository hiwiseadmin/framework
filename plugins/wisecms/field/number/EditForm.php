<?php
namespace plugin\wisecms\field\number;

use plugin\wisecms\field\base\FieldBase;

class EditForm extends FieldBase
{
    /**
     * 编辑字段时的表单（数值边界回显）
     */
    public static function getEditForm(array $setting = []): string
    {
        $min = $setting['min'] ?? '';
        $max = $setting['max'] ?? '';

        $html = <<<HTML
<div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label">最小值</label>
        <input type="number" name="setting[min]" value="{$min}" class="form-control" placeholder="留空不限">
    </div>
    <div class="col-md-6">
        <label class="form-label">最大值</label>
        <input type="number" name="setting[max]" value="{$max}" class="form-control" placeholder="留空不限">
    </div>
</div>
<div class="form-text mb-3">提交时超出边界的值会被裁剪到 min/max 范围内。</div>
HTML;
        return $html;
    }
}
