<?php
// +----------------------------------------------------------------------
// | 后台保持登录（Remember Me）配置
// | 勾选「保持登录」后在 Session 之外签发持久化 Cookie（selector:validator，
// | 仅存哈希于数据库），由 app\admin\middleware\RememberLogin 恢复登录态。
// +----------------------------------------------------------------------

return [
    // 是否启用
    'enabled' => true,

    // 免登录有效期（秒），默认 7 天
    'ttl' => 7 * 24 * 60 * 60,

    // 持久化 Cookie 名称
    'cookie' => 'admin_remember',

    // Cookie 路径
    'path' => '/',

    // Cookie 作用域（留空跟随当前域名）
    'domain' => '',

    // 单个管理员最多保留的令牌数（超出时淘汰最旧的，防止无限增长）
    'max_tokens' => 5,
];
