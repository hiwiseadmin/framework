<?php
namespace plugin\wisecms\field\editor;

use plugin\wisecms\field\base\FieldBase;

class Form extends FieldBase
{
    /**
     * 是否已注入 wiseeditor 资源（每个请求仅注入一次）
     *
     * @var bool
     */
    private static bool $assetsInjected = false;

    /**
     * 生成表单HTML
     */
    public function getFormHtml(array $fieldInfo, $value = ''): string
    {
        // UIUX-P0-31：setting 已由 FieldRegistry 归一化为数组，禁止直接 json_decode（PHP 8 抛 TypeError）
        $setting = self::normalizeSetting($fieldInfo['setting'] ?? []);
        
        // 解析设置
        $toolbar = $setting['toolbar'] ?? 'full';
        $height = $setting['height'] ?? 300;
        $defaultValue = $setting['defaultvalue'] ?? '';
        $enableKeyLink = $setting['enablekeylink'] ?? 0;
        $linkMode = $setting['link_mode'] ?? 1;
        $enableSaveImage = $setting['enablesaveimage'] ?? 0;
        $disabledPage = $setting['disabled_page'] ?? 0;
        
        // 如果未传入值，使用默认值
        if (empty($value) && !empty($defaultValue)) {
            $value = $defaultValue;
        }
        
        $fieldName = $fieldInfo['field'];
        $fieldId = 'editor_' . $fieldName;

        // 优先委托 wiseeditor（已安装并启用时）；未安装/异常则回退原有 CKEditor 实现。
        // 这是"检测到即启用、检测不到就退化"的向后兼容接入点。
        $delegated = $this->renderWithWiseEditor($fieldName, $value, (string) $toolbar, (int) $height);
        if ($delegated !== null) {
            return $delegated;
        }

        // 生成HTML
        $html = '<div id="' . $fieldId . '_tip"></div>';
        $html .= '<textarea name="' . $fieldName . '" id="' . $fieldId . '" style="height:' . $height . 'px;">' . 
                 htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</textarea>';
        
        // 生成JS初始化代码
        $html .= $this->getEditorScript($fieldId, $setting);
        
        return $html;
    }
    
    /**
     * 委托 wiseeditor 渲染富文本编辑器
     *
     * wiseeditor 插件未安装/未启用时返回 null，调用方回退原有实现。
     *
     * @return string|null
     */
    private function renderWithWiseEditor(string $fieldName, $value, string $toolbar, int $height): ?string
    {
        try {
            $manager = app('editor.manager');
            if (!$manager instanceof \app\common\library\EditorManager || !$manager->has('richtext')) {
                return null;
            }

            $html = $manager->render($fieldName, $value, [
                'type'    => 'richtext',
                'toolbar' => $toolbar === 'basic' ? 'basic' : 'full',
                'height'  => $height > 0 ? $height : 300,
            ]);

            return $html . $this->wiseEditorAssets();
        } catch (\Throwable $e) {
            // 编辑器插件异常不应阻断页面渲染
            return null;
        }
    }

    /**
     * 注入 wiseeditor 的 CSS / 入口 JS（每个请求仅注入一次）
     *
     * 后台 CSP 为 script-src 'self'，故只能引用同源外部模块，不能写内联脚本。
     */
    private function wiseEditorAssets(): string
    {
        if (self::$assetsInjected) {
            return '';
        }
        self::$assetsInjected = true;

        $base = '/statics/plugins/wiseeditor';

        return '<link rel="stylesheet" href="' . $base . '/css/wiseeditor.css">' . "\n"
            . '<script type="module" src="' . $base . '/js/wiseeditor-init.js"></script>' . "\n";
    }

