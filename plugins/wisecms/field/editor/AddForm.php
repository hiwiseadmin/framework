<?php
namespace plugin\wisecms\field\editor;

use plugin\wisecms\field\base\FieldBase;

class AddForm extends FieldBase
{
    /**
     * 添加字段时的表单
     */
    public static function getAddForm(array $setting = []): string
    {
        $html = <<<HTML
<div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label">编辑器样式</label>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[toolbar]" value="basic" id="toolbar_basic" checked>
            <label class="form-check-label" for="toolbar_basic">简洁型</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[toolbar]" value="full" id="toolbar_full">
            <label class="form-check-label" for="toolbar_full">标准型</label>
        </div>
    </div>
    <div class="col-md-6">
        <label class="form-label">编辑器默认高度</label>
        <div class="input-group">
            <input type="number" name="setting[height]" value="300" class="form-control">
            <span class="input-group-text">px</span>
        </div>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-12">
        <label class="form-label">默认值</label>
        <textarea name="setting[defaultvalue]" class="form-control" rows="3" placeholder="编辑器的默认内容"></textarea>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label">是否启用关联链接</label>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[enablekeylink]" value="1" id="enablekeylink_yes">
            <label class="form-check-label" for="enablekeylink_yes">是</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[enablekeylink]" value="0" id="enablekeylink_no" checked>
            <label class="form-check-label" for="enablekeylink_no">否</label>
        </div>
    </div>
    <div class="col-md-6">
        <label class="form-label">替换次数</label>
        <input type="number" name="setting[replacenum]" value="1" class="form-control" placeholder="留空则为替换全部">
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label">关联链接方式</label>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[link_mode]" value="1" id="link_mode_keyword" checked>
            <label class="form-check-label" for="link_mode_keyword">关键字链接</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[link_mode]" value="0" id="link_mode_url">
            <label class="form-check-label" for="link_mode_url">网址链接</label>
        </div>
    </div>
    <div class="col-md-6">
        <label class="form-label">是否保存远程图片</label>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[enablesaveimage]" value="1" id="enablesaveimage_yes">
            <label class="form-check-label" for="enablesaveimage_yes">是</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[enablesaveimage]" value="0" id="enablesaveimage_no" checked>
            <label class="form-check-label" for="enablesaveimage_no">否</label>
        </div>
    </div>
</div>
HTML;

        return $html;
    }
}