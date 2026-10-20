<?php
/**
 * wiseeditor 插件配置
 *
 * 访问方式：config('wiseeditor.xxx')
 * 框架全局配置在 app/config/wiseeditor.php，插件配置在此。
 */
return [
    'tiptap' => [
        'extensions' => [
            'heading',
            'bold', 'italic', 'underline', 'strike',
            'bulletList', 'orderedList', 'taskList',
            'blockquote', 'codeBlock', 'horizontalRule',
            'link', 'image',
            'table', 'tableRow', 'tableCell', 'tableHeader',
            'history',
            'placeholder',
            'characterCount',
        ],
        'toolbar' => [
            'full'    => ['heading', 'bold', 'italic', 'underline', 'strike', '|',
                          'bulletList', 'orderedList', 'blockquote', 'codeBlock', '|',
                          'link', 'image', 'video', 'table', '|',
                          'undo', 'redo', '|', 'source'],
            'basic'   => ['bold', 'italic', 'underline', '|', 'link', 'image', '|', 'undo', 'redo'],
            'minimal' => ['bold', 'italic', 'link'],
        ],
    ],

    'milkdown' => [
        'plugins'      => ['clipboard', 'history', 'cursor', 'tooltip', 'slash', 'block', 'emoji', 'prism'],
        'slash_menu'   => true,
        'math_support' => true,
    ],

    'tiptap_default_height'   => 400,
    'milkdown_default_height' => 500,
];
