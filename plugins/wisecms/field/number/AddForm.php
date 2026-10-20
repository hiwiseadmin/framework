<?php
namespace plugin\wisecms\field\number;

use plugin\wisecms\field\base\FieldBase;

class AddForm extends FieldBase
{
    /**
     * 添加字段时的表单（数值边界）
     */
    public static function getAddForm(array $setting = []): string
    {
        $html = <<<HTML
<div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label">最小值</label>
        <input type="number" name="setting[min]" class="form-control" placeholder="留空不限">
    </div>
    <div class="col-md-6">
        <label class="form-label">最大值</label>
        <input type="number" name="setting[max]" class="form-control" placeholder="留空不限">
    </div>
</div>
<div class="form-text mb-3">提交时超出边界的值会被裁剪到 min/max 范围内。</div>
HTML;
        return $html;
    }
}
