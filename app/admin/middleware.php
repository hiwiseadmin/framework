<?php
return [
    // 0. 多语言侦测（应用级，必须最先执行）
    //    think-multi-app 切换应用时会调用 App::loadLangPack() 把当前语言重置为
    //    default_lang（见 MultiApp::loadApp() 末行），而全局的 LoadLangPack 跑在
    //    应用切换之前，其侦测结果会被这一步覆盖。故应用级需要再侦测一次。
    \think\middleware\LoadLangPack::class,

    // 1. IP 黑白名单 — 全局前置，拒绝黑名单 IP 访问任何 admin 页面
    \wise\loginsecurity\middleware\IpGuardMiddleware::class,

    // 2. 保持登录 — Session 失效时用持久化 Cookie 恢复登录态（必须先于 RBAC）
    \app\admin\middleware\RememberLogin::class,

    // 3. RBAC 权限 — 登录鉴权 + 路由权限匹配
    \wise\auth\middleware\RbacMiddleware::class,

    // 4. TOTP 双因素认证 — 登录后检查 2FA（未启用 TOTP 的用户自动放行）
    \wise\loginsecurity\middleware\TwoFactorMiddleware::class,

    // 5. XSS 过滤 — 输入清洗 + 安全响应头（config/xss.php 控制）
    \app\common\middleware\XssFilterMiddleware::class,

    // 6. 审计日志 — 无条件全量记录（与操作日志完全解耦，独立 buffer/flush/开关）
    \wise\operationlog\middleware\AuditLog::class,

    // 7. CSRF 防护 — 表单令牌验证（config/csrf.php 控制）
    \app\common\middleware\VerifyCsrfToken::class,

    // 8. 操作日志 — 记录所有经允许通过的操作（依赖 Attribute 标注）
    \wise\operationlog\middleware\OperationLog::class,

    // 9. 菜单声明懒同步 — 仅当 ExtPoints::MENU_ITEM 声明指纹变化时执行一次（带节流）
    //    置于末尾：鉴权（RBAC）通过后才可能触发写操作；失败静默，不影响访问。
    \app\admin\middleware\MenuStaleSync::class,
];
