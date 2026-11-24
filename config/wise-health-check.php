<?php

/**
 * WiseHealthCheck 配置文件
 *
 * 通过 config('wise-health-check.xxx') 读取配置项
 */
return [
    // ------------------------------------------------------------
    // 包信息
    // ------------------------------------------------------------

    /**
     * 包版本（用于前端静态资源缓存失效）
     */
    'version' => '1.0.0',

    // ------------------------------------------------------------
    // 检测器配置
    // ------------------------------------------------------------

    /**
     * 检测器列表（按顺序执行）
     * 每个元素为 CheckerInterface 实现类的完全限定名
     */
    'checker' => [
        \wise\health\checker\PhpVersionChecker::class,
        \wise\health\checker\ExtensionChecker::class,
        \wise\health\checker\DirectoryPermissionChecker::class,
        \wise\health\checker\EnvFileChecker::class,
        \wise\health\checker\DatabaseConnectionChecker::class,
        \wise\health\checker\RedisConnectionChecker::class,
        \wise\health\checker\DiskSpaceChecker::class,
    ],

    /**
     * 生产环境额外检测项
     * 在 production 环境下自动追加到检测列表末尾
     */
    'production_extra_checks' => [
        \wise\health\checker\AppDebugChecker::class,
        \wise\health\checker\XdebugChecker::class,
        \wise\health\checker\CacheConfigChecker::class,
    ],

    // ------------------------------------------------------------
    // 阈值配置
    // ------------------------------------------------------------

    'thresholds' => [
        // 磁盘剩余空间低于此百分比告警
        'disk_free_percent' => 20,
        // 磁盘剩余空间低于此 GB 数告警
        'disk_free_gb'      => 5,
        // PHP 最低版本要求
        'php_min_version'   => '8.0.0',
    ],

    // ------------------------------------------------------------
    // 目录权限检测配置
    // ------------------------------------------------------------

    /**
     * 需要检测读写权限的目录列表
     * 路径相对于项目根目录 (root_path)
     */
    'check_directories' => [
        'runtime',
        'public',
        'public/uploads',
        'public/statics',
    ],

    // ------------------------------------------------------------
    // 数据库检测配置
    // ------------------------------------------------------------

    /**
     * 数据库连接名（ThinkPHP 配置中的 connections key）
     */
    'db_connection' => env('ENV_CHECK_DB_CONNECTION', 'mysql'),

    // ------------------------------------------------------------
    // Redis 检测配置
    // ------------------------------------------------------------

    /**
     * Redis 连接名
     */
    'redis_connection' => env('ENV_CHECK_REDIS_CONNECTION', 'default'),

    // ------------------------------------------------------------
    // 告警配置
    // ------------------------------------------------------------

    'alert' => [
        // 是否启用告警
        'enabled' => env('ENV_CHECK_ALERT_ENABLED', false),
        // 告警渠道配置
        'channels' => [
            'http' => [
                'url'     => env('ENV_CHECK_ALERT_HTTP_URL', ''),
                'timeout' => 10,
            ],
            'webhook' => [
                'url'     => env('ENV_CHECK_ALERT_WEBHOOK_URL', ''),
                'secret'  => env('ENV_CHECK_ALERT_WEBHOOK_SECRET', ''),
                'timeout' => 10,
            ],
        ],
    ],

    // ------------------------------------------------------------
    // Web 后台配置
    // ------------------------------------------------------------

    /**
     * 健康检查页面路由路径
     */
    'route_path' => '/admin/health',

    /**
     * 是否启用 Web 健康检查页面
     */
    'web_enabled' => env('ENV_CHECK_WEB_ENABLED', true),

    /**
     * 访问健康页面的权限配置
     * - 'none'  : 无需权限
     * - 'login' : 需要登录
     * - 'auth'  : 需要特定权限节点
     */
    'web_auth' => 'auth',
    'web_auth_node' => 'envcheck.health',

    /**
     * Web 路由（配置驱动，由 RouteRegistrar 读取）
     * 未在此列出的键继承包默认配置（packages/wiseadmin/wise-health-check/config）。
     */
    'web' => [
        // 总开关：false 时本包不注册任何 HTTP 路由
        'enable' => true,
        // 默认路由前缀（分组级 prefix 可覆盖）；未设置时回退读取 route_path
        'prefix' => '',
        // 全局默认中间件（所有分组生效）
        'middleware' => [],
        // 分组配置：key 对应 RouteRegistrar::define() 中的 group('key', ...)
        'groups' => [
            'health' => [
                'enable'     => true,
                'middleware' => [],
            ],
            // 后台安全体检页面（/admin/security_audit/index、/stats）
            'security' => [
                'enable'     => true,
                // 沿用历史 URL，避免书签 / 外部引用失效；改写后需同步更新权限与文档
                'prefix'     => 'admin/security_audit',
                // 中间件留空：由宿主 admin 应用全局中间件链统一接管鉴权 / CSRF / 审计
                'middleware' => [],
            ],
        ],
    ],

    // ------------------------------------------------------------
    // 安全体检配置（后台安全配置聚合检查，由 SecurityAuditManager 消费）
    // ------------------------------------------------------------

    'security' => [
        /**
         * 安全体检检查器列表（按顺序执行）
         * 每个元素为 SecurityCheckInterface 实现类的完全限定名；
         * 实现类同时满足 CheckerInterface，可加入上方 checker 列表供 CLI / 告警复用。
         */
        'checker' => [
            \wise\health\security\checker\AdminEntryChecker::class,
            \wise\health\security\checker\LoginCaptchaChecker::class,
            \wise\health\checker\AppDebugChecker::class,
            \wise\health\security\checker\WeakAdminPasswordChecker::class,
            \wise\health\security\checker\PackageConfigChecker::class,
        ],

        /**
         * 管理员表与字段（弱口令检查；表名受 database.prefix 影响）
         */
        'admin_table'          => 'admins',
        'admin_id_field'       => 'id',
        'admin_username_field' => 'username',
        'admin_password_field' => 'password',
        'admin_status_field'   => 'status',
        'admin_active_status'  => 1,

        /**
         * 默认管理员用户名（弱口令扫描范围之一）
         */
        'default_admin_username' => 'admin',

        /**
         * 超级管理员 ID
         * 留空时依次回退 wise-auth.super_admin_ids → app.super_admin_ids
         */
        'super_admin_ids' => [],

        /**
         * 弱口令候选集（保守收缩，避免对大量账号执行 bcrypt 校验拖慢请求）
         */
        'weak_password_candidates' => ['123456', 'admin123', 'admin888'],

        /**
         * 单次弱口令扫描的最大账号数
         */
        'weak_password_scan_limit' => 20,
    ],

    // ------------------------------------------------------------
    // 后台顶栏入口配置
    // ------------------------------------------------------------

    'frontend' => [
        // 是否向后台顶栏注入「安全体检」入口（仅超管可见）
        'enable' => true,
        // 前端模块 key（包内模块须同名导出，宿主据此挂载）
        'key'    => 'healthSecurityBadge',
    ],

    // ------------------------------------------------------------
    // 环境检测配置（从 wise-environment-check 合并）
    // ------------------------------------------------------------

    /**
     * 环境检测器列表
     */
    'environment_checker' => [
        \wise\health\checker\PhpVersionChecker::class,
        \wise\health\checker\ExtensionChecker::class,
        \wise\health\checker\DirectoryPermissionChecker::class,
        \wise\health\checker\EnvFileChecker::class,
        \wise\health\checker\DatabaseConnectionChecker::class,
        \wise\health\checker\RedisConnectionChecker::class,
        \wise\health\checker\DiskSpaceChecker::class,
    ],

    'environment_production_extra_checks' => [
        \wise\health\checker\AppDebugChecker::class,
        \wise\health\checker\XdebugChecker::class,
        \wise\health\checker\CacheConfigChecker::class,
    ],
];
