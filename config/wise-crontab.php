<?php

return [
    /**
     * 配置文件定义的任务
     *
     * 每个任务支持以下字段（以「清理临时文件」为例）：
     *
     * [
     *     // 语义化调度（推荐，中英双语）：优先于 cron_expression
     *     'schedule'         => '每天凌晨2点',        // 或 'daily at 02:30'、'每5分钟'、'每月最后一个周五 09:00'
     *     // 原生 5 段 cron（向后兼容，schedule 为空时生效）
     *     'cron_expression'  => '0 2 * * *',
     *     'name'             => '清理临时文件',        // 必填，唯一
     *     'command'          => 'php think clean:temp', // 必填，需命中 allowed_command_patterns
     *     'allow_concurrent' => false,                 // 是否允许并发执行
     *     'max_retries'      => 1,                     // 失败重试次数
     *     'timeout'          => 600,                   // 超时（秒）
     *     'enabled'          => true,                  // false 等价于 status=0
     *     'status'           => 1,                     // 0 禁用 / 1 启用
     * ],
     */
    'tasks' => [
        // 清理过期的通知发送历史（保留天数取 wise-notification.history_retention_days）
        [
            'schedule'         => 'daily at 03:30',
            'cron_expression'  => '30 3 * * *',
            'name'             => '清理通知发送历史',
            'command'          => 'php think wise:notification:clean-history',
            'allow_concurrent' => false,
            'max_retries'      => 0,
            'timeout'          => 600,
            'enabled'          => true,
        ],
    ],

    // 命令白名单（正则）：只有命中的命令才会被执行，防止命令注入
    'allowed_command_patterns' => ['/^php\s+/'],

    // 锁配置
    'lock_driver'        => 'file',              // file | redis | database
    'lock_dir'           => '',                  // 文件锁目录（空=系统临时目录）
    'lock_ttl'           => 3600,                // 锁过期时间（秒）
    'lock_redis_store'   => 'redis',             // Redis 驱动使用的 cache store（必须是 cache.php 中的 redis 通道）
    'lock_db_table'      => 'crontab_lock',      // DB 驱动使用的锁表名

    // 默认值
    'default_timeout'     => 300,
    'default_max_retries' => 0,
    'output_max_bytes'    => 65536,

    // 日志
    'log_retention_days' => 30,
    'auto_clean_logs'    => false,               // 是否在每次 dispatch 后自动清理

    // 并行执行（O-3）
    'parallel' => [
        'enabled'        => false,
        'max_concurrent' => 5,
        'wait_all'       => true,
        'wait_timeout'   => 600,
    ],

    // 全局并发限制（E-6）
    'concurrency' => [
        'max_global' => 0,       // 0=不限制
    ],

    // 告警配置（E-2）
    'alert' => [
        'enabled'    => false,
        'on_failure' => true,
        'on_timeout' => true,
        'channels'   => ['email'],
        'throttle'   => 300,     // 同一任务告警间隔（秒）
        'recipients' => [],
    ],
];
