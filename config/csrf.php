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
    // 需要排除CSRF验证的路由
    'except' => [
        'admin/login/*',
    ],
    // CSRF有效期（秒）
    'expire' => 86400,
    // 错误提示信息
    'error_msg' => 'CSRF令牌验证失败，请刷新页面重试',
];