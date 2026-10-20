<?php
declare(strict_types=1);

namespace plugin\wiseeditor\src;

use app\common\contract\EditorDriverInterface;

/**
 * TipTap 编辑器驱动
 *
 * 基于 TipTap 3.x（ProseMirror）提供富文本编辑与简易编辑能力。
 *
 * 引擎实现分两层，本类只负责「服务端契约」：
 *   - 引擎层：statics/js/vendor/tiptap.bundle.js（build/build-editor.mjs 打包，
 *     扩展集与命令表以它为准，本类不重复校验）
 *   - UI 层：statics/js/wiseeditor-tiptap.js（工具栏 / 上传 / 源码切换）
 */
class TipTapDriver implements EditorDriverInterface
{
    public function getTypes(): array
    {
        return ['richtext', 'simple'];
    }

    public function getName(): string
    {
        return 'TipTap';
    }

    public function getVersion(): string
    {
        // 与 src/package.json 的 @tiptap/* 锁定版本保持一致
        return '3.31.4';
    }

    public function renderHtml(string $name, $value, array $options = []): string
    {
        $mode   = $options['mode'] ?? $options['type'] ?? 'richtext';
        $id     = $options['id'] ?? ('editor_' . $name);
        $defaultHeight = function_exists('config')
            ? (int) config('wiseeditor.tiptap_default_height', 400)
            : 400;
        $height = ($options['height'] ?? $defaultHeight) . 'px';
        $class  = 'wiseeditor-container ' . ($options['class'] ?? '');

        $attrs = [
            'id'                => $id,
            'class'             => trim($class),
            'style'             => "height:{$height}",
            'data-editor'       => 'wiseeditor',
            'data-editor-type'  => 'tiptap',
            'data-mode'         => $mode,
            'data-name'         => $name,
            'data-upload-url'   => $options['upload_url'] ?? '/admin/attachment/upload',
        ];

        if (!empty($options['readonly'])) {
            $attrs['data-readonly'] = 'true';
        }

        if (!empty($options['placeholder'])) {
            $attrs['data-placeholder'] = $options['placeholder'];
        }

        if (!empty($options['attrs']) && is_array($options['attrs'])) {
            $attrs = array_merge($attrs, $options['attrs']);
        }

        $attrStr = '';
        foreach ($attrs as $k => $v) {
            $attrStr .= " {$k}=\"" . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
        }

        $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $html  = "<div{$attrStr}></div>\n";
        $html .= "<input type=\"hidden\" name=\"{$name}\" id=\"{$id}_input\" value=\"{$safeValue}\">";

        return $html;
    }

    public function getJsConfig(string $elementId, array $options = []): array
    {
        $mode = $options['mode'] ?? $options['type'] ?? 'richtext';

        return [
            'type'   => 'tiptap',
            'module' => 'plugins/wiseeditor/js/wiseeditor-tiptap.js',
            'config' => array_merge(
                $this->getDefaultConfig(),
                [
                    'element'     => "#{$elementId}",
                    'mode'        => $mode,
                    'placeholder' => $options['placeholder'] ?? '请输入内容...',
                    'readonly'    => $options['readonly'] ?? false,
                    'toolbar'     => $options['toolbar'] ?? ($mode === 'simple' ? 'basic' : 'full'),
                ]
            ),
        ];
    }

    public function getCssDependencies(string $mode = 'richtext'): array
    {
        return [
            'plugins/wiseeditor/css/wiseeditor.css',
        ];
    }

    public function getJsDependencies(string $mode = 'richtext'): array
    {
        // 页面只需引入单一入口；该入口内部 import 核心与适配器并自动初始化。
        // 适配器在运行时再动态加载 vendor/tiptap.bundle.js（相对自身路径），
        // 因此这里不需要、也不应该注入 bundle 本身。
        // 路径相对 /statics/，由消费方拼接为 /statics/plugins/wiseeditor/js/wiseeditor-init.js
        return [
            'plugins/wiseeditor/js/wiseeditor-init.js',
        ];
    }

