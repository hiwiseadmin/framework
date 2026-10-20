<?php
// demo 插件配置文件
// 加载后通过 config('demo.xxx') 访问

return [
    'info' => [
        'name'        => 'demo',
        'title'       => 'demo',
        'description' => '插件描述',
        'author'      => 'Unknown',
        'version'     => '1.0.0',
    ],

    // 插件设置
    'setting' => [
        'enable'    => true,
        'auto_load' => true,
    ],
];
