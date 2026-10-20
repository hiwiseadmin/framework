<?php
declare(strict_types=1);

namespace plugin\wiseeditor\src;

use app\common\contract\EditorDriverInterface;

/**
 * Milkdown Markdown 编辑器驱动
 *
 * 基于 Milkdown 7.x（ProseMirror），提供 WYSIWYG Markdown 编辑能力。
 * 输入和输出均为纯 Markdown 文本，不需要 HTMLPurifier。
 */
class MilkdownDriver implements EditorDriverInterface
{
    public function getTypes(): array
    {
        return ['markdown'];
    }

    public function getName(): string
    {
        return 'Milkdown';
    }

    public function getVersion(): string
    {
        return '7.7.0';
    }

    public function renderHtml(string $name, $value, array $options = []): string
    {
        $id     = $options['id'] ?? ('editor_' . $name);
        $defaultHeight = function_exists('config')
            ? (int) config('wiseeditor.milkdown_default_height', 500)
            : 500;
        $height = ($options['height'] ?? $defaultHeight) . 'px';
        $class  = 'wiseeditor-wrapper ' . ($options['class'] ?? '');

        $attrs = [
            'id'               => $id,
            'class'            => 'wiseeditor-container milkdown-editor',
            'style'            => "height:{$height}",
            'data-editor'      => 'wiseeditor',
            'data-editor-type' => 'milkdown',
            'data-mode'        => 'markdown',
            'data-name'        => $name,
        ];

        if (!empty($options['readonly'])) {
            $attrs['data-readonly'] = 'true';
        }

        if (!empty($options['placeholder'])) {
            $attrs['data-placeholder'] = $options['placeholder'];
        }

        $attrStr = '';
        foreach ($attrs as $k => $v) {
            $attrStr .= " {$k}=\"" . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
        }

        $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $html  = "<div class=\"" . trim($class) . "\">\n";
        $html .= "  <div{$attrStr}></div>\n";
        $html .= "  <textarea name=\"{$name}\" id=\"{$id}_textarea\" style=\"display:none;\">{$safeValue}</textarea>\n";
        $html .= "</div>";

        return $html;
    }

    public function getJsConfig(string $elementId, array $options = []): array
    {
        return [
            'type'   => 'milkdown',
            'module' => 'plugins/wiseeditor/js/wiseeditor-milkdown.js',
            'config' => array_merge(
                $this->getDefaultConfig(),
                [
                    'element'     => "#{$elementId}",
                    'textarea'    => "#{$elementId}_textarea",
                    'placeholder' => $options['placeholder'] ?? '开始用 Markdown 写作...',
                    'readonly'    => $options['readonly'] ?? false,
                ]
            ),
        ];
    }

    public function getCssDependencies(string $mode = 'markdown'): array
    {
        return [
            'plugins/wiseeditor/css/wiseeditor.css',
            'plugins/wiseeditor/css/milkdown-theme.css',
        ];
    }

    public function getJsDependencies(string $mode = 'markdown'): array
    {
        return [
            'plugins/wiseeditor/js/wiseeditor-init.js',
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'default_mode'   => 'markdown',
            'slash_menu'     => true,
            'math_support'   => true,
            'plugins'        => [
                'clipboard', 'history', 'cursor',
                'tooltip', 'slash', 'block',
                'emoji', 'prism', 'math',
            ],
        ];
    }

    public function getHtmlWhitelist(): array
    {
        // Markdown 编辑器输出纯文本，不需要 HTML 标签白名单
        // XSS 防护由 Markdown 解析器内置处理
        return [
            'allowed_tags'       => [],
            'allowed_attributes' => [],
        ];
    }

    public function isMarkdownNative(): bool
    {
        return true;
    }
}
