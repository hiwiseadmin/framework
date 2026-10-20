<?php
declare(strict_types=1);

/**
 * 后台管理端 App API 配置（/api/admin/*）
 *
 * 与后台 Web（/admin/*，Session + RbacMiddleware）并存：
 *   - App 端走 JWT（wise-jwt 的 admin guard，独立密钥 / issuer / 黑名单）；
 *   - 权限仍复用 auth_permissions 的 ident（菜单派生），
 *     因此后台角色授权页配置的权限对 App 同样生效。
 *
 * 路由注册见 app/api/route/api.php，控制器见 app/api/controller/admin/，
 * 权限中间件见 app/api/middleware/AdminApiPermission.php。
 *
 * ─────────────────────────────────────────────────────────────────────────
 * 【权限模型速查】三轮链路，顺序不可颠倒
 * ─────────────────────────────────────────────────────────────────────────
 *   1. JwtAuth(guard=admin)  验签 / 过期 / 黑名单
 *   2. AdminApiAuth          管理员存在、status=1、scope≠pending_2fa、权限版本 ver 一致
 *                            → 通过后注入 $request->admin
 *   3. AdminApiPermission    按路由声明的 ident 校验角色授权（本文件 fail_closed / check_type 生效处）
 *
 * 【ident 规则】
 *   - App 接口的 ident **一律复用后台已有节点**（菜单 path → path_to_dot，
 *     如 /admin/admin/index → admin.admin.index，见 config/menu_permission.php）。
 *   - **不要**新建 api.* 前缀节点：那样后台授权页看不到、任何角色都无法授予，
 *     结果是「除超管外恒 403」。
 *   - ident 必须字面量写在路由上，便于静态对账（放行前跑 `php think api:perm-check --strict`）。
 *
 * 【A/B 分组】见 app/api/route/api.php
 *   - A 组（只验身份）：me / menus / refresh / logout 等登录态通用接口；
 *   - B 组（验权限）：所有业务读写，逐接口挂 AdminApiPermission 并声明 ident。
 */

return [
    // 总开关：false 时不注册任何 /api/admin 路由（后台 Web 不受影响）
    'enable' => true,

    // JWT guard 名（对应 config/wise-jwt.php 的 guards.admin）
    // 生产环境务必配置 JWT_ADMIN_SECRET，避免与会员令牌共用顶层密钥
    'guard' => 'admin',

    // 正式 token 有效期（秒）
    'token_ttl' => 3600,

    // 双因素认证 pending token 有效期（秒）
    'pending_ttl' => 300,

    // JWT sub 前缀：避免与会员的纯数字 sub 撞号（双保险，issuer 已隔离）
    'subject_prefix' => 'admin:',

    /**
     * 接口未声明权限标识时的策略（fail-closed）
     *
     * ⚠️ 边界必须说清楚（最常见的误解）：
     *   本开关**只**作用于「路由挂了 AdminApiPermission，但解析不到 ident」这一种情形
     *   （路由参数没写、注解没写）。此时：
     *       true  = 403「接口未声明权限标识」（默认，推荐）
     *       false = 跳过权限校验，仅要求登录
     *
     *   它**管不到**「接口压根没挂 AdminApiPermission」的情况 —— 那种接口只有
     *   AdminApiAuth 的登录校验，等于对所有登录管理员开放。因此：
     *       - 业务接口一律放 B 组（组上挂 JwtAuth + AdminApiAuth，
     *         每个接口自己挂 AdminApiPermission 并写 ident）；
     *       - A 组只放 me / menus / refresh / logout 这类登录态通用接口；
     *       - 「业务接口误放 A 组」是本方案唯一的裸奔风险，靠 review 收敛。
     *
     *   另：不在组上再挂一次 AdminApiPermission 来「兜底」——TP 的组中间件先于
     *   路由中间件执行（think\route\Rule::getOption()），组级实例拿不到 ident，
     *   会让整组恒 403。
     *
     *   临时灰度（不建议长期开）：置 false 后，新接口即使忘写 ident 也能通，
     *   但会立刻失去「漏配即报错」的保护。
     */
    'fail_closed' => true,

    /**
     * 权限检查模式
     *   or  = 任一 ident 命中即通过（默认）
     *   and = 必须全部命中
     *
     * 与 wise-auth.middleware_check_type 相互独立：本项只作用于 App API 中间件。
     */
    'check_type' => 'or',

    // 登录
    'login' => [
        // 复用 wise-login-security 的失败锁定（LoginLockManager）
        'lock' => true,
        // 登录接口限流：令牌桶 [容量, 每秒填充]
        'rate_limit' => [10, 0.2],
    ],

    /**
     * 写会话（敏感写操作的二次验证）
     *
     * 背景：access token 默认 1 小时有效。手机丢失/被解锁时，仅凭 token 就能
     * 改密码、改角色、禁用账号 —— 破坏力远超只读接口。因此写接口统一要求：
     *   1. POST /api/admin/write-session 用「TOTP（已启用 2FA）」或「密码」换取；
     *   2. 请求头带 `X-Write-Token`（或 body 参数 write_token）；
     *   3. 校验由 app/api/middleware/AdminApiWriteGuard 完成。
     *
     * 关闭 enabled 会让写接口退化为「仅需 access token」，只在受控内网环境考虑。
     */
    'write_session' => [
        'enabled'  => true,
        // 写会话有效期（秒）：默认 10 分钟，够完成一次操作，泄露窗口最小
        'ttl'      => 600,
        // 连续失败上限（按管理员计数，独立于 Web 登录锁定）
        'max_fail' => 5,
        // 失败锁定时间（秒）
        'lock_ttl' => 600,
    ],

    /**
     * 排障指引（403 时先看响应体的 data.reason）
     *
     *   ident_missing  声明的 ident 不在 auth_permissions（节点未创建 / 拼写错误）
     *                  → 后台授权页看不到该节点，所有角色都授不了，除超管恒 403
     *                  → 处置：补节点，或修正路由 ident
     *   not_granted    节点存在，但当前账号的角色没被授予
     *                  → 处置：后台「角色管理」勾选该权限
     *
     * 命令：php think api:perm-check [--strict] [--show-ok]
     *   - 提取路由里的 ident 字面量，校验节点存在性 / 启用状态 / 是否被角色授予
     *   - 节点不存在时给出最相近的已有 ident（did you mean）
     *   - 检测「无参挂载」（组级挂载 AdminApiPermission）这类恒 403 配置
     */
];
