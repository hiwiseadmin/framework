<?php
/**
 * WiseAuth RBAC 权限管理配置文件
 *
 * 可通过 config('wise-auth.xxx') 读取所有配置项。
 * 发布后自动复制到项目 config/ 目录，业务可按需覆盖。
 */
return [
    // -----------------------------------------------------------------------
    // 用户模型配置
    // -----------------------------------------------------------------------

    /**
     * 默认用户模型类（FQCN）
     * 该模型需要 use \wise\auth\trait\HasRoles
     */
    'default_user_model' => \app\admin\model\Admin::class,

    /**
     * 多用户类型映射
     * key: guard/user_type 标识符
     * value: 用户模型类 (FQCN)
     *
     * 示例：
     *   'admin'  => \app\admin\model\Admin::class,
     *   'member' => \plugin\member\model\Member::class,
     */
    'user_models' => [
        'admin'  => \app\admin\model\Admin::class,
        // 口径统一：会员模型以 member 插件为准（与 config/wise-oauth.php 及
        // wise-oauth 内的默认回退保持一致），避免同一 member 表出现两个模型的映射分叉。
        //
        // 注意：这里是 `::class` **字符串常量**（不触发自动加载），因此 member 插件
        // 停用时配置本身无害；但**消费方必须守卫** —— 任何 `app($class)` /
        // `$class::find()` 之前都要 `class_exists()`，否则运行到该分支即致命错误。
        // 通用链路（如附件鉴权 app\common\middleware\AttachmentLogin）已按此约定实现。
        'member' => \plugin\member\model\Member::class,
    ],

    /**
     * 会员模型「可用」判定口径（可选）
     *
     * 通用链路（附件鉴权等）只认「用户模型」而不认具体包，因此需要知道
     * 如何判断一个会员是有效登录态：
     *   1. 模型自带 `isActive()` 方法时优先使用（模型自述，最准确）；
     *   2. 否则按下面的字段 / 值判定（默认等价于此前的 `status === 1` 硬编码）。
     *
     * 仅当 `user_models.member` 对应模型确实存在时才生效。
     */
    'member_status_field'  => 'status',
    'member_active_status' => 1,


    // -----------------------------------------------------------------------
    // 超级管理员配置
    // -----------------------------------------------------------------------

    /**
     * 超级管理员角色标识（slug）
     * 拥有此角色的用户将绕过所有权限检查。
     * 设为 null 则禁用超级管理员概念，严格按 RBAC 判定。
     */
    'super_role' => 'super_admin',

    /**
     * 超级管理员用户 ID 列表
     * 这些 ID 的用户直接拥有全部权限，无需分配角色。
     * 通常与项目的 config('app.super_admin_ids') 保持一致。
     */
    'super_admin_ids' => [1],

    // -----------------------------------------------------------------------
    // 缓存配置
    // -----------------------------------------------------------------------

    /**
     * 是否启用权限缓存
     */
    'cache_enabled' => true,

    // 说明：cache_store / cache_store_config 不再在此声明，
    // 直接跟随包内默认值（专属 store「wise_auth」，由 AuthServiceProvider 自动注册）。
    // 如需覆盖（如复用宿主已有 store 或指定 Redis 驱动参数），再显式声明这两个键即可。

    /**
     * 缓存过期时间（秒）
     * null 表示永久有效（直到手动清除）
     */
    'cache_ttl' => 3600,

    /**
     * 缓存键前缀
     * 用于防止多应用实例之间的缓存键冲突
     */
    'cache_prefix' => 'wsauth:',

    /**
     * 权限操作审计日志
     *
     * 启用后自动记录所有角色/权限变更操作到 auth_audit_log 表。
     * 日志包含操作类型、实体信息、操作者ID、IP 地址等。
     *
     * 默认 true（审计日志开启）。
     */
    'audit_enabled' => true,

    // -----------------------------------------------------------------------
    // 权限加载与注册
    // -----------------------------------------------------------------------

    /**
     * 权限来源方式
     *
     * 支持多种来源组合：
     *   - 'database'  : 从数据库动态加载（默认）
     *   - 'attribute' : 扫描控制器上的 #[AuthPermission] Attribute 自动发现
     *   - 'config'    : 从本配置文件 'permissions' 节点加载
     *
     * 可同时启用多个来源，按数组顺序加载，后加载的会合并覆盖。
     */
    'permission_sources' => ['database', 'attribute'],
    
    // -----------------------------------------------------------------------
    // 权限 Attribute
    // -----------------------------------------------------------------------

    /**
     * Attribute 扫描目录
     * 当 'attribute' 在 permission_sources 中时，扫描这些目录的控制器，
     * 读取类/方法上的 #[AuthPermission] 声明并落库。
     *
     * 指向宿主 app 目录（composer PSR-4 自动加载，CLI 下可反射）；
     * 插件权限不走本链路（CLI 不加载 plugin\* 命名空间），由 manifest.json 的
     * menus / permissions 声明落库。
     */
    'attribute_dirs' => [
        app_path('admin/controller'),
		// app_path('member/controller'),
    ],

    /**
     * 配置文件驱动的权限列表（当 'config' 在 permission_sources 中时生效）
     * 每个权限包含：ident（标识）, name（名称）, type（类型: route/api/custom）,
     *   route（路由路径）, method（请求方法: GET/POST/PUT/DELETE/*）,
     *   description（描述）, parent（父权限标识，可选）
     *
     * 示例：
     * [
     *     ['ident' => 'admin.dashboard', 'name' => '仪表盘', 'type' => 'route', 'route' => '/admin/index/index', 'method' => '*'],
     *     ['ident' => 'admin.user.list',  'name' => '管理员列表', 'type' => 'route', 'route' => '/admin/admins/index', 'method' => '*'],
     * ]
     */
    'permissions' => [],

    // -----------------------------------------------------------------------
    // 权限检查策略
    // -----------------------------------------------------------------------

    /**
     * 默认检查模式
     *   - 'whitelist' : 白名单模式，未匹配路由默认拒绝（推荐）
     *   - 'blacklist' : 黑名单模式，未匹配路由默认允许
     */
    'default_mode' => 'whitelist',

    /**
     * 白名单路由（无需鉴权即可访问）
     * 支持路由 pattern，如 '/admin/login', '/admin/auth/*'
     */
    'whitelist_routes' => [
        '/admin/login/*',
        '/admin/index/lockscreen',
        '/admin/index/unlock',
        '/admin/search/*',
        // '/admin/auth/*',
    ],

    /**
     * 登录即可访问的路由（只跳过权限节点匹配，**仍需登录**）
     *
     * 用于后台首页、个人资料、消息中心等「框架外壳」入口：它们没有对应的业务
     * 菜单，因此不会有菜单派生的权限节点。在 default_mode=whitelist 下若不豁免，
     * 所有非超管登录后会被首页直接重定向到 403。
     *
     * 注意与 whitelist_routes 的区别：白名单完全跳过登录校验（匿名可访问），
     * 本项在登录校验之后生效，未登录仍会跳登录页。
     */
    'authenticated_routes' => [
        '/admin/index',
        '/admin/index/*',
        '/admin/profile',
        '/admin/profile/*',
        '/admin/system_message',
        '/admin/system_message/*',
        // 布局插槽数据：wise-bundle 在每个后台页面加载时都会拉取（未被授予权限节点
        // 的非超管此前会得到 403 HTML，控制台报「加载插槽配置失败」）
        '/admin/slot/list',
    ],

    /**
     * 黑名单路由（必须鉴权，即使用户已登录）
     * 仅在 default_mode 为 'blacklist' 时生效
     */
    'blacklist_routes' => [],

    // -----------------------------------------------------------------------
    // 未授权处理
    // -----------------------------------------------------------------------

    /**
     * 未授权时的处理行为
     *
     * 支持三种配置方式：
     *
     * 1. 字符串模式（向后兼容，所有请求统一处理）：
     *    'exception' | 'redirect' | 'json' | 'custom'
     *
     * 2. 'auto' 模式（推荐，根据请求类型自动判断）：
     *    AJAX/API 请求（X-Requested-With: XMLHttpRequest 或 Accept: application/json）
     *    → 返回 JSON 错误响应
     *    普通浏览器请求 → 重定向到 unauthorized_redirect
     *
     * 3. 数组模式（按请求类型分别配置）：
     *    [
     *        'default' => 'json',    // 默认处理（必须，当无法判断请求类型时使用）
     *        'ajax'    => 'json',    // AJAX/XHR/API 请求
     *        'web'     => 'redirect', // 普通网页请求（浏览器地址栏访问）
     *    ]
     */
    'unauthorized_action' => 'auto',

    /**
     * 重定向 URL（当 unauthorized_action = 'redirect' 时生效）
     */
    'unauthorized_redirect' => '/404.html',

    /**
     * 自定义未授权处理器（当 unauthorized_action = 'custom' 时生效）
     * 可设为闭包或实现了 __invoke($request) 的类名
     * function(\think\Request $request): \think\Response
     */
    'unauthorized_handler' => null,

    // -----------------------------------------------------------------------
    // 未登录处理（Authentication — 用户未认证）
    // -----------------------------------------------------------------------
    // 与"未授权处理"用法完全一致，但仅对"未登录"场景生效。
    // 各配置项设为 null 时自动回退到对应的 unauthorized_* 配置（向后兼容）。

    /**
     * 未登录时的处理行为
     *
     * 格式与 unauthorized_action 一致：
     *   1. 字符串：'exception' | 'redirect' | 'json' | 'custom'
     *   2. 'auto'：自动判断请求类型（AJAX→json，浏览器→redirect）
     *   3. 数组：['default' => 'json', 'ajax' => 'json', 'web' => 'redirect']
     *
     * 设为 null 时回退到 unauthorized_action（向后兼容）。
     */
    'not_logged_in_action' => 'redirect',

    /**
     * 未登录时重定向 URL（当 action 为 'redirect' 时生效）
     * 设为 null 时回退到 unauthorized_redirect（向后兼容）。
     */
    'not_logged_in_redirect' => '/admin/login',

    /**
     * 未登录时 JSON 响应的 HTTP 状态码（默认 401）
     */
    'not_logged_in_code' => 401,

    /**
     * 未登录时的自定义处理器（当 action 为 'custom' 时生效）
     * 签名：function(\think\Request $request, string $type): \think\Response
     * 设为 null 时回退到 unauthorized_handler（向后兼容）。
     */
    'not_logged_in_handler' => null,

    // -----------------------------------------------------------------------
    // 无权限处理（Authorization — 用户已认证但无权限）
    // -----------------------------------------------------------------------
    // 与"未授权处理"用法完全一致，但仅对"无权限"场景（路由无匹配 / 权限检查失败）生效。
    // 各配置项设为 null 时自动回退到对应的 unauthorized_* 配置（向后兼容）。

    /**
     * 无权限时的处理行为
     *
     * 格式同上。
     * 设为 null 时回退到 unauthorized_action（向后兼容）。
     *
     * 推荐配置：
     *   - 网页后台：'redirect'，配合 forbidden_redirect 跳转到独立提示页
     *   - API 服务：'json'，返回 403 JSON 错误
     *
     * 这里用数组：浏览器地址栏/iframe 打开页面仍跳 403 提示页；
     * AJAX（X-Requested-With / Accept: application/json）直接返回 403 JSON，
     * 避免前端 fetch 拿到 HTML 后抛 `Unexpected token '<'`。
     */
    'forbidden_action' => [
        'default' => 'json',
        'ajax'    => 'json',
        'web'     => 'redirect',
    ],

    /**
     * 无权限时重定向 URL（当 action 为 'redirect' 时生效）
     * 设为 null 时回退到 unauthorized_redirect（向后兼容）。
     *
     * 推荐设为 '/admin/403' 或 '/admin/no-permission'，
     * 该路由由 Service 自动加入 whitelist_routes 防止拦截死循环。
     */
    'forbidden_redirect' => '/403.html',

    /**
     * 无权限时 JSON 响应的 HTTP 状态码（默认 403）
     */
    'forbidden_code' => 403,

    /**
     * 无权限时的自定义处理器（当 action 为 'custom' 时生效）
     * 签名：function(\think\Request $request, string $type): \think\Response
     * 设为 null 时回退到 unauthorized_handler（向后兼容）。
     */
    'forbidden_handler' => null,

    // -----------------------------------------------------------------------
    // 角色继承
    // -----------------------------------------------------------------------

    /**
     * 是否启用角色继承（RBAC1）
     * 启用后，父角色自动拥有子角色的所有权限。
     */
    'inheritance_enabled' => true,

    /**
     * 角色继承最大深度
     * null 或 0 表示无限制；设为正整数限制继承层级。
     */
    'inheritance_max_depth' => 10,

    /**
     * 是否检测循环继承
     * 启用后，在保存角色父子关系时会自动检测并阻止循环引用。
     */
    'cycle_check_enabled' => true,

    // -----------------------------------------------------------------------
    // RBAC2 约束扩展
    // -----------------------------------------------------------------------

    /**
     * RBAC2 约束检查器
     *
     * 可设为：
     *   - null : 不启用约束检查
     *   - FQCN : 实现了 \wise\auth\contract\ConstraintCheckerInterface 的类
     *   - \Closure : function(string $event, array $context): bool
     *
     * 约束检查器会在角色分配/权限赋予等操作前后被调用。
     */
    'constraint_checker' => null,

    /**
     * 内置约束规则配置（轻量级 RBAC2）
     *
     * 支持：
     *   - 'mutual_exclusion' : 互斥角色（定义哪些角色不能同时分配给同一用户）
     *   - 'cardinality'      : 角色基数限制（限制某个角色最多分配多少用户）
     *   - 'prerequisite'     : 前置角色（分配某角色前必须先拥有另一角色）
     *
     * 示例：
     * 'constraints' => [
     *     'mutual_exclusion' => [
     *         ['admin', 'editor'],     // admin 和 editor 不能同时分配
     *         ['finance', 'auditor'],  // finance 和 auditor 不能同时分配
     *     ],
     *     'cardinality' => [
     *         'super_admin' => 3,      // super_admin 最多3人
     *     ],
     *     'prerequisite' => [
     *         'super_admin' => ['admin'],  // 分配 super_admin 前必须先有 admin
     *     ],
     * ],
     */
    'constraints' => [],

    // -----------------------------------------------------------------------
    // 中间件配置
    // -----------------------------------------------------------------------

    /**
     * 默认权限检查类型
     *   - 'and' : 用户必须拥有所有指定的权限
     *   - 'or'  : 用户拥有任意一个指定权限即通过
     */
    'middleware_check_type' => 'or',

    /**
     * 中间件登录检查
     * 设为 true 时，中间件会先检查用户是否已登录
     */
    'middleware_auth_check' => true,

    /**
     * 获取当前登录用户 ID 的回调
     * 闭包签名：function(): ?int
     * 若为 null，中间件使用默认逻辑获取。
     *
     * 示例：function() { return session('admin_id'); }
     */
    'user_resolver' => function() {
        switch(app('http')->getName()) {
            case 'admin':
                return  \app\admin\model\Admin::find(session('admin_id')); 
            default:
                return null;
        }
    },

    /**
     * 是否信任请求中的 auth_user_id 属性
     *
     * 当上游中间件（如 JWT 鉴权中间件）已将用户 ID 注入到
     * $request->auth_user_id 时，启用此选项允许 RbacMiddleware
     * 直接使用该值而无需通过 session 或数据库再次查询。
     *
     * 安全要求：上游中间件必须同时设置 $request->_rbac_trusted = true
     * 作为信任标记，防止请求伪造 auth_user_id。
     *
     * 默认 false（不接受请求中的 auth_user_id，必须通过 session 或 user_resolver 获取）。
     */
    'middleware_trust_auth_user_id' => false,

    /**
     * 是否启用权限版本检查（角色变更后强制重新登录）
     *
     * 启用后，当用户角色被修改/移除时，中间件会检测 session 中保存的
     * 权限版本号与缓存中的当前版本号是否一致，不一致时清除权限缓存并
     * 要求重新登录。
     *
     * 默认 true（开启角色变更后的会话保护）。
     */
    'middleware_permission_version_check' => true,

    // -----------------------------------------------------------------------
    // 数据权限（行级权限）
    // -----------------------------------------------------------------------

    /**
     * 数据权限策略
     * 设为闭包或实现了 __invoke 的类名，用于行级数据过滤。
     *
     * 签名：function(string $permission, $user, array $context = []): \Closure
     * 返回闭包用于修改查询条件。
     */
    'data_policy' => null,

    // =======================================================================
    // 登录安全（原 config/wise-login-security.php，2026-11-08 并入本文件）
    //
    // 这里只保留**与包默认值不同**的覆盖项（由包内 config/login-defaults.php 合并）；
    // 完整默认值见 packages/wiseadmin/wise-auth/config/login-defaults.php。
    // =======================================================================
    'login' => [
        // 生产环境开启验证码；本机调试可临时改为 false
        'captcha' => [
            'enabled' => true,
        ],

        // 失败次数落库（多机/重启后仍生效），而非缓存
        'failed_attempts' => [
            'store' => 'database',
        ],

        'events' => [
            'log_channel' => 'login_security',
            // 宿主实际的管理员模型（用于事件关联与安全事件列表展示）
            'user_model'  => 'app\admin\model\Admin',
        ],
    ],
];