    /**
     * 生成编辑器JS脚本
     */
    private function getEditorScript(string $fieldId, array $setting): string
    {
        $toolbar = $setting['toolbar'] ?? 'full';
        
        // 工具栏配置
        $toolbarConfig = $this->getToolbarConfig($toolbar);
        
        // 上传配置
        $uploadUrl = url('/cms/upload/editor')->build();
        $editorHeight = $setting['height'] ?? 300;
        
        $script = <<<JS
<script>
$(document).ready(function() {
    // 初始化富文本编辑器
    var editor = CKEDITOR.replace('{$fieldId}', {
        toolbar: {$toolbarConfig},
        height: {$editorHeight},
        filebrowserImageUploadUrl: '{$uploadUrl}',
        filebrowserUploadMethod: 'form',
        allowedContent: true,
        extraPlugins: 'uploadimage',
        uploadUrl: '{$uploadUrl}',
        language: 'zh-cn',
        removeButtons: ''
    });
    
    // 添加到全局编辑器数组，便于获取内容
    window.editors = window.editors || {};
    window.editors['{$fieldId}'] = editor;
});
</script>
JS;
        
        return $script;
    }
    
    /**
     * 获取工具栏配置
     */
    private function getToolbarConfig(string $toolbar): string
    {
        if ($toolbar === 'basic') {
            return json_encode([
                ['Bold', 'Italic', '-', 'NumberedList', 'BulletedList', '-', 'Link', 'Unlink'],
                ['Format', 'FontSize', 'TextColor', 'BGColor', '-', 'Source']
            ]);
        }
        
        // 标准工具栏
        return json_encode([
            ['Source', '-', 'Save', 'NewPage', 'Preview', 'Print', '-', 'Templates'],
            ['Cut', 'Copy', 'Paste', 'PasteText', 'PasteFromWord', '-', 'Undo', 'Redo'],
            ['Find', 'Replace', '-', 'SelectAll', '-', 'Scayt'],
            ['Form', 'Checkbox', 'Radio', 'TextField', 'Textarea', 'Select', 'Button', 'ImageButton', 'HiddenField'],
            '/',
            ['Bold', 'Italic', 'Underline', 'Strike', 'Subscript', 'Superscript', '-', 'RemoveFormat'],
            ['NumberedList', 'BulletedList', '-', 'Outdent', 'Indent', '-', 'Blockquote', 'CreateDiv', '-', 'JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock', '-', 'BidiLtr', 'BidiRtl', 'Language'],
            ['Link', 'Unlink', 'Anchor'],
            ['Image', 'Flash', 'Table', 'HorizontalRule', 'Smiley', 'SpecialChar', 'PageBreak', 'Iframe'],
            '/',
            ['Styles', 'Format', 'Font', 'FontSize'],
            ['TextColor', 'BGColor'],
            ['Maximize', 'ShowBlocks'],
            ['About']
        ]);
    }
    
    /**
     * 获取验证规则
     */
    public function getValidateRules(array $fieldInfo): array
    {
        $rules = parent::getValidateRules($fieldInfo);
        
        // 编辑器特殊验证：如果有内容则验证
        $fieldName = $fieldInfo['field'];
        $jsValidation = <<<JS
function(value, fieldName) {
    // 兼容两种实现：wiseeditor（隐藏域实时同步）与原有 CKEditor
    if (window.CKEDITOR && CKEDITOR.instances['editor_' + fieldName]) {
        value = CKEDITOR.instances['editor_' + fieldName].getData();
    } else if (window.WiseEditor && WiseEditor.get('editor_' + fieldName)) {
        value = WiseEditor.get('editor_' + fieldName).getContent();
    }
    if ($('#islink').is(':checked')) {
        return true;
    }

    // 去除HTML标签后的纯文本长度
    var text = String(value == null ? '' : value).replace(/<[^>]+>/g, '').trim();
    if (text.length === 0) {
        return '内容不能为空';
    }

    return true;
}
JS;
        
        // 添加自定义验证规则
        $rules[] = [
            'rule' => 'callback',
            'callback' => $jsValidation,
            'field' => $fieldName
        ];
        
        return $rules;
    }
}