<?php

/**
 * WiseAdmin 二维码与条形码 — 默认配置
 *
 * 可通过宿主的 config/wise-qrcode.php 覆盖任意项。
 */
return [
    // -----------------------------------------------------------------
    //  QR 码默认参数
    // -----------------------------------------------------------------
    'qrcode' => [
        // 纠错级别：L(7%) / M(15%) / Q(25%) / H(30%)
        'ecc'               => 'M',

        // 输出格式：png / svg
        'output_format'     => 'png',

        // 图片尺寸（像素），设为 0 表示自动
        'size'              => 300,

        // 空白边距（模块数），最小 0
        'margin'            => 3,

        // 版本号 1-40，设为 0 表示自动
        'version'           => 0,

        // 前景色（HEX）
        'foreground'        => '#000000',

        // 背景色（HEX，支持透明 #FFFFFF00）
        'background'        => '#FFFFFF',

        // 中间 Logo 路径（null 表示不使用）
        'logo_path'         => null,

        // Logo 占 QR 码的比例（0.1 ~ 0.3）
        'logo_ratio'        => 0.2,

        // 底部文字（null 表示不显示）
        'label'             => null,

        // 文字字号
        'label_size'        => 12,

        // 文字颜色（HEX）
        'label_color'       => '#000000',

        // PNG 压缩质量（0-9）
        'png_quality'       => -1,

        // SVG 是否内联样式（否则用 presentation attributes）
        'svg_use_inline_css'=> false,
    ],

    // -----------------------------------------------------------------
    //  条形码默认参数
    // -----------------------------------------------------------------
    'barcode' => [
        // 默认条码类型
        'default_type'      => 'TYPE_CODE_128',

        // 输出格式：png / svg
        'output_format'     => 'png',

        // 条宽度（像素）
        'bar_width'         => 2,

        // 条码高度（像素）
        'bar_height'        => 60,

        // 前景色（HEX）
        'foreground'        => '#000000',

        // 背景色（HEX）
        'background'        => '#FFFFFF',

        // 是否显示底部文字
        'show_text'         => true,

        // 底部文字字号
        'font_size'         => 10,

        // 是否根据数据长度动态计算宽度
        'dynamic_width'     => true,
    ],

    // -----------------------------------------------------------------
    //  缓存配置
    // -----------------------------------------------------------------
    'cache' => [
        // 是否启用缓存（避免重复生成相同内容的码图）
        'enabled'   => false,

        // 缓存驱动：file / redis
        'driver'    => 'file',

        // 缓存有效期（秒），0 表示永久
        'ttl'       => 3600,

        // 缓存 key 前缀
        'prefix'    => 'wise_qrcode:',

        // file 驱动：缓存目录（相对于 runtime）
        'file_path' => 'qrcode',
    ],
];
