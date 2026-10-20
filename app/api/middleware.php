<?php
// API 应用级中间件
//
// think-multi-app 在应用目录存在 middleware.php 时会把它并入 'app' 中间件流水线
// （MultiApp::loadApp → middleware->import(..., 'app')），作用于 /api/* 的**所有**请求：
//   - 本应用自身的兜底调度规则（:plugin/... → ApiDispatch）
//   - wise-* 包经 ExtPoints::ROUTE_API 挂载的路由（app/api/route/api.php 中 applyRoutes 落地）
//
// 也就是说，包路由无需自己声明这些共性能力，就能自动继承——这正是「包只维护自己的
// 私有中间件，全应用共性中间件由应用统一装配」的落点，避免各包各自为政导致
// 追踪缺失、限流遗漏、日志不全、IP 过滤被绕过。
// 与 admin 应用一致：app/admin/middleware.php 承载 IpGuard → RBAC → 2FA → 审计 等
// 后台统一链路，挂在 admin 应用下的包路由同样自动继承。
//
// 注意：认证（JwtAuth）不在此列——它属于「按路由组」的决策，公开接口（wx 登录、
// 加密公钥分发等）必须能匿名访问，因此由各组在自己的 middleware 中声明。
return [
    // L0: 多语言侦测（应用级，必须最先执行）
    //     think-multi-app 切换应用时会调用 App::loadLangPack() 把当前语言重置为
    //     default_lang（见 MultiApp::loadApp() 末行），而全局的 LoadLangPack 跑在
    //     应用切换之前，其侦测结果会被这一步覆盖。故应用级需要再侦测一次。
    \think\middleware\LoadLangPack::class,

    // L1: 响应压缩（最外层，压缩最终输出，config('wise-api.compression') 控制）
    \wise\api\middleware\CompressionMiddleware::class,

    // L2: 请求体大小限制（10MB，防大 payload）
    [\wise\api\middleware\BodySizeLimitMiddleware::class, [10485760]],

    // L3: 全链路追踪 ID
    \wise\api\middleware\TraceIdMiddleware::class,

    // L3.5: 框架/协议版本协商（仅请求头 X-API-Protocol-Version，与 URL 版本互不干扰）
    \wise\api\middleware\ProtocolVersionMiddleware::class,

    // L4: API 限流（令牌桶，60 容量 + 1 token/s）
    [\wise\api\middleware\RateLimitMiddleware::class, [60, 1]],

    // L5: 签名验证（HMAC-SHA256，config('wise-api.sign.enable') 控制，默认关闭）
    \wise\api\middleware\SignVerifyMiddleware::class,

    // L6: API 请求日志（多驱动 File/Database/Queue）
    \wise\api\middleware\ApiLogMiddleware::class,

    // IP 白名单 / 黑名单
    \wise\api\middleware\IpWhitelistMiddleware::class,

    // API 版本弃用通知
    \wise\api\middleware\DeprecationMiddleware::class,
];
