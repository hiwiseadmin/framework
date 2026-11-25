<?php

// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

return [
    // 默认缓存驱动
    'default' => 'file',

    // 缓存连接方式配置
    'stores'  => [
        'file' => [
            // 驱动方式
            'type'       => 'File',
            // 缓存保存目录
            'path'       => '',
            // 缓存前缀
            'prefix'     => '',
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        'redis' => [
            'type'       => 'redis',
            'host'       => '127.0.0.1',
            'port'       => 6379,
            'password'   => '',
            'select'     => 0,          // 数据库编号
            'timeout'    => 3.0,        // 连接超时（秒）
            'persistent' => false,      // 是否长连接
            'prefix'     => '',         // 全局前缀（框架会在内部拼接，直接写死即可）
        ],
        'shared_file' => [
            // 驱动方式
            'type'       => 'File',
            // 缓存保存目录
            'path'       => runtime_path() . 'cache/',
            // 缓存前缀
            'prefix'     => 'shared',
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        // 更多的缓存连接
    ],
];
