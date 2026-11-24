<?php

use app\api\dispatch\ApiDispatch;
use app\api\middleware\AdminApiAuth;
use app\api\middleware\AdminApiPermission;
use app\api\middleware\AdminApiWriteGuard;
use think\facade\Route;
use wise\api\middleware\JwtAuthMiddleware;
use wise\support\ExtPoints;
use wise\support\router\RouteRegistrar;

RouteRegistrar::applyRoutes(ExtPoints::ROUTE_API);

if ((bool) config('admin_api.enable', true)) {
    Route::group('admin', function (): void {
        Route::post('login', 'app\api\controller\admin\Auth@login');

        Route::post('2fa', 'app\api\controller\admin\Auth@verify2fa')
            ->middleware([[JwtAuthMiddleware::class, ['admin']]]);

        Route::group(function (): void {
            Route::post('refresh', 'app\api\controller\admin\Auth@refresh');
            Route::post('logout', 'app\api\controller\admin\Auth@logout');
            Route::get('me', 'app\api\controller\admin\Auth@me');
            Route::get('menus', 'app\api\controller\admin\Menu@index');

            Route::get('messages', 'app\api\controller\admin\Message@index');
            Route::get('messages/stats', 'app\api\controller\admin\Message@stats');
            Route::post('messages/read', 'app\api\controller\admin\Message@read');
            Route::post('messages/read-all', 'app\api\controller\admin\Message@readAll');
            Route::delete('messages/:id', 'app\api\controller\admin\Message@destroy')
                ->pattern(['id' => '\d+']);

            Route::post('write-session', 'app\api\controller\admin\Auth@writeSession');
        })->middleware([
            [JwtAuthMiddleware::class, ['admin']],
            AdminApiAuth::class,
        ]);

        Route::group(function (): void {
            Route::get('admins', 'app\api\controller\admin\Admin@index')
                ->middleware([[AdminApiPermission::class, 'admin.admin.index']]);

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

            Route::get('dashboard', 'app\api\controller\admin\Dashboard@index')
                ->middleware([[AdminApiPermission::class, 'admin.dashboard.index']]);
            Route::get('dashboard/widgets', 'app\api\controller\admin\Dashboard@widgets')
                ->middleware([[AdminApiPermission::class, 'admin.dashboard.index']]);

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

            Route::get('logs/operation', 'app\api\controller\admin\Log@operation')
                ->middleware([[AdminApiPermission::class, 'admin.operation_log.get_list']]);
            Route::get('logs/login', 'app\api\controller\admin\Log@login')
                ->middleware([[AdminApiPermission::class, 'admin.login_log.index']]);
            Route::get('logs/api', 'app\api\controller\admin\Log@api')
                ->middleware([[AdminApiPermission::class, 'admin.api_log.get_list']]);

            Route::get('attachments/categories', 'app\api\controller\admin\Attachment@categories')
                ->middleware([[AdminApiPermission::class, 'admin.attachment_category.index']]);
            Route::get('attachments', 'app\api\controller\admin\Attachment@index')
                ->middleware([[AdminApiPermission::class, 'admin.attachment.index']]);
            Route::get('attachments/:id', 'app\api\controller\admin\Attachment@detail')
                ->pattern(['id' => '\d+'])
                ->middleware([[AdminApiPermission::class, 'admin.attachment.index']]);

            Route::get('dicts/:code/items', 'app\api\controller\admin\Dict@items')
                ->pattern(['code' => '[A-Za-z0-9_\-\.]+'])
                ->middleware([[AdminApiPermission::class, 'admin.wise_dict.items']]);
            Route::get('dicts/:code', 'app\api\controller\admin\Dict@show')
                ->pattern(['code' => '[A-Za-z0-9_\-\.]+'])
                ->middleware([[AdminApiPermission::class, 'admin.wise_dict.detail']]);
            Route::get('dicts', 'app\api\controller\admin\Dict@index')
                ->middleware([[AdminApiPermission::class, 'admin.wise_dict.list']]);

            Route::get('roles/tree', 'app\api\controller\admin\Role@tree')
                ->middleware([[AdminApiPermission::class, 'admin.role.getlist']]);
            Route::get('roles', 'app\api\controller\admin\Role@index')
                ->middleware([[AdminApiPermission::class, 'admin.role.getlist']]);
            Route::get('roles/:id', 'app\api\controller\admin\Role@detail')
                ->pattern(['id' => '\d+'])
                ->middleware([[AdminApiPermission::class, 'admin.role.getlist']]);

            if (class_exists(\plugin\member\controller\adminapi\Level::class)) {
                Route::get('member/levels', 'plugin\member\controller\adminapi\Level@index')
                    ->middleware([[AdminApiPermission::class, 'admin.member.level.index']]);
                Route::get('member/tags', 'plugin\member\controller\adminapi\Tag@index')
                    ->middleware([[AdminApiPermission::class, 'admin.member.tag.index']]);
                Route::get('member/statistics/overview', 'plugin\member\controller\adminapi\Statistic@overview')
                    ->middleware([[AdminApiPermission::class, 'admin.member.statistic.index']]);
            }
        })->middleware([
            [JwtAuthMiddleware::class, ['admin']],
            AdminApiAuth::class,
        ]);
    });
}

Route::group(function () {
    Route::any('/:plugin/[:version]/[:controller]/[:action]', ApiDispatch::class)
        ->pattern([
            'plugin' => '[a-z][a-z0-9_-]*',
        ]);
})->middleware([
    [\wise\jwt\middleware\JwtAuth::class, ['app', 'bearer']],
]);
