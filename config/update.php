<?php
return [
    // 升级服务端配置
    'server' => [
        'url' => env('UPDATE_SERVER_URL', 'https://update.example.com'), // 升级服务端地址
        'api' => [
            'latest' => '/api/latest',       // 获取最新版本信息接口
            'download' => '/api/download',   // 升级包下载接口（可能包含在latest返回的url中）
        ],
        'timeout' => 30,                     // HTTP请求超时时间(秒)
    ],

    // 本地存储路径
    'path' => [
        'temp' => runtime_path() . 'update/',   // 临时目录，存放下载的升级包
        'backup' => root_path() . 'backup/',    // 备份目录
    ],

    // 安全相关
    'security' => [
        'public_key' => env('UPDATE_PUBLIC_KEY', ''),   // RSA公钥，用于验证升级包签名
        'verify_signature' => true,              // 是否强制验证签名
    ],

    // 备份配置
    'backup' => [
        'enabled' => true,                       // 是否在升级前自动备份
        'exclude_dirs' => [
            'runtime',
            'backup',
            '.git',
            'vendor',                           // 通常vendor不备份，通过composer管理
        ],
        'max_backups' => 5,                      // 最多保留备份文件数量
    ],

    // 升级选项
    'options' => [
        'clean_cache' => true,                   // 升级后是否清理缓存
        'set_maintenance_mode' => true,          // 升级前是否开启维护模式（生成maintenance.lock）
    ],

    // 当前系统版本（可从数据库或配置文件读取）
    'current_version' => env('APP_VERSION', '1.0.0'),
    'notification' => [
        'channels' => ['mail', 'dingtalk'], // 启用渠道
        'mail' => [
            'to' => 'admin@example.com',
        ],
        'dingtalk' => [
            'webhook' => 'https://oapi.dingtalk.com/robot/send?access_token=xxx',
        ],
        'wechat' => [
            'webhook' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=xxx',
        ],
    ],
    // 多服务器同步配置
    'multi_server' => [
        'enabled' => env('UPDATE_MULTI_SERVER', false), // 是否启用多服务器同步
        'type' => 'rsync', // 同步类型：rsync 或 custom
        'servers' => [
            [
                'host' => '192.168.1.10',
                'port' => 22,
                'user' => 'www',
                'path' => '/var/www/html',
                'key' => '/home/www/.ssh/id_rsa', // 私钥路径
            ],
            // 可添加更多服务器
        ],
        'exclude' => [
            'runtime',
            'backup',
            '.git',
            'vendor',
        ],
        'timeout' => 300, // 同步超时时间（秒）
    ],
];