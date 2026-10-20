<?php
namespace plugin\wisecms\field\editor;

use plugin\wisecms\field\base\FieldBase;

class EditForm extends FieldBase
{
    /**
     * 编辑字段时的表单
     */
    public static function getEditForm(array $setting = []): string
    {
        $toolbar = $setting['toolbar'] ?? 'full';
        $height = $setting['height'] ?? 300;
        $defaultValue = $setting['defaultvalue'] ?? '';
        $enableKeyLink = $setting['enablekeylink'] ?? 0;
        $replaceNum = $setting['replacenum'] ?? 1;
        $linkMode = $setting['link_mode'] ?? 1;
        $enableSaveImage = $setting['enablesaveimage'] ?? 0;
        $disabledPage = $setting['disabled_page'] ?? 0;

        // 预计算 checked 属性（heredoc 内不支持 {self::method()} 插值）
        $toolbarBasicChecked       = $toolbar === 'basic' ? 'checked' : '';
        $toolbarFullChecked        = $toolbar === 'full' ? 'checked' : '';
        $enableKeyLinkYesChecked   = $enableKeyLink == 1 ? 'checked' : '';
        $enableKeyLinkNoChecked    = $enableKeyLink == 0 ? 'checked' : '';
        $linkModeKeywordChecked    = $linkMode == 1 ? 'checked' : '';
        $linkModeUrlChecked        = $linkMode == 0 ? 'checked' : '';
        $enableSaveImageYesChecked = $enableSaveImage == 1 ? 'checked' : '';
        $enableSaveImageNoChecked  = $enableSaveImage == 0 ? 'checked' : '';
        $disabledPageYesChecked    = $disabledPage == 1 ? 'checked' : '';
        $disabledPageNoChecked     = $disabledPage == 0 ? 'checked' : '';

        $html = <<<HTML
<div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label">编辑器样式</label>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[toolbar]" value="basic" id="toolbar_basic" {$toolbarBasicChecked}>
            <label class="form-check-label" for="toolbar_basic">简洁型</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[toolbar]" value="full" id="toolbar_full" {$toolbarFullChecked}>
            <label class="form-check-label" for="toolbar_full">标准型</label>
        </div>
    </div>
    <div class="col-md-6">
        <label class="form-label">编辑器默认高度</label>
        <div class="input-group">
            <input type="number" name="setting[height]" value="{$height}" class="form-control">
            <span class="input-group-text">px</span>
        </div>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-12">
        <label class="form-label">默认值</label>
        <textarea name="setting[defaultvalue]" class="form-control" rows="3" placeholder="编辑器的默认内容">{$defaultValue}</textarea>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label">是否启用关联链接</label>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[enablekeylink]" value="1" id="enablekeylink_yes" {$enableKeyLinkYesChecked}>
            <label class="form-check-label" for="enablekeylink_yes">是</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[enablekeylink]" value="0" id="enablekeylink_no" {$enableKeyLinkNoChecked}>
            <label class="form-check-label" for="enablekeylink_no">否</label>
        </div>
    </div>
    <div class="col-md-6">
        <label class="form-label">替换次数</label>
        <input type="number" name="setting[replacenum]" value="{$replaceNum}" class="form-control" placeholder="留空则为替换全部">
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label">关联链接方式</label>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[link_mode]" value="1" id="link_mode_keyword" {$linkModeKeywordChecked}>
            <label class="form-check-label" for="link_mode_keyword">关键字链接</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[link_mode]" value="0" id="link_mode_url" {$linkModeUrlChecked}>
            <label class="form-check-label" for="link_mode_url">网址链接</label>
        </div>
    </div>
    <div class="col-md-6">
        <label class="form-label">是否保存远程图片</label>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[enablesaveimage]" value="1" id="enablesaveimage_yes" {$enableSaveImageYesChecked}>
            <label class="form-check-label" for="enablesaveimage_yes">是</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[enablesaveimage]" value="0" id="enablesaveimage_no" {$enableSaveImageNoChecked}>
            <label class="form-check-label" for="enablesaveimage_no">否</label>
        </div>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-12">
        <label class="form-label">禁止显示编辑器下方的分页符与子标题</label>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[disabled_page]" value="1" id="disabled_page_yes" {$disabledPageYesChecked}>
            <label class="form-check-label" for="disabled_page_yes">禁止</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="setting[disabled_page]" value="0" id="disabled_page_no" {$disabledPageNoChecked}>
            <label class="form-check-label" for="disabled_page_no">显示</label>
        </div>
    </div>
</div>
HTML;

        return $html;
    }
}
