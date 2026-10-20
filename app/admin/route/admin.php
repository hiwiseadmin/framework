<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Route;

// ==================== 登录路由（绑定 wise-login-security 中间件） ====================

// LoginGuardMiddleware: V2 Pipeline 内置 IpGuardAction → LockCheckAction → CaptchaCheckAction
//   - captcha.enabled=false 时 CaptchaCheckAction 不实例化，跳过验证码
//   - require_captcha_after_failures 控制失败次数阈值（默认2，设0每次都需要）
// LoginResultMiddleware: 后置记录 — 失败计数、锁定通知、设备记录、登录成功清除、异常检测
Route::post('login/doLogin', 'Login/doLogin')->middleware([
    \wise\loginsecurity\middleware\LoginGuardMiddleware::class,    // 前置：IP/锁定/验证码（V2 Pipeline 内置 CaptchaCheckAction）
    \wise\loginsecurity\middleware\LoginResultMiddleware::class,   // 后置：自动记录（需放在守卫之后）
]);

// ==================== WiseConfig 控制器拆分 — 保持前端兼容的路由映射 ====================
// 原 WiseConfig 拆分为 WiseConfig / WiseConfigGroup / WiseConfigHistory / WiseConfigImport
// 以下路由将旧的 URL 映射到新的控制器，无需修改前端 JS 和菜单配置

// 分组管理 → WiseConfigGroup
Route::post('wise_config/renameGroup', 'WiseConfigGroup/renameGroup');
Route::post('wise_config/deleteGroup', 'WiseConfigGroup/deleteGroup');
Route::get('wise_config/availableGroups', 'WiseConfigGroup/availableGroups');

// 历史记录 → WiseConfigHistory
Route::get('wise_config/history', 'WiseConfigHistory/history');
Route::post('wise_config/historyRevert', 'WiseConfigHistory/historyRevert');

// 导入导出 → WiseConfigImport
Route::get('wise_config/export', 'WiseConfigImport/export');
Route::post('wise_config/copyToNamespace', 'WiseConfigImport/copyToNamespace');
Route::post('wise_config/importConfig', 'WiseConfigImport/importConfig');
Route::post('wise_config/uploadImage', 'WiseConfigImport/uploadImage');

// ==================== 插件配置页（manifest 配置项，统一入口） ====================
// 统一为 path 参数形式：/admin/plugin/configIndex/name/{ident}
//
// 为什么不能用 query 形式（/admin/plugin/configIndex?name=xxx）：
//   1. URL → 菜单的匹配只比对路径（query 被归一掉），各插件的配置菜单 pathname 相同，
//      刷新恢复/侧边栏高亮无法区分是哪个插件的配置；
//   2. RBAC 中间件常驻**应用级**流水线、在路由分派之前执行，取到的请求路由是
//      `pathinfo`（不含 query），与菜单 path 派生的权限 route（原样带 query）比对恒不命中
//      → whitelist 模式下非超管恒 403（docs/wisecms-后台UIUX-任务清单.md 阻塞 #24）。
//      改成 path 参数形式后「请求 route ≡ 权限 route」，精确命中。
//
// 显式规则的额外收益：`url('plugin_config_index', ['name' => $ident])` 会生成 path 形式；
// 若只写控制器地址（如 url('admin/plugin/configIndex', [...])），受 url_common_param=true
// 影响仍会拼成 `?name=`，又回到上面的问题 —— 生成链接时请用下面这个命名路由。
Route::get('plugin/configIndex/name/:name', 'Plugin/configIndex')
    ->pattern(['name' => '[A-Za-z0-9_\-.]+'])
    ->name('plugin_config_index');

// ==================== AI 助手路由 ====================
// 该路由已改由所属包内 RouteRegistrar 自注册（配置 web 节驱动），经 ExtPoints::ROUTE_ADMIN
// 延迟投递、由本目录 packages.php 的 applyRoutes() 落地为 /admin/ai/*（同应用内相对前缀 'ai'）。
// 卸载包后路由随之消失，此处不再硬编码，否则包内新增端点会因缺漏而 404。
