<?php

declare(strict_types=1);

/**
 * 插件管理器配置
 *
 * 此文件包含 Wise 插件管理器的默认配置。
 * 您可以在应用的 config/plugin.php 文件中覆盖这些设置。
 */
return [
    // 插件存储目录（相对路径依赖 vendor/wise/plugin/config/ 的目录层级，应用层可在 config/plugin.php 中覆盖为 root_path('plugins')）
    'plugin_dir' => root_path('plugins'),

    // 配置存储驱动（'database' 或 'file'）
    'config_driver' => 'database',

    // 基于文件存储的配置目录（仅当 config_driver 为 'file' 时使用）
    'config_dir' => root_path('plugin_config'),

    // 是否启用插件扫描缓存
    'cache_enabled' => true,

    // 插件扫描结果缓存 TTL（秒）
    'cache_ttl' => 3600,

    // 应用市场驱动（'local' 或 'http'）
    'market_driver' => 'local',

    // 应用市场驱动配置
    'market_config' => [
        // HTTP 市场驱动的 API URL
        'api_url' => '',

        // HTTP 市场认证的 API 密钥
        'api_key' => '',
    ],
];