    /**
     * 默认配置
     *
     * `extensions` / `toolbar_presets` 是「声明性」的，用于文档与第三方集成参考；
     * 真正的扩展集与命令表以 statics/js/vendor/tiptap.bundle.js 为准。
     */
    public function getDefaultConfig(): array
    {
        return [
            'default_mode'    => 'richtext',
            'toolbar_presets' => [
                // 与 statics/js/wiseeditor-tiptap.js 的 TOOLBAR_PRESETS.full 对齐
                'full'    => ['h2', 'h3', 'h4', 'paragraph', '|',
                              'bold', 'italic', 'underline', 'strike', '|',
                              'bulletList', 'orderedList', 'blockquote', 'codeBlock', '|',
                              'link', 'image', 'table', 'horizontalRule', 'clearFormat', '|',
                              'undo', 'redo', '|', 'source'],
                'basic'   => ['bold', 'italic', 'underline', '|',
                              'bulletList', 'orderedList', '|',
                              'link', 'image', '|',
                              'undo', 'redo'],
                'minimal' => ['bold', 'italic', 'link'],
            ],
            'upload' => [
                'max_size'   => 5 * 1024 * 1024,
                'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            ],
            'extensions' => [
                // StarterKit 3.x（内含，含 Link / Underline / 各类列表 / 历史）
                'document', 'paragraph', 'text',
                'heading', 'bold', 'italic', 'underline', 'strike',
                'bulletList', 'orderedList', 'listItem', 'listKeymap',
                'blockquote', 'codeBlock', 'code',
                'horizontalRule', 'hardBreak',
                'link', 'dropcursor', 'gapcursor', 'trailingNode', 'undoRedo',
                // 显式装配
                'image',
                'table', 'tableRow', 'tableCell', 'tableHeader',
                'placeholder', 'characterCount',
            ],
        ];
    }

    /**
     * HTML 白名单
     *
     * 白名单必须覆盖 bundle 实际输出，否则保存时会被 Request 的净化逻辑剥损。
     * TipTap 3.x 的表格会输出 `<table><colgroup><col style="min-width:..">`，
     * 对齐功能会写 `style="text-align:.."`，因此 colgroup/col 与 style 必须放行。
     *
     * 两种声明写法由 EditorManager 合并（见 buildWhitelistMap）：
     *   - allowed_tags      只需给标签名
     *   - allowed_attributes 按标签补属性
     */
    public function getHtmlWhitelist(): array
    {
        return [
            'allowed_tags' => [
                // 文本块与行内
                'p', 'br', 'hr',
                'strong', 'b', 'em', 'i', 'u', 's', 'del', 'code',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'ul', 'ol', 'li',
                'blockquote', 'pre',
                'span', 'div',
                // 链接与图片
                'a', 'img',
                // 表格（colgroup / col 为 TipTap 默认输出）
                'table', 'colgroup', 'col',
                'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
                // 媒体与折叠
                'figure', 'figcaption',
                'video', 'audio', 'source',
                'details', 'summary',
            ],
            'allowed_attributes' => [
                'div'      => ['class', 'style', 'data-type'],
                'span'     => ['class', 'style'],
                'p'        => ['class', 'style'],
                'h1'       => ['class', 'style'],
                'h2'       => ['class', 'style'],
                'h3'       => ['class', 'style'],
                'h4'       => ['class', 'style'],
                'h5'       => ['class', 'style'],
                'h6'       => ['class', 'style'],
                'ul'       => ['class', 'data-type'],
                'ol'       => ['class', 'data-type'],
                'li'       => ['class'],
                'pre'      => ['class'],
                'code'     => ['class'],
                'a'        => ['href', 'target', 'rel', 'title', 'class'],
                'img'      => ['src', 'alt', 'title', 'width', 'height', 'loading', 'style'],
                'table'    => ['class', 'style'],
                'colgroup' => ['span'],
                'col'      => ['span', 'width', 'style'],
                'tr'       => ['style'],
                'th'       => ['colspan', 'rowspan', 'style', 'class'],
                'td'       => ['colspan', 'rowspan', 'style', 'class'],
                'video'    => ['src', 'controls', 'width', 'height', 'poster', 'autoplay', 'loop', 'muted'],
                'audio'    => ['src', 'controls', 'autoplay', 'loop', 'muted'],
                'source'   => ['src', 'type'],
            ],
        ];
    }

    public function isMarkdownNative(): bool
    {
        return false;
    }
}
