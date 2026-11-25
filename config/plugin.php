<?php
// +----------------------------------------------------------------------
// | 插件配置
// +----------------------------------------------------------------------

return [
    // 自动更新插件列表
    'auto_update'       => true,
    // 插件服务器地址
    'service_url'        => 'https://wise.uxiangtk.com/',
    // 市场许可 Key：安装/升级**付费**插件时随请求携带（免费插件留空即可）
    // 建议经环境变量配置：PLUGIN_LICENSE_KEY=WISE-XXXX-XXXX-XXXX-XXXX
    'license_key'        => env('PLUGIN_LICENSE_KEY', ''),
    // 插件发布 token（会员 JWT），仅供 `php think plugin:publish` 使用。
    // 建议只走环境变量：PLUGIN_PUBLISH_TOKEN=<jwt>；命令不落盘、不打日志，也不接受账号密码。
    'publish_token'      => env('PLUGIN_PUBLISH_TOKEN', ''),
    // integration 测试环境（P2-11）
    // 空 = 直接在**当前真实库**跑 `plugin:test --integration`（`--expect-db` 可选，提供则强校验）；
    // 非空 = 切到项目根目录的 `.env.{name}`（必须完整副本）再跑，例如 'test' → `.env.test`。
    // 临时覆盖：`php think plugin:test --integration --test-env=test`
    // 注意：ThinkPHP 的 env() 只认 .env 里的键与 `PHP_` 前缀的系统变量；
    // 这里额外兜底 getenv()，让 CI 直接 `PLUGIN_TEST_ENV=test php think ...` 也能生效。
    'test_env'           => env('PLUGIN_TEST_ENV', getenv('PLUGIN_TEST_ENV') ?: ''),
    // 特殊插件映射表
    'special_plugins' => [
        'storage' => 11, // 存储插件分类ID
        'payment' => 12, // 支付插件分类ID
    ],
    // 插件后台鉴权（P0-1）
    // 为 plugin 应用统一补齐后台登录/权限校验，修复「插件后台接口可匿名调用」问题。
    'admin_auth' => [
        // 总开关：false 时完全跳过（用于灰度期整体回滚）
        'enabled' => true,
        // 路由级豁免（支持 * 通配），形如 'ident'、'ident/controller'、'ident/controller/action'
        'except_routes' => [],

        // fail-closed 插件列表（P0-2）
        //
        // 插件后台路由若在权限系统中**没有** route→permission 映射，默认放行
        // （避免误伤尚未登记权限节点的插件）——这会让任意最低权限账号都能调用
        // 未登记的高危动作（如 site/delete、model/import）。
        //
        // 列入本列表的插件改为 fail-closed：未匹配到权限节点 → 403。
        // 纳入前请确认该插件 manifest 的 `permissions` 已登记全部后台动作，
        // 否则这些动作将对所有角色不可用（超管不受影响）。
        // 支持 'ident' 精确匹配与 '*'（全部插件）。
        'fail_closed_plugins' => ['wisecms'],
    ],
];
