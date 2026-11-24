<?php

/**
 * WiseAdmin 在线演示沙箱配置
 *
 * 所有配置项均可通过 .env 覆盖。
 * 生产部署时按需修改以下环境变量。
 */

return [
    // 是否启用演示沙箱（关闭后所有沙箱路由返回 404）
    'enabled' => (bool) env('SANDBOX_ENABLED', true),

    // 模板数据库名（包含表结构和种子数据）
    'template_database' => env('SANDBOX_TEMPLATE_DB', 'wiseadmin_demo_template'),

    // 演示数据库前缀（克隆库命名: {prefix}{session_id}）
    'database_prefix' => env('SANDBOX_DB_PREFIX', 'wiseadmin_demo_'),

    // 演示账号（模板数据库中预先存在的管理员用户名）
    'demo_account' => env('SANDBOX_DEMO_ACCOUNT', 'demo'),

    // 会话有效期（分钟，0表示使用框架默认session过期时间）
    'session_ttl' => (int) env('SANDBOX_SESSION_TTL', 30),

    // 同一 IP 最大并发会话数（防滥用）
    'max_sessions_per_ip' => (int) env('SANDBOX_MAX_SESSIONS_PER_IP', 3),

    // 预热池大小（0表示禁用预热，生产环境建议 3~10）
    'pool_size' => (int) env('SANDBOX_POOL_SIZE', 0),

    // 演示入口路由路径（兼容旧版，新代码请使用 web 节）
    'entry_route' => env('SANDBOX_ENTRY_ROUTE', 'admin/demo'),

    // Web 路由（配置驱动，由 RouteRegistrar 读取）
    'web' => [
        // 总开关：false 时本包不注册任何 HTTP 路由
        'enable' => true,
        // 默认路由前缀（分组级 prefix 可覆盖）；未设置时回退读取 entry_route
        'prefix' => '',
        // 全局默认中间件（所有分组生效）
        'middleware' => [],
        // 分组配置：key 对应 RouteRegistrar::define() 中的 group('key', ...)
        'groups' => [
            'demo' => [
                'enable'     => true,
                'middleware' => [],
            ],
        ],
    ],

    // 体验完成后重定向地址
    'redirect_after_login' => env('SANDBOX_REDIRECT_URL', '/admin/index'),

    // 过期会话清理间隔（分钟，CronJob 执行频率）
    'cleanup_interval' => (int) env('SANDBOX_CLEANUP_INTERVAL', 5),

    // Redis 键前缀（用于会话状态存储）
    'redis_prefix' => env('SANDBOX_REDIS_PREFIX', 'sandbox:'),

    // ========================
    // 每表数据量控制
    // ========================

    // 每表最大行数（全局默认，0 表示不限制）
    // 防止操作日志等大表导致克隆耗时过长、磁盘占用过大
    'row_limit' => (int) env('SANDBOX_ROW_LIMIT', 500),

    // 必须全量复制的表（少一行功能就缺失）
    // 权限、菜单、角色、管理员等基础数据表，需要完整复制
    'full_copy_tables' => [
        'ws_admin_role',
        'ws_role',
        'ws_role_permission',
        'ws_permission',
        'ws_menu',
        'ws_config',
        'ws_attachment',
    ],

    // 仅复制表结构，不复制数据（日志、审计等大表）
    // 这些表空着不影响功能，体积却可能很大
    'structure_only_tables' => [
        'ws_operation_log',
        'ws_admin_login_log',
    ],

    // 不同表不同行数上限（覆盖全局 row_limit）
    // key 为完整表名（含前缀），value 为最大行数
    'table_limits' => [
        'ws_admins'        => 0,    // 全部（含 demo 账号）
        'ws_notification'  => 100,
        'ws_notification_log' => 0, // 0=仅结构
        'ws_crontab_log'   => 0,
        'ws_login_log'     => 0,
    ],

    // ========================
    // 运行时写入行数限制
    // ========================

    // 全局写入上行（INSERT/REPLACE 被拦截时检查）
    // -1 表示不限制，0 表示禁止写入，>0 表示行数上限
    'write_limit_global' => (int) env('SANDBOX_WRITE_LIMIT', 1000),

    // 单表写入上限（覆盖 write_limit_global）
    // key 为完整表名（含 ws_ 前缀），value 为行数上限
    // -1 = 不限制, 0 = 禁止写入该表, >0 = 上限行数
    'write_limits' => [
        'ws_articles'       => 200,
        'ws_upload_file'    => 500,
        'ws_notification'   => 200,
        'ws_notification_log' => 1000,
    ],
];
