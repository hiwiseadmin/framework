<?php
// API 应用路由
//
// think-multi-app 在解析出 app=api 后会把 pathinfo 中的 'api' 段剥离，
// 因此本文件中的规则前缀都是「应用内相对路径」：
//   /api/wx/login  → pathinfo 'wx/login'  → 路由前缀 'wx'
//
// 全应用的共性中间件（压缩/限流/追踪/日志/IP 过滤等）装配在 app/api/middleware.php，
// 由 think-multi-app 并入 'app' 流水线，对本文件注册的一切规则（含包路由）统一生效。
//
// wise-* 库包不直接注册路由，而是把分组投递到 ExtPoints::ROUTE_API 扩展点
// （见 wise\support\router\RouteRegistrar），由本文件在应用路由加载阶段统一落地。

use app\api\dispatch\ApiDispatch;
use app\api\middleware\AdminApiAuth;
use app\api\middleware\AdminApiPermission;
use app\api\middleware\AdminApiWriteGuard;
use think\facade\Route;
use wise\api\middleware\JwtAuthMiddleware;
use wise\support\ExtPoints;
use wise\support\router\RouteRegistrar;

// ① wise-* 库包路由（更精确的规则优先匹配，必须排在兜底规则之前）
//    停用的实现已由 applyRoutes() 过滤，能力市场可直接开关某个包的接口组。
//    这些路由同样继承 app/api/middleware.php 的应用级中间件；认证等按组决策的
//    中间件由各包在自己的 web.groups.*.middleware 中声明。
RouteRegistrar::applyRoutes(ExtPoints::ROUTE_API);

