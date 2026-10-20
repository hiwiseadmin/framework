<?php
declare (strict_types = 1);

namespace app;

use think\Service;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\auth\SessionGuard;
use wise\support\auth\TokenGuard;
use wise\support\context\RequestContext;
use app\common\dashboard\DashboardRegistry;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        // 绑定 ExtPoint 活跃覆盖表的持久化存储（能力市场「设为活跃/停用」跨请求生效）
        // 通过回调注入，保持 wise-support 包层不依赖具体配置存储实现；
        // 后续如需更换持久化方案，仅需替换 CapabilityOverrideService 的实现。
        ExtPoint::bindOverrideStore(
            [\app\common\service\CapabilityOverrideService::class, 'load'],
            [\app\common\service\CapabilityOverrideService::class, 'save']
        );

        // 授权「变更安全相关扩展点的活跃实现」（auth.guard / jwt.provider /
        // connector / exception.renderer）：只有已登录的后台管理员可以切换，
        // 未绑定授权器时这些点的活跃变更会被 ExtPoint 直接拒绝（fail-closed）。
        // 闭包在调用时求值，因此 CLI / 队列等无请求上下文时按「未授权」处理。
        ExtPoint::bindOverrideAuthorizer(function (string $point, string $name, bool $active): bool {
            unset($point, $name, $active);

            return (bool) app('auth')->user('admin');
        });

        // 将框架事件系统桥接为 ExtPoint 的事件派发器：各包可用标准
        // Event::listen(CapabilityToggled::class, ...) 订阅能力启停
        // （如会员能力显隐审计），而 ExtPoint 保持零依赖（未绑定时静默不派发，
        // CLI / 测试等无事件系统的场景安全）。
        ExtPoint::bindEventDispatcher(static function (object $event): void {
            \think\facade\Event::trigger($event);
        });

        // 阶段 0.2：为 wise-workflow 的权限判定点绑定一个「最小可用」实现。
        //
        // 背景：包按容器标识 'permission' 查找权限服务，取不到时 CheckPermissionPipe
        //   会「静默放行」（fail-open）——即只要没绑定，权限工序就等于空操作。
        //   这里先绑定 AuditOnlyPermission（默认拒绝 + 逐条记录），让判定真正发生，
        //   并用日志产出「转换 × 调用方」清单；真实 RBAC 实现稍后替换本绑定即可，
        //   包侧无需任何改动。
        //
        // 回滚：注释掉本绑定即恢复原状（注意：权限工序会立刻变回 fail-open）。
        // 应急：临时设 WISE_WORKFLOW_PERMISSION_MODE=allow 可放行而不改代码。
        //
        // @see docs/security-audit-skill/wise-workflow/run-1/FIX-PLAN.md 阶段 0
        $this->app->bind('permission', \app\common\service\AuditOnlyPermission::class);
    }

    public function boot()
    {
        // 注册 Dashboard 系统内置数据面板（注册表数据，延迟到实例化才触库）
        DashboardRegistry::boot();

        // 为 wise-login-security 的 TwoFactorMiddleware 绑定用户解析器
        // 本项目使用 Session 认证（非 auth() guard），需手动提供用户解析逻辑
        $this->app->bind('wise_login_security.user_resolver', function (Request $request) {
            $userId = auth_id('admin');
            if (!$userId) {
                return null;
            }
            return \app\admin\model\Admin::find($userId);
        });

        // Phase 2.2/2.3: 注册认证 Guard 到统一扩展点
        //
        // 这里属于应用层，负责告诉框架「用什么模型、从哪里获取用户」。
        // 包（wise-support）提供 SessionGuard/TokenGuard 工具，
        // 应用（app/AppService）注入具体的用户解析逻辑。
        //
        // 其他 wise-* 包使用 Auth::user('admin') / Auth::user('api') / Auth::user('member')
        // 时，统一通过 AuthManager → ExtPoint → 此处注册的 Guard。
        $this->registerAuthGuards();

        // 每请求开始：将能力市场的活跃覆盖表重新应用到 ExtPoint 注册表，
        // 保证 Swoole/Workerman 常驻多 worker 下「设为活跃/停用」跨请求、跨 worker 生效。
        $this->app->event->listen(\think\event\HttpRun::class, function () {
            ExtPoint::applyPersistedOverrides();
        });

        // 请求结束时清除 RequestContext，防止 Swoole/Workerman 常驻进程上下文泄漏。
        // FPM 每请求独立进程，不清理也无问题，但为兼容常驻模式统一补齐。
        $this->app->event->listen(\think\event\HttpEnd::class, function () {
            RequestContext::clear();
        });
    }

    /**
     * 注册应用级认证 Guard 到 ExtPoint 注册中心
     *
     * 放在应用层而非包层的原因：
     *  - 包（wise-auth/wise-api）不知道具体的用户模型
     *  - 只有应用层知道 Admin 模型、Member 模型存在何处
     *  - 不同项目可替换 userResolver 对接不同的用户模型
     */
    protected function registerAuthGuards(): void
    {
        // ── admin guard — 后台管理员（Session 驱动）──
        ExtPoint::register(
            ExtPoints::AUTH_GUARD,
            'admin',
            function () {
                $guard = new SessionGuard('admin');
                $guard->setUserResolver(function ($id) {
                    return \app\admin\model\Admin::find($id);
                });
                return $guard;
            },
            10, // 最高优先级
        );

        // ── api guard — API 用户（JWT Bearer Token 驱动）──
        ExtPoint::register(
            ExtPoints::AUTH_GUARD,
            'api',
            function () {
                $guard = new TokenGuard('api');
                $guard->setUserResolver(function (array $payload) {
                    $userId = $payload['sub'] ?? null;
                    if ($userId === null) {
                        return null;
                    }
                    // 默认从 payload 构造用户对象
                    // 具体项目可替换此回调对接实际 API 用户模型
                    return (object) [
                        'id'  => $userId,
                        'jti' => $payload['jti'] ?? null,
                    ];
                });
                return $guard;
            },
            20,
        );

        // ── member guard — 前台会员（Session 驱动）──
        ExtPoint::register(
            ExtPoints::AUTH_GUARD,
            'member',
            function () {
                $guard = new SessionGuard('member');
                $guard->setUserResolver(function ($id) {
                    if (class_exists(\wise\member\model\Member::class)) {
                        return \wise\member\model\Member::find($id);
                    }
                    return (object) ['id' => (int) $id];
                });
                return $guard;
            },
            30,
        );
    }

}
