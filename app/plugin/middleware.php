<?php
/**
 * 插件应用（app = plugin）中间件定义＼�
 *
 * 为 `/plugin/*` 请求补齐「管理员登录 + 权限」校验。
 *
 * 背景：该应用此前没有 middleware.php，且 RBAC 中间件只在 admin 应用注册、
 * EntryVerify 又把 plugin 列为免校验应用，导致插件后台接口可被匿名访问（审计 C1）。
 *
 * 说明：
 * - 只守护继承 `plugin\BaseController` 的后台控制器；前台（`plugin\FrontController`）
 *   与 JSON API（`plugin\ApiController`）不受影响。
 * - 回滚开关：`config('plugin.admin_auth.enabled')`（见 config/plugin.php）。
 * - 个别豁免：`config('plugin.admin_auth.except_routes')`；
 *   控制器亦可用 `noNeedLogin / noNeedAuth / noNeedRight` 声明白名单动作。
 */
return [
    \think\middleware\LoadLangPack::class,

    \app\plugin\middleware\PluginAdminAuth::class,
];
