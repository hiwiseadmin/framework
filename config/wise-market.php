<?php
/**
 * wiseadmin/wise-market 默认配置
 *
 * 合并规则：包内默认值 + 宿主 `config/wise-market.php` 覆盖（宿主优先，列表整体替换）。
 * 读取方式：`config('wise-market.xxx')`。
 *
 * @see \wise\market\MarketServiceProvider::register() → ServiceTrait::mergeConfig()
 */

return [
    // ==========================================
    //  存储
    // ==========================================
    // 市场包存储路径（位于 Web 根目录之外，仅经带鉴权的下载接口分发）
    'upload_path' => runtime_path() . 'uploads/market/',

    // 允许的上传文件类型
    'allow_ext' => ['zip', 'rar'],

    // 最大文件大小 (MB)
    //
    // 注意：与 api 应用的请求体上限强耦合 —— 会员投稿走 `/api/market/member/submit`，
    // 受 `wise-api.body_size_limit.max_bytes`（宿主 config/wise-api.php，默认 10MB）约束，
    // 超限会被 BodySizeLimitMiddleware 直接 413。故此处取 10 与之对齐（D7 决策②）。
    // 如需放开更大体积，必须**同时**提升：
    //   1) config/wise-api.php → body_size_limit.max_bytes
    //   2) PHP  upload_max_filesize / post_max_size
    //   3) Nginx client_max_body_size
    'max_size' => 10,

    // ==========================================
    //  下载签名（Composer 自动安装通道）
    // ==========================================
    // 生产环境请在 .env 配置 MARKET_DOWNLOAD_SECRET
    'download_secret' => env('MARKET_DOWNLOAD_SECRET', ''),

    // 下载签名有效期（秒）
    'download_token_ttl' => 3600,

    // ==========================================
    //  状态映射
    // ==========================================
    'status_map' => [
        0 => '待审核',
        1 => '已上架',
        2 => '审核不通过',
        3 => '已下架',
    ],

    'version_status_map' => [
        0 => '待审核',
        1 => '已发布',
        2 => '已下架',
    ],

    // ==========================================
    //  Web 路由（配置驱动，由 RouteRegistrar 读取）
    // ==========================================
    'web' => [
        // 总开关：false 时本包不注册任何 HTTP 路由
        'enable'     => true,
        // 投递优先级（扩展点内排序）
        'priority'   => 100,
        // 全局默认中间件（所有分组生效）
        'middleware' => [],
        // 分组配置：key 对应 RouteRegistrar::define() 中的 group('key', ...)
        'groups'     => [
            // 后台管理页面：/admin/market/**（投递 ROUTE_ADMIN，鉴权由 admin 应用级 RBAC 白名单统一负责）
            'admin'  => [
                'enable'     => true,
                'prefix'     => 'admin/market',
                'middleware' => [],
            ],
            // 公开市场接口：/api/market/**（列表 / 详情 / 下载 / 许可 / Composer 仓库，无鉴权）
            'api'    => [
                'enable'     => true,
                'prefix'     => 'api/market',
                'middleware' => [],
            ],
            // 会员端投稿接口：/api/market/member/**（组级会员 JWT）
            'member' => [
                'enable'     => true,
                'prefix'     => 'api/market/member',
                'middleware' => [
                    \wise\member\middleware\JwtAuth::class,
                ],
            ],
        ],
    ],
];