// ② 后台管理端 App API（/api/admin/*，JWT）
//
// 【App 权限约定】—— 改动本段前必读
//   1. ident 一律复用后台权限节点（菜单 path → path_to_dot，见 config/menu_permission.php，
//      如 /admin/admin/index → admin.admin.index）。不新建 api.* 节点，不新增表/字段：
//      App 与 Web 共用同一套 auth_permissions + 角色 + 继承，授权一次两边生效。
//   2. 业务接口必须挂 ->middleware([[AdminApiPermission::class, '<ident>']])，
//      且**每个接口只能挂一次**。组级再挂一次会恒 403：路由分组中间件先于路由中间件
//      执行（think\route\Rule::getOption() 的 array_merge 顺序），组级实例拿不到 ident，
//      在 fail_closed=true 下直接拒绝。
//   3. A 组仅限登录态通用接口（me / menus / refresh / logout）；业务读写一律进 B 组。
//   4. config('admin_api.fail_closed') 只兜「挂了中间件但没写 ident」，
//      **不兜**「忘挂中间件」——业务接口误放 A 组等于只验登录。
//   5. 发布前跑 `php think api:perm-check --strict` 对账 ident 是否存在于权限表且已被角色授予。
if ((bool) config('admin_api.enable', true)) {
    Route::group('admin', function (): void {
        // 登录：无需 token；限流规则见 config/wise-api.php → rate_limit.groups['/admin/login']
        Route::post('login', 'app\api\controller\admin\Auth@login');

        // 2FA 第二步：只验签（pending token 不能访问业务接口，故不挂 AdminApiAuth）
        // 注意：TP 的中间件参数必须是数组，字符串会被展开成独立中间件名
        Route::post('2fa', 'app\api\controller\admin\Auth@verify2fa')
            ->middleware([[JwtAuthMiddleware::class, ['admin']]]);

        // ── A 组：登录态通用接口（只验身份，不验权限）──────────────────────────
        // 判据：返回的是「我能做什么」（me / menus），或纯会话维护（refresh / logout）。
        // 这类接口没有对应的业务菜单，因此不存在可授权的权限节点。
        Route::group(function (): void {
            Route::post('refresh', 'app\api\controller\admin\Auth@refresh');
            Route::post('logout', 'app\api\controller\admin\Auth@logout');
            Route::get('me', 'app\api\controller\admin\Auth@me');
            Route::get('menus', 'app\api\controller\admin\Menu@index');

            // 站内消息：返回的是「当前登录人自己的消息」，与 Web 侧一致只要求登录
            //（config/wise-auth.php 的 authenticated_routes 含 /admin/system_message/*），
            // 越权由 service 层按当前 admin_id 过滤保证。
            Route::get('messages', 'app\api\controller\admin\Message@index');
            Route::get('messages/stats', 'app\api\controller\admin\Message@stats');
            Route::post('messages/read', 'app\api\controller\admin\Message@read');
            Route::post('messages/read-all', 'app\api\controller\admin\Message@readAll');
            Route::delete('messages/:id', 'app\api\controller\admin\Message@destroy')
                ->pattern(['id' => '\d+']);

            // 写会话：敏感写操作前用密码 / TOTP 换短时效 write_token
            Route::post('write-session', 'app\api\controller\admin\Auth@writeSession');
        })->middleware([
            [JwtAuthMiddleware::class, ['admin']],
            AdminApiAuth::class,
        ]);

        // ── B 组：业务接口（必须声明 ident）─────────────────────────────────
        // 组级只挂 JwtAuth + AdminApiAuth；ident 一律声明在各自路由上，一个接口一处。
        // 新增接口照此追加：ident 取后台已存在的节点，写完跑 `php think api:perm-check`。
        Route::group(function (): void {
            // 用户管理列表（ident 与后台「用户管理」菜单派生节点同源）
            Route::get('admins', 'app\api\controller\admin\Admin@index')
                ->middleware([[AdminApiPermission::class, 'admin.admin.index']]);

            // ── 用户写操作（批次 4）───────────────────────────────────────
            // 除权限 ident 外，额外挂 AdminApiWriteGuard：必须带有效 X-Write-Token
            //（由 POST /api/admin/write-session 换取），并在成功后写审计日志。
            Route::post('admins', 'app\api\controller\admin\Admin@save')
                ->middleware([
                    [AdminApiPermission::class, 'admin.admin.save'],
                    AdminApiWriteGuard::class,
                ]);
            Route::put('admins/:id', 'app\api\controller\admin\Admin@update')
                ->pattern(['id' => '\d+'])
                ->middleware([
                    [AdminApiPermission::class, 'admin.admin.update'],
                    AdminApiWriteGuard::class,
                ]);
            Route::post('admins/:id/reset-password', 'app\api\controller\admin\Admin@resetPassword')
                ->pattern(['id' => '\d+'])
                ->middleware([
                    [AdminApiPermission::class, 'admin.admin.reset_password'],
                    AdminApiWriteGuard::class,
                ]);
            Route::post('admins/:id/roles', 'app\api\controller\admin\Admin@assignRole')
                ->pattern(['id' => '\d+'])
                ->middleware([
                    [AdminApiPermission::class, 'admin.admin.assign_role'],
                    AdminApiWriteGuard::class,
                ]);
            Route::post('admins/:id/status', 'app\api\controller\admin\Admin@status')
                ->pattern(['id' => '\d+'])
                ->middleware([
                    [AdminApiPermission::class, 'admin.admin.status'],
                    AdminApiWriteGuard::class,
                ]);

            // 工作台：布局 + 面板目录 + 快捷入口 / 面板数据
            Route::get('dashboard', 'app\api\controller\admin\Dashboard@index')
                ->middleware([[AdminApiPermission::class, 'admin.dashboard.index']]);
            Route::get('dashboard/widgets', 'app\api\controller\admin\Dashboard@widgets')
                ->middleware([[AdminApiPermission::class, 'admin.dashboard.index']]);

            // 任务中心：列表 / 进度 / 详情 / 取消 / 重试
            // 注意 progress 必须先于 :id 注册，避免被当成任务 ID
            Route::get('tasks', 'app\api\controller\admin\Task@index')
                ->middleware([[AdminApiPermission::class, 'admin.async_task.getlist']]);
            Route::get('tasks/progress', 'app\api\controller\admin\Task@progress')
                ->middleware([[AdminApiPermission::class, 'admin.async_task.progress']]);
            Route::get('tasks/:id', 'app\api\controller\admin\Task@show')
                ->pattern(['id' => '\d+'])
                ->middleware([[AdminApiPermission::class, 'admin.async_task.detail']]);
            Route::post('tasks/:id/cancel', 'app\api\controller\admin\Task@cancel')
                ->pattern(['id' => '\d+'])
                ->middleware([[AdminApiPermission::class, 'admin.async_task.cancel']]);
            Route::post('tasks/:id/retry', 'app\api\controller\admin\Task@retry')
                ->pattern(['id' => '\d+'])
                ->middleware([[AdminApiPermission::class, 'admin.async_task.retry']]);

            // 日志查询（全部只读）
            Route::get('logs/operation', 'app\api\controller\admin\Log@operation')
                ->middleware([[AdminApiPermission::class, 'admin.operation_log.get_list']]);
            Route::get('logs/login', 'app\api\controller\admin\Log@login')
                ->middleware([[AdminApiPermission::class, 'admin.login_log.index']]);
            Route::get('logs/api', 'app\api\controller\admin\Log@api')
                ->middleware([[AdminApiPermission::class, 'admin.api_log.get_list']]);

            // ── 附件（只读；上传/删除缺权限节点，暂不开放）───────────────
            // 静态子路径必须先于 :id 注册，否则 'categories' 会被当成 ID
            Route::get('attachments/categories', 'app\api\controller\admin\Attachment@categories')
                ->middleware([[AdminApiPermission::class, 'admin.attachment_category.index']]);
            Route::get('attachments', 'app\api\controller\admin\Attachment@index')
                ->middleware([[AdminApiPermission::class, 'admin.attachment.index']]);
            Route::get('attachments/:id', 'app\api\controller\admin\Attachment@detail')
                ->pattern(['id' => '\d+'])
                ->middleware([[AdminApiPermission::class, 'admin.attachment.index']]);

            // ── 字典（只读）─────────────────────────────────────────────
            Route::get('dicts/:code/items', 'app\api\controller\admin\Dict@items')
                ->pattern(['code' => '[A-Za-z0-9_\-\.]+'])
                ->middleware([[AdminApiPermission::class, 'admin.wise_dict.items']]);
            Route::get('dicts/:code', 'app\api\controller\admin\Dict@show')
                ->pattern(['code' => '[A-Za-z0-9_\-\.]+'])
                ->middleware([[AdminApiPermission::class, 'admin.wise_dict.detail']]);
            Route::get('dicts', 'app\api\controller\admin\Dict@index')
                ->middleware([[AdminApiPermission::class, 'admin.wise_dict.list']]);

            // ── 角色（只读）─────────────────────────────────────────────
            Route::get('roles/tree', 'app\api\controller\admin\Role@tree')
                ->middleware([[AdminApiPermission::class, 'admin.role.getlist']]);
            Route::get('roles', 'app\api\controller\admin\Role@index')
                ->middleware([[AdminApiPermission::class, 'admin.role.getlist']]);
            Route::get('roles/:id', 'app\api\controller\admin\Role@detail')
                ->pattern(['id' => '\d+'])
                ->middleware([[AdminApiPermission::class, 'admin.role.getlist']]);

            // ── 会员（只读）─────────────────────────────────────────────
            // 直接复用 wise-member 的 adminapi 控制器：其基类自包含（不依赖 Session，
            // 身份由本应用的 AdminApiAuth 注入），输出 {code,msg,data} 与本应用一致。
            // 包未安装时跳过注册，避免命中后 500。
            if (class_exists(\wise\member\controller\adminapi\Level::class)) {
                Route::get('member/levels', 'wise\member\controller\adminapi\Level@index')
                    ->middleware([[AdminApiPermission::class, 'admin.member.level.index']]);
                Route::get('member/tags', 'wise\member\controller\adminapi\Tag@index')
                    ->middleware([[AdminApiPermission::class, 'admin.member.tag.index']]);
                Route::get('member/statistics/overview', 'wise\member\controller\adminapi\Statistic@overview')
                    ->middleware([[AdminApiPermission::class, 'admin.member.statistic.index']]);
            }
        })->middleware([
            [JwtAuthMiddleware::class, ['admin']],
            AdminApiAuth::class,
        ]);
    });
}

// ③ 插件 / Bundle 统一兜底入口：:plugin[/v{N}][/controller[/action]] → ApiDispatch
//    该入口要求 JWT（其认证不在应用级中间件里），公开接口需声明自己的路由组。
Route::group(function () {
    Route::any('/:plugin/[:version]/[:controller]/[:action]', ApiDispatch::class)
        ->pattern([
            'plugin' => '[a-z][a-z0-9_-]*',
        ]);
})->middleware([
    [\wise\jwt\middleware\JwtAuth::class, ['app', 'bearer']],
]);
