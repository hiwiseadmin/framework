<?php
declare(strict_types=1);

/**
 * wise-mcp 默认配置
 *
 * 宿主可在 config/wise-mcp.php 中覆盖任意键（ServiceTrait::mergeConfig 递归合并，
 * 宿主配置优先）。本包只承担「协议 + 传输 + 双角色」职责，不含任何 AI/Agent 概念。
 */
return [
    // =========================================================================
    // 总开关
    // =========================================================================
    // 是否启用 MCP HTTP 端点（由 web 配置决定挂载点）。stdio 通道由命令单独控制。
    'enable' => false,

    // =========================================================================
    // 服务端信息
    // =========================================================================
    'server' => [
        // 对外暴露的 serverInfo.name / version
        'name'    => 'wise-mcp',
        'version' => '1.0.0',
        // 指令说明（initialize 响应中的 instructions）
        'instructions' => 'WiseAdmin MCP server. Exposes capabilities contributed by registered providers.',
    ],

    // =========================================================================
    // 协议与能力协商
    // =========================================================================
    'protocol' => [
        // 服务端当前支持的协议版本
        'version' => \wise\mcp\protocol\ProtocolVersion::CURRENT,
        // 可协商接受的版本集合（客户端请求命中集合时回显客户端版本）
        'supported' => \wise\mcp\protocol\ProtocolVersion::SUPPORTED,

        // 服务端能力声明（initialize 响应 capabilities）
        // listChanged=true 表示服务端会在能力变化时发送 notifications/*/list_changed
        'capabilities' => [
            'tools'     => ['listChanged' => true],
            'resources' => ['subscribe' => false, 'listChanged' => true],
            'prompts'   => ['listChanged' => true],
            // 是否声明 logging 能力（可发送 notifications/message）
            'logging'   => true,
        ],
    ],

    // =========================================================================
    // 传输
    // =========================================================================
    'transports' => [
        // stdio：供本地客户端（Claude Desktop / IDE）以子进程方式连接
        'stdio' => [
            'enable' => true,
        ],
        // Streamable HTTP：远程客户端经 POST 调用，可选 SSE 升级
        'http' => [
            'enable' => true,
            // 会话：assign Mcp-Session-Id，用于 GET SSE 推送服务端通知与 DELETE 终止
            'sessions' => [
                'enable' => true,
                'ttl'    => 1800,
                // 缓存驱动，留空使用默认；跨请求（FPM）需配置真实缓存
                'store'  => '',
            ],
        ],
    ],

    // =========================================================================
    // 客户端：wise-mcp 作为 MCP Client 连接的外部服务器
    // =========================================================================
    // 每个条目：
    //   'transport' => 'stdio' | 'http'
    //   stdio: command / args / cwd / env
    //   http : url / headers
    //   'tools'     => ['tool_a', 'tool_b']   可选：仅导入白名单内的工具
    // 仅当 security.allowed_servers 为空或包含该名称时才会连接。
    'clients' => [
        // 'filesystem' => [
        //     'transport' => 'stdio',
        //     'command'   => 'npx',
        //     'args'      => ['-y', '@modelcontextprotocol/server-filesystem', '/data'],
        //     'tools'     => ['read_file', 'list_directory'],
        // ],
        // 'remote' => [
        //     'transport' => 'http',
        //     'url'       => 'https://example.com/mcp',
        //     'headers'   => ['Authorization' => 'Bearer xxx'],
        // ],
    ],

    // =========================================================================
    // 客户端运行时
    // =========================================================================
    'client' => [
        // 客户端根（roots）：服务端可请求 roots/list 获取
        'roots' => [
            // ['uri' => 'file:///data', 'name' => 'data'],
        ],

        // 连接池：复用连接，避免每次调用都重新 spawn / 建连
        'connection' => [
            'idle_ttl' => 300,
        ],

        // 能力发现缓存：缓存外部工具列表，避免每请求重复拉取
        'cache' => [
            'enable' => true,
            'ttl'    => 300,
            'store'  => '',
        ],
    ],

    // =========================================================================
    // 能力提供者自动发现
    // =========================================================================
    // 扫描插件 manifest.json 的 "mcp" 声明与 Composer 包的 extra.think-mcp，
    // 自动注册为 MCP 能力提供者（无需插件依赖 wise-agent）。
    'discovery' => [
        'enable'        => true,
        // 扫描 plugins/plugin 目录下的 manifest.json
        'scan_plugins'  => true,
        // 扫描 vendor 中包的 extra.think-mcp
        'scan_composer' => true,
        // 发现结果缓存（避免每请求扫描文件系统）
        'cache_ttl'     => 3600,
        'cache_store'   => '',
    ],

    // =========================================================================
    // 安全
    // =========================================================================
    'security' => [
        // 外部服务器白名单：为空表示不限制；非空时仅连接列表内名称
        'allowed_servers' => [],
        // 是否允许暴露/调用具有写语义的工具（工具 meta.requires_confirmation=true 视为写工具）
        // 默认关闭：HTTP 端点面向外部，写操作应由宿主显式开启
        'allow_write'     => false,
        // stdio 客户端允许执行的命令白名单（为空表示不限制）。生产环境建议收紧。
        'allowed_commands' => [],

        // HTTP 端点是否要求已登录（由 McpGuardMiddleware 执行，复用 wise-support AuthManager）
        // 默认开启：避免端点一旦启用即匿名暴露全部能力
        'require_auth' => true,
        // 认证 Guard 名（admin / api / member …）
        'guard'        => 'admin',

        // HTTP 端点允许的跨源 Origin 白名单（防 DNS rebinding）。
        // 为空时仅允许与当前 Host 同源；无 Origin 的原生客户端（非浏览器）放行。
        'allowed_origins' => [],

        // HTTP 请求体大小上限（字节），默认 1MB
        'max_body_bytes' => 1048576,

        // HTTP 端点限流（复用 wise-support RateLimiter），按客户端 IP
        'rate_limit' => [
            'enable' => true,
            'limit'  => 120,
            'window' => 60,
            // 缓存驱动，留空使用默认；高并发建议 'redis'
            'store'  => '',
        ],
    ],

    // =========================================================================
    // Web 路由（配置驱动，由 RouteRegistrar 消费）
    // =========================================================================
    'web' => [
        'enable'     => false,
        // 归属应用：admin / api / member / plugin；留空则按 prefix 首段嗅探
        'app'        => 'admin',
        'prefix'     => 'admin/mcp',
        // 默认中间件：守卫中间件内部按 security 配置决定是否认证/限流
        'middleware' => [
            \wise\mcp\middleware\McpGuardMiddleware::class,
        ],

        'groups' => [
            'admin' => [
                'enable' => true,
                // 可在此追加宿主自定义中间件（如 RBAC）：
                // 'middleware' => [\wise\auth\middleware\RbacMiddleware::class],
                'middleware' => [],
            ],
        ],
    ],
];
