<?php
/**
 * wise-game-ops 默认配置
 *
 * 小游戏运营辅助能力：行为埋点分析、业务监控告警、活动系统
 */
return [
    // ──────────────────────────────────────────────
    // 通用
    // ──────────────────────────────────────────────
    'redis_connection' => 'default',

    // ──────────────────────────────────────────────
    // Analytics — 行为埋点分析
    // ──────────────────────────────────────────────
    'analytics' => [
        // 是否启用埋点异步队列写入（推荐启用）
        'use_queue'           => true,
        // 队列名称
        'queue_name'          => 'game_event',
        // 批量写入大小（达到该数量后一次性批量 INSERT）
        'batch_size'          => 100,
        // 批量写入超时（秒，超过该时间即使未达到 batch_size 也写入）
        'batch_timeout'       => 10,
        // 留存计算天数配置
        'retention_days'      => [1, 3, 7, 30],
        // 日报预计算 crontab 表达式（默认凌晨 2 点）
        'daily_report_cron'   => '0 2 * * *',
    ],

    // ──────────────────────────────────────────────
    // Monitor — 业务监控告警
    // ──────────────────────────────────────────────
    'monitor' => [
        // 是否启用监控
        'enabled'              => true,
        // Redis 实时计数 key 前缀
        'redis_key_prefix'     => 'game:monitor:',
        // 指标数据保留天数（超过自动清理）
        'retention_days'       => 90,
        // 告警检查间隔（crontab 表达式，默认每分钟）
        'alert_check_cron'     => '* * * * *',
        // 预设告警规则（可被数据库配置覆盖）
        'default_alert_rules'  => [
            [
                'metric_key'   => 'payment_success_rate',
                'operator'     => '<',
                'threshold'    => 95,
                'level'        => 'critical',
                'message'      => '支付成功率低于 95%，当前值: {value}%',
            ],
            [
                'metric_key'   => 'api_error_rate',
                'operator'     => '>',
                'threshold'    => 1,
                'level'        => 'warning',
                'message'      => 'API 错误率超过 1%，当前值: {value}%',
            ],
            [
                'metric_key'   => 'online_players',
                'operator'     => '<',
                'threshold'    => 10,
                'level'        => 'info',
                'message'      => '在线玩家数低于 10 人，当前值: {value}',
            ],
        ],
    ],

    // ──────────────────────────────────────────────
    // Activity — 活动系统
    // ──────────────────────────────────────────────
    'activity' => [
        // 是否启用发奖功能（依赖 wise-game-core Inventory）
        'reward_enabled'       => false,
        // 活动时间调度 crontab 表达式（默认每分钟检查）
        'schedule_cron'        => '* * * * *',
        // 签到
        'sign_in'              => [
            // 断签是否重置连续天数
            'reset_on_miss'    => true,
            // 连续签到奖励阶梯 [days => reward_config]
            'streak_rewards'   => [],
        ],
        // 抽奖
        'lottery'              => [
            // 保底次数（0 表示不启用保底）
            'pity_count'       => 50,
            // 保底状态 Redis key 前缀
            'pity_key_prefix'  => 'game:lottery:pity:',
            // 保底状态过期时间（秒，活动结束后清理）
            'pity_ttl'         => 86400,
        ],
        // 活动状态调度
        'scheduler'            => [
            // 调度锁 key 前缀
            'lock_key_prefix'  => 'game:activity:schedule:',
            // 调度锁超时（秒）
            'lock_ttl'         => 60,
        ],
    ],

    // ──────────────────────────────────────────────
    // 路由（兼容旧版，新代码请使用 web 节）
    // ──────────────────────────────────────────────
    'route' => [
        'api_prefix' => 'api/game',
    ],

    // ──────────────────────────────────────────────
    // Web 路由（配置驱动，由 RouteRegistrar 读取）
    // ──────────────────────────────────────────────
    'web' => [
        // 总开关：false 时本包不注册任何 HTTP 路由
        'enable' => true,
        // 默认路由前缀（分组级 prefix 可覆盖）
        'prefix' => 'api/game',
        // 全局默认中间件（所有分组生效）
        'middleware' => [],
        // 分组配置：key 对应 RouteRegistrar::define() 中的 group('key', ...)
        'groups' => [
            // 行为埋点（公开，建议按需挂限流）
            'analytics' => [
                'enable'     => true,
                'middleware' => [],
            ],
            // 业务监控（建议挂内部签名/限流中间件）
            'monitor' => [
                'enable'     => true,
                'middleware' => [],
            ],
            // 健康检查（公开）
            'health' => [
                'enable'     => true,
                'middleware' => [],
            ],
            // 活动系统（公开查询 + 用户操作需 JWT，按需配置）
            'activity' => [
                'enable'     => true,
                'middleware' => [],
            ],
        ],
    ],
];
