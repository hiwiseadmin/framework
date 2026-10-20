<?php
/**
 * 编辑器框架全局配置
 *
 * 访问方式：config('wise-editor.xxx')
 */
return [
    // 默认编辑器类型（当请求/表单未指定类型时使用）
    'default_editor' => 'richtext',

    // 编辑器驱动注册（兜底方案，优先级低于插件动态注册）
    'drivers' => [
        // 示例：
        // 'richtext' => 'plugin\\wiseeditor\\src\\TipTapDriver',
        // 'markdown' => 'plugin\\wiseeditor\\src\\MilkdownDriver',
    ],

    // 上传配置
    'upload' => [
        'default_url'        => '/admin/attachment/upload',
        'max_size'           => 5 * 1024 * 1024,  // 5MB
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
        'storage_driver'     => 'local',
    ],

    // 图片处理
    'image' => [
        'compress_enabled'  => true,
        'compress_quality'  => 85,
        'max_width'         => 1920,
        'thumbnail_enabled' => true,
        'thumbnail_size'    => [400, 300],
    ],

    // 内容安全
    'sanitize' => [
        'htmlpurifier_enabled' => true,
        'strip_unsafe_tags'    => true,
        'allowed_protocols'    => ['http', 'https', 'mailto', 'tel'],
    ],

    // 性能
    'performance' => [
        'lazy_load'     => true,   // 按需加载编辑器 JS
        'max_instances' => 10,     // 单页面最大编辑器实例数
    ],
];
