<?php

/**
 * WiseAdmin 游戏任务与成就系统 — 默认配置
 *
 * 可通过宿主的 config/wise-game-task.php 覆盖任意项。
 */
return [
    // ================================================================
    //  任务 (Task) 配置
    // ================================================================

    'task' => [
        /**
         * 每日任务自动重置
         *
         * 为 true 时，任务进度在跨周期（如跨天）后自动重置，
         * 依赖 resetPeriodic() 或定时任务驱动。
         */
        'auto_reset_daily' => true,

        /**
         * 每日重置时间点（0~23 小时）
         */
        'daily_reset_hour' => 0,

        /**
         * 周期任务类别集合
         *
         * 这些类别的任务进度在跨周期（如跨天）后首次上报时会被自动重置，
         * 与 auto_reset_daily 配合生效。主线任务（main）不在此列。
         */
        'periodic_categories' => ['daily', 'weekly'],

        /**
         * 周期粒度映射：类别 => 周期标识粒度
         *
         * day  → Y-m-d 自然日（受 daily_reset_hour 运营日偏移影响）
         * week → ISO 周（Y-\WW，如 2026-W34），周常任务跨周才重置
         *
         * 修复：原实现对所有类别一律用天粒度，导致周常任务每天被重置。
         */
        'period_granularity' => [
            'daily'  => 'day',
            'weekly' => 'week',
        ],
    ],

    // ================================================================
    //  成就 (Achievement) 配置
    // ================================================================

    'achievement' => [
        /**
         * 达标自动解锁并发奖
         *
         * 成就为一次性里程碑，进度达标即自动解锁发奖，
         * 区别于任务的"手动领取"。
         */
        'auto_unlock' => true,
    ],

    // ================================================================
    //  事件上报 (Event)
    // ================================================================

    'event' => [
        /**
         * 单次事件上报允许的最大进度增量
         *
         * 服务端权威上限：防止客户端通过 data.count 一次性刷满任务/成就进度。
         * 批量上报应由服务端内部调用 TaskService/AchievementService::onEvent()
         * 并在此配置允许的范围内传 count；HTTP 上报端点强制单次增量 1。
         */
        'max_increment' => 1000,

        /**
         * 事件幂等去重 TTL（秒）
         *
         * 客户端上报带 event_id 时，同一事件在 TTL 内只会被累计一次，
         * 用于弱网下客户端重试导致进度重复累计的防重。Redis 不可用时
         * 自动 fail-open（不做去重，不阻塞上报）。
         */
        'idempotent_ttl' => 86400,

        /**
         * 事件上报内部调用密钥（服务端权威上报）
         *
         * 用于校验 POST /api/game/task/event 的 X-Internal-Key 请求头。
         * 默认空串表示"未启用"——事件端点拒绝一切直接调用，事件只能由
         * 游戏服务端内部调用 TaskService::onEvent / AchievementService::onEvent 上报。
         * 如需开放该端点，请在此配置强随机密钥，并确保仅内网可达。
         */
        'internal_key' => '',
    ],

    // ================================================================
    //  基础设施
    // ================================================================

    /**
     * Redis 连接名（用于进度缓存与分布式锁）
     */
    'redis_connection' => 'default',

    /**
     * 默认分页大小（getList 的 page_size 缺省值）
     */
    'log_page_size' => 20,

    /**
     * 分布式锁配置
     *
     * 用于串行化并发领取/成就解锁（防重复发奖）。
     * - driver: redis（SET NX EX，多机首选）| file（单机 flock）| database
     * - redis 驱动获取失败时自动降级 file 锁，避免锁不可用导致功能静默失效
     * - blocking=true 时 acquire 会等待至多 blocking_timeout 秒
     */
    'lock' => [
        'driver'           => 'redis',
        'ttl'              => 10,
        'blocking'         => true,
        'blocking_timeout' => 5,
    ],

    /**
     * 任务进度缓存配置
     *
     * 单任务进度查询（getProgress）优先读缓存，miss 时查库并回填；
     * 写点（事件上报/领取/重置）自动失效对应缓存。
     * 缓存不可用（如 Redis 故障）时自动 fail-open 直查数据库。
     */
    'cache' => [
        'enabled' => true,
        'ttl'     => 300,
    ],

    // ================================================================
    //  wise-crontab 接入
    // ================================================================

    /**
     * 周期重置任务的 wise-crontab 注册定义
     *
     * 通过 `php think wise:game-task:reset --register` 写入 crontab_task 表
     * （DB 任务优先级高于配置文件任务），cron 表达式由 task.daily_reset_hour
     * 自动生成（如 daily_reset_hour=0 → "0 0 * * *"），无需在此配置。
     * 系统侧仅需保证调度命令每分钟触发：php think wise:crontab:run。
     */
    'crontab' => [
        'task_name'        => 'wise:game-task:reset',
        'title'            => '游戏任务周期重置',
        'description'      => '重置每日/周常任务进度',
        'command'          => 'php think wise:game-task:reset',
        'timeout'          => 300,
        'max_retries'      => 0,
        'allow_concurrent' => false,
    ],

    // ================================================================
    //  路由前缀（兼容旧版，新代码请使用 web 节）
    // ================================================================

    'route' => [
        'api_prefix' => 'api/game',
    ],

    // ================================================================
    //  Web 路由（配置驱动，由 RouteRegistrar 读取）
    // ================================================================

    'web' => [
        // 总开关：false 时本包不注册任何 HTTP 路由
        'enable' => true,
        // 默认路由前缀（分组级 prefix 可覆盖）
        'prefix' => 'api/game',
        // 全局默认中间件（所有分组生效）
        'middleware' => [],
        // 分组配置：key 对应 RouteRegistrar::define() 中的 group('key', ...)
        'groups' => [
            // 任务与成就（需 JWT）
            'task' => [
                'enable'     => true,
                'middleware' => [
                    [\wise\jwt\middleware\JwtAuth::class, ['app', 'bearer']],
                ],
            ],
        ],
    ],
];
