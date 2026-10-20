<?php
declare(strict_types=1);

/**
 * WiseApi 默认配置
 *
 * 复制本文件到项目 config 目录后按需调整。
 * 项目中可通过 `config('wise-api.xxx')` 读取。
 *
 * @package wise\api
 */

return [
    // ====================================================================
    // JWT 鉴权
    // ====================================================================
    'jwt' => [
        // 当前应用名（多应用隔离密钥时使用）
        'app'         => 'default',
        // 签名算法：HS256 | RS256
        'algorithm'   => 'HS256',
        // HS256 共享密钥（生产环境务必修改，建议从 .env 读取）
        'secret'      => env('JWT_SECRET', 'please-change-me-in-production-32bytes!'),
        // RS256 私钥/公钥 PEM 路径（仅 RS256 时使用）
        'private_key' => env('JWT_PRIVATE_KEY', ''),
        'public_key'  => env('JWT_PUBLIC_KEY', ''),
        // access_token 有效期（秒）
        'ttl'         => 7200,
        // 刷新阈值（秒）：剩余有效期小于该值时自动续签
        'refresh_ttl' => 1800,
        // 签发者
        'issuer'      => 'wise-api',
        // token 提取顺序：header(Authorization Bearer) → cookie(name=wise_token)
        'token_header'=> 'Authorization',
        'token_cookie'=> 'wise_token',
    ],

    // ====================================================================
    // 签名验证
    // ====================================================================
    'sign' => [
        // 是否启用
        // Phase 2 全局默认关闭：插件路由 /:plugin/* 暂无签名机制，需签名的接口在控制器级 opt-in
        'enable'      => false,
        // 签名算法：HMAC-SHA256
        'algorithm'   => 'sha256',
        // 签名密钥（每个 app_key 可对应不同 secret）
        'secret'      => env('JWT_SIGN_SECRET', 'please-change-me-in-production-sign-secret!'),
        // 时间戳容差（秒），超出视为重放攻击
        'timestamp_ttl' => 300,
        // 是否启用 nonce 防重放（基于 Redis 缓存 5 分钟）
        'enable_nonce'  => true,
        // 排除参与签名的字段
        'excludes'      => ['sign'],
        // 签名头名（用于传入 app_key 以支持多应用密钥）
        'app_key_field' => 'app_key',
        // S3: 是否对 JSON/XML 请求体签名（防止请求体被篡改）
        'sign_body'     => false,
        // S3: Body 签名哈希算法（md5 / sha256）
        'sign_body_algo' => 'md5',
    ],

    // ====================================================================
    // 限流
    // ====================================================================
    'rate_limit' => [
        // 是否启用
        'enable'    => true,
        // 默认算法：token_bucket | sliding_window
        'algorithm' => 'token_bucket',
        // 限流键生成策略：ip | user_id | api_path | combine(默认 ip+api_path)
        'key_by'    => 'combine',
        // 存储驱动：仅 redis 可用
        'store'     => 'redis',
        // 全局默认配置
        'default'   => [
            'algorithm' => 'token_bucket',
            // 令牌桶：桶容量
            'capacity'  => 60,
            // 令牌桶：每秒填充速率
            'rate'      => 1.0,
        ],
        // 按 API 分组预设（按 api_path 前缀匹配，路径格式同 pathinfo）
        'groups'    => [
            // 附件上传接口收紧限流（10 容量 + 0.5 token/s）
            '/attachment/upload'  => ['algorithm' => 'token_bucket', 'capacity' => 10, 'rate' => 0.5],
            '/attachment/delete'  => ['algorithm' => 'token_bucket', 'capacity' => 20, 'rate' => 1.0],
            // 后台 App 登录（/api/admin/login）：10 容量 + 0.2 token/s，防口令爆破
            '/admin/login'        => ['algorithm' => 'token_bucket', 'capacity' => 10, 'rate' => 0.2],
        ],
        // 触发限流时返回的 HTTP 状态码
        'http_code' => 429,
        // 多应用隔离前缀（多应用部署时避免限流键串号）
        // 默认读取 jwt.app，设为 '' 关闭隔离
        'app_prefix' => env('APP_NAME', 'default'),
    ],

    // ====================================================================
    // 统一响应
    // ====================================================================
    'response' => [
        // 业务成功状态码
        'success_code' => 0,
        // 业务默认成功消息
        'success_msg'  => 'success',
        // 是否输出 timestamp 字段
        'with_timestamp' => true,
    ],

    // ====================================================================
    // API 调试面板（仅 Debug 模式可见）
    // ====================================================================
    'debug' => [
        // 是否启用（仅当 app()->isDebug() 为 true 时生效）
        'enable'   => true,
        // 输出方式：response_header | route
        // route 模式会注册 /__debug_api 返回最近一次请求的调试信息
        'output'   => 'route',
        // 是否记录 SQL 日志
        'with_sql' => true,
        // 是否在响应 Header 中附带 X-Debug-Info
        'with_header' => true,
    ],

    // ====================================================================
    // API 版本管理（配合 DeprecationMiddleware 使用）
    //
    // DeprecationMiddleware 从 URL 路径提取版本号：/api/v1/users → v1
    // 当前 app\api 路由不包含 /api/v{N}/ 前缀，DeprecationMiddleware 为 opt-in
    // ====================================================================
    'version' => [
        // 是否启用框架/协议版本协商（ProtocolVersionMiddleware，头信息）
        'enable' => true,
        // 协商所用的请求头名
        'header' => 'X-API-Protocol-Version',
        // 当前支持的框架/协议版本
        'supported' => ['v1'],
        // 默认协议版本（请求头未携带或携带非法值时使用）
        'default' => 'v1',
        // 框架协议版本的废弃配置（可选，预留）
        'deprecated' => [
            // 'v1' => [
            //     'sunset'  => '2027-01-01',
            //     'message' => '协议 v1 即将下线，请升级',
            // ],
        ],
    ],

    // ====================================================================
    // 响应压缩
    // ====================================================================
    'compression' => [
        // 是否启用（配合 CompressionMiddleware 使用）
        // Phase 3 已注册 CompressionMiddleware，通过子开关 gzip/brotli 控制
        'enable' => true,

        // 最小压缩字节数（小于该值不压缩）
        'min_bytes' => 1024,

        // ---- gzip 压缩 ----
        // ⚠ 生产环境建议：若 Nginx 已配置 gzip on，请设为 false
        //   Nginx C 实现的 gzip 比 PHP gzencode() 快得多，避免双重压缩和性能浪费
        //   检查方法：curl -sI https://your-host | grep Content-Encoding
        'gzip_enabled' => true,

        // gzip 压缩级别（0-9，默认 6）
        'gzip_level' => 6,

        // ---- brotli 压缩 ----
        // brotli 比 gzip 压缩率高 15%-25%，且标准 Nginx 通常不带 brotli 模块
        // 推荐始终开启，由 PHP 层补充 Nginx 缺失的 brotli 能力
        'brotli_enabled' => true,

        // brotli 压缩级别（0-11，默认 4）
        'brotli_level' => 4,
    ],

    // ====================================================================
    // 请求体大小限制
    // ====================================================================
    'body_size_limit' => [
        // 是否启用（配合 BodySizeLimitMiddleware 使用）
        'enable' => true,
        // 默认限制字节数（10MB，适配附件上传接口）
        'max_bytes' => 10485760,
    ],

    // ====================================================================
    // API 日志记录器
    // ====================================================================
    'logger' => [
        // 全局开关
        'enabled' => true,

        // 默认日志通道
        'default_channel' => 'api',

        // 默认应用标识
        'default_app_key' => '',

        // 请求/响应体最大长度
        'max_body_length' => 65536,

        // 是否记录请求头
        'log_request_headers' => true,

        // 是否记录响应头
        'log_response_headers' => false,

        // 是否记录响应体（默认关闭，减少大响应带来的内存开销）
        // 设置为 true 时记录完整响应体（受 max_body_length 限制）
        // 设置为 'error_only' 时仅记录状态码 >= 400 的错误响应体
        'log_response_body' => 'error_only',

        // 敏感头字段（这些头的值会被替换为 ***）
        'sensitive_headers' => ['authorization', 'cookie', 'x-api-key'],

        // 跳过的路由
        'skip_routes' => [],

        // 跳过的路径（前缀匹配）
        'skip_paths' => ['/health', '/metrics'],

        // 跳过的 HTTP 方法
        'skip_methods' => ['OPTIONS'],

        // 是否自动建表（默认关闭，建议通过 InstallCommand 或迁移文件建表）
        'auto_create_table' => false,

        // 慢请求检测
        'slow_request' => [
            // 全局阈值/毫秒（0 表示关闭检测）
            'threshold_ms' => 1000,
            // 按路径分组阈值（前缀匹配，单位毫秒）
            'groups' => [
                // '/api/v1/export' => 5000,  // 导出类接口放宽
                // '/api/v1/upload' => 10000, // 上传类接口更宽松
            ],
        ],

        // 驱动配置
        'drivers' => [
            // 文件驱动
            'file' => [
                'enabled' => true,
                'path' => '',               // 空则使用 runtime_path() . 'api_log'
                'max_files' => 30,          // 保留最大文件数
                'max_file_size' => 52428800, // 单文件最大 50MB
            ],

            // 数据库驱动
            'database' => [
                'enabled' => false,
                'table' => 'api_log',
                'connection' => '',          // 空则使用默认连接
                'batch_size' => 50,          // 批量写入大小
            ],

            // 队列驱动
            'queue' => [
                'enabled' => false,
                'queue_name' => 'api_log',
                'job_class' => '',           // 队列任务类名，留空则使用包内默认 Job（wise\api\logger\job\ApiLogJob）
                'delay' => 0,               // 延迟时间（秒）
            ],
        ],
    ],

    // ====================================================================
    // IP 过滤（白名单/黑名单，配合 IpWhitelistMiddleware 使用）
    // ====================================================================
    'ip_filter' => [
        // 是否启用
        'enable' => false,
        // 过滤模式：whitelist | blacklist
        'mode'    => 'blacklist',
        // IP 列表（支持单个 IP / CIDR 网段）
        'ips'     => [
            // '192.168.1.100',
            // '10.0.0.0/8',
        ],
        // 是否启用 Redis 动态加载（实时生效，无需重启）
        'redis_enable' => false,
        // Redis 键名（值应为 string[] 类型）
        'redis_key'    => 'wise_api:ip_filter',
    ],

    // ====================================================================
    // Webhook 系统
    // ====================================================================
    'webhook' => [
        // 是否启用
        'enable'          => false,
        // 最大重试次数
        'max_retries'     => 3,
        // 连续失败多少次后自动停用
        'disable_after_failures' => 10,
        // 请求超时/秒
        'timeout'         => 10,
    ],

    // ====================================================================
    // 响应缓存（F1: 配合 CacheResponseMiddleware 使用）
    // ====================================================================
    'cache_response' => [
        // 是否启用
        'enable' => false,
        // 缓存驱动（默认使用 cache.php 默认驱动）
        'store' => '',
        // 缓存键前缀（多应用部署时建议区分）
        'prefix' => 'api_cache:',
        // 默认 TTL/秒
        'default_ttl' => 300,
        // 是否跳过已认证请求（推荐开启，避免用户数据泄露）
        'skip_auth' => true,
        // 跳过的路径（前缀匹配）
        'skip_paths' => [],
        // 按路由分组 TTL
        'groups' => [
            // '/api/dict/list' => 3600,
            // '/api/config/all' => 7200,
        ],
    ],

    // ====================================================================
    // API Bundle 注册表（wise-* 库包统一入口）
    //
    // 背景：/api/{plugin}/... 由 ApiDispatch 统一调度，默认只解析插件命名空间
    // plugin\{ident}\controller\api\，且要求该 ident 已入驻插件系统。而 wise-* 库包
    // （type:library，控制器在 wise\{pkg}\ 命名空间）不满足该条件 —— 这正是
    // 「包内 api\* 路由无效」的根因。
    //
    // 解法：此处登记库包为 API Bundle，调度器命中后跳过插件生命周期检查，
    // 直接按 namespace 解析控制器，与插件 API 共享同一入口 / 统一响应 / 中间件链。
    //
    // URL 契约与插件完全一致：/api/{bundle}[/v{N}][/{controller}[/{action}]]
    //   - {controller}/{action} 由 namespace 下真实控制器类提供（如 namespace 为
    //     wise\game\core\controller，则 /api/game/leaderboard/rank →
    //     wise\game\core\controller\LeaderboardController@rank）。
    //   - 控制器类名两种形态均可命中：Xxx 与 XxxController。
    //
    // 迁移步骤（以 wise-game-core 为例）：
    //   1. 在包 ServiceProvider::boot() 中移除 RouteRegistrar::register()（停止静态路由）。
    //   2. 在下方登记 bundle（enable=true + namespace）。
    //   3. 客户端 URL 保持不变（/api/game/...），获得与插件 API 一致的网关能力。
    // ====================================================================
    'bundles' => [
        // 小游戏核心：排行榜 / 存档 / 配置下发 / 背包（控制器仍在 controller\ 根，
        // 类名为 XxxController；新包建议统一 controller\api\ 子目录 + Xxx 类名）
        'game' => [
            'enable'    => true,
            'namespace' => 'wise\\game\\core\\controller',
            // 可选版本策略（与插件 manifest api 字段同构）；不配置则默认无版本管理
            'policy'    => [],
        ],
        // wise-wx 使用包内显式路由（前缀为剥离后的 wx，见 wise-wx/config/wise-wx.php web 节）。
        // 注意：若同时登记为 bundle，/api/wx/* 会出现"显式路由 + catch-all 推断"双通道——
        // 凡显式规则未覆盖的子路径都会被兜底按 URL 默认控制器格式误执行，并绕过 auth 分组
        // 的 JWT 中间件，故此处保持停用：
        // 'wx' => ['enable' => true, 'namespace' => 'wise\\wx\\controller'],
        // 'workflow' => ['enable' => true, 'namespace' => 'wise\\workflow\\controller'],
    ],
];
