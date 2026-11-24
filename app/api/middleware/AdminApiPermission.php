<?php
declare(strict_types=1);

namespace app\api\middleware;

use app\admin\model\Admin;
use Closure;
use think\Request;
use think\Response;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;
use wise\auth\attribute\AuthPermission;
use wise\auth\model\Permission;
use wise\auth\service\RbacManager;

/**
 * 后台 App API 权限中间件（RBAC ident 校验）
 *
 * 必须在 AdminApiAuth 之后执行（依赖其注入的 $request->admin）。
 *
 * 权限标识来源（按优先级）：
 *   1. 路由中间件参数：
 *        ->middleware([AdminApiPermission::class, 'admin.user.list'])
 *        ->middleware([AdminApiPermission::class, ['admin.user.list', 'admin.user.edit']])
 *   2. 控制器方法上的 #[AuthPermission(ident: 'admin.user.list')]
 *      —— 与权限声明同源，避免「代码一处、路由一处」两处维护。
 *
 * 都取不到时按 config('admin_api.fail_closed') 处理：
 *   true  → 403（默认，防止新增接口忘记声明权限）
 *   false → 仅要求登录
 *
 * 检查模式：config('admin_api.check_type') = or（默认）/ and。
 * 超管由 RbacManager::checkAny/checkAll 内部直接放行。
 *
 * 拒绝时响应体带 data.reason，供 App 与排障区分两类失败：
 *   - ident_missing：声明的 ident 不在 auth_permissions（节点从未创建 / 拼写错误），
 *     后台无法授权 → 除超管外恒 403；
 *   - not_granted  ：节点存在，但当前账号的角色未被授予。
 */
class AdminApiPermission
{
    public function __construct(protected ResponseWrapper $wrapper)
    {
    }

    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $admin = $request->admin ?? null;
        if (!$admin instanceof Admin) {
            return $this->wrapper->error('未认证', [], ErrorCode::UNAUTHORIZED);
        }

        $idents = $this->resolveIdents($request, $permissions);

        if ($idents === []) {
            if ((bool) config('admin_api.fail_closed', true)) {
                return $this->wrapper->error('接口未声明权限标识', [], ErrorCode::FORBIDDEN);
            }

            return $next($request);
        }

        $rbac = app(RbacManager::class);

        $passed = config('admin_api.check_type', 'or') === 'and'
            ? $rbac->checkAll($admin->id, 'admin', $idents)
            : $rbac->checkAny($admin->id, 'admin', $idents);

        if (!$passed) {
            return $this->denyForPermissions($idents);
        }

        return $next($request);
    }

    /**
     * 拒绝响应：区分「节点不存在」与「未被授权」
     *
     * 两者对调用方都是 403，但排障路径完全不同：
     *   - ident_missing：路由里写了库里不存在的 ident。后台角色授权页根本看不到
     *     该节点 → 任何角色都无法被授予 → 除超管外**恒 403**，属配置/拼写错误；
     *   - not_granted：节点存在，只是当前账号的角色没被授予 → 去角色授权页勾选即可。
     *
     * 存在性校验只在拒绝分支执行：正常放行的请求不承担这次查询。
     *
     * @param string[] $idents
     */
    protected function denyForPermissions(array $idents): Response
    {
        $missing = $this->missingIdents($idents);

        if ($missing !== []) {
            $this->traceMissing($missing);

            return $this->wrapper->error(
                '接口权限节点不存在：' . implode(', ', $missing),
                [
                    'permissions' => $idents,
                    'reason'      => 'ident_missing',
                    'missing'     => $missing,
                ],
                ErrorCode::FORBIDDEN
            );
        }

        return $this->wrapper->error(
            '无权限访问：' . implode(', ', $idents),
            ['permissions' => $idents, 'reason' => 'not_granted'],
            ErrorCode::FORBIDDEN
        );
    }

    /**
     * 找出声明了但权限表中不存在的 ident
     *
     * 通配符 ident（如 admin.admin.*）无法逐个校验存在性，直接跳过；
     * 权限表不可用时按「都存在」处理（返回 not_granted），避免鉴权语义被查询异常改变。
     *
     * @param  string[] $idents
     * @return string[]
     */
    protected function missingIdents(array $idents): array
    {
        $checkable = array_values(array_filter(
            $idents,
            static fn (string $ident): bool => !str_contains($ident, '*')
        ));

        if ($checkable === []) {
            return [];
        }

        try {
            $existing = Permission::whereIn('ident', $checkable)
                ->where('status', 1)
                ->column('ident');
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_diff($checkable, array_map('strval', $existing)));
    }

    /**
     * 节点缺失时留痕（开发期最容易被忽略的一类配置错误）
     *
     * @param string[] $missing
     */
    protected function traceMissing(array $missing): void
    {
        if (!function_exists('trace')) {
            return;
        }

        trace(
            '[admin-api] 权限节点不存在：' . implode(', ', $missing)
            . '（检查路由 ident 是否与后台菜单派生节点一致；可跑 php think api:perm-check）',
            'warning'
        );
    }

    /**
     * 解析所需权限标识
     *
     * @param Request $request
     * @param array   $permissions 路由中间件参数（字符串或字符串数组）
     * @return string[]
     */
    protected function resolveIdents(Request $request, array $permissions): array
    {
        $idents = [];

        foreach ($permissions as $permission) {
            foreach ((array) $permission as $item) {
                foreach (explode(',', (string) $item) as $ident) {
                    $ident = trim($ident);
                    if ($ident !== '') {
                        $idents[] = $ident;
                    }
                }
            }
        }

        if ($idents !== []) {
            return array_values(array_unique($idents));
        }

        return $this->attributeIdents($request);
    }

    /**
     * 读取控制器方法上的 #[AuthPermission]
     *
     * 路由中间件执行阶段 controller/action 是否已写入依赖 TP 版本，
     * 因此这里全部 try/catch 兜底：取不到就返回空（交给 fail_closed 策略）。
     *
     * @return string[]
     */
    protected function attributeIdents(Request $request): array
    {
        try {
            $class  = $this->resolveControllerClass($request);
            $action = (string) $request->action();

            if ($class === null || $action === '' || !method_exists($class, $action)) {
                return [];
            }

            $idents = [];
            foreach ((new \ReflectionMethod($class, $action))->getAttributes(AuthPermission::class) as $attribute) {
                $instance = $attribute->newInstance();
                if ($instance->ident !== null && $instance->ident !== '') {
                    $idents[] = $instance->ident;
                }
            }

            return array_values(array_unique($idents));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 解析当前请求的控制器类名
     */
    protected function resolveControllerClass(Request $request): ?string
    {
        $candidates = [];

        $controller = (string) $request->controller();
        if ($controller !== '') {
            $candidates[] = $controller;
            $candidates[] = str_replace('.', '\\', $controller);
        }

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }

            $candidate = ltrim($candidate, '\\');
            foreach ([
                'app\\api\\controller\\' . $candidate,
                'app\\api\\controller\\' . $candidate . 'Controller',
            ] as $fqcn) {
                if (class_exists($fqcn)) {
                    return $fqcn;
                }
            }
        }

        return null;
    }
}
