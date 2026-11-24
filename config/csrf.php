<?php
/**
 * 表单令牌支持配置
 */
return [
    // 是否开启CSRF验证
    'enabled' => true,
    // Cookie中Token的键名
    'cookie_key' => 'csrf_token',
    // 表单或HTTP头中Token的参数名
    'param_key' => '_token',
    // CSRF Token 的 Header 名称（与前端注入保持严格一致，避免大小写歧义）
    'header_name' => 'X-CSRF-TOKEN',
    // Cookie 安全选项
    // 注意：实际 secure 已跟随全局 cookie.secure（由 AppService::initCookieSecureFlag()
    // 按请求协议动态修正：HTTP→false / HTTPS→true），此处仅作兜底默认值。
    // 若写死 true，HTTP 环境下 csrf_token 会被标为 Secure，浏览器拒绝保存，导致验证永远失败。
    'secure' => true,
    // 需要排除 CSRF 验证的路由（格式：应用名/路径，支持 * 通配）
    //
    // 【admin/login/* 是分层边界，不要移除】
    // 登录端点**不由本中间件守护**，而是交给 wise-login-security 包自行处理：
    //   1. 令牌：wise\login\middleware\CsrfTokenMiddleware（Session 令牌，
    //      配置见 config/wise-login-security.php 的 csrf 段），已挂在 login/doLogin 路由；
    //   2. 来源：LoginGuardMiddleware 管道内的 SameOriginCheckAction（Origin/Referer）。
    // 两层都属于「登录安全子系统」，与全局 CSRF 各自独立、互不读取。
    //
    // 之所以必须分家：
    //   - 全局层是 Cookie 令牌，业务页面靠 JS 读 Cookie 提交；
    //   - 登录页在未登录状态下渲染，属于另一个生命周期，令牌与页面必须由
    //     登录子系统自己签发，否则会把两层令牌混用成「A 签发、B 校验」。
    //
    // 新增后台业务路由不要往这里加；新增登录安全端点请用包内 CsrfTokenMiddleware。
    'except' => [
        'admin/login/*',
    ],
    // CSRF有效期（秒）
    'expire' => 86400,
    // 错误提示信息
    'error_msg' => 'CSRF令牌验证失败，请刷新页面重试',
];