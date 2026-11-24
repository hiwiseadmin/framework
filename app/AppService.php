<?php
declare (strict_types = 1);

namespace app;

use think\Service;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\auth\SessionGuard;
use wise\support\auth\TokenGuard;
use wise\support\context\RequestContext;
use wise\support\context\RequestStateRegistry;
use app\common\dashboard\DashboardRegistry;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        ExtPoint::bindOverrideStore(
            [\app\common\service\CapabilityOverrideService::class, 'load'],
            [\app\common\service\CapabilityOverrideService::class, 'save']
        );

        ExtPoint::bindOverrideAuthorizer(function (string $point, string $name, bool $active): bool {
            unset($point, $name, $active);

            return (bool) app('auth')->user('admin');
        });

        ExtPoint::bindEventDispatcher(static function (object $event): void {
            \think\facade\Event::trigger($event);
        });

        $this->app->bind('permission', \app\common\service\AuditOnlyPermission::class);
    }

    public function boot()
    {
        DashboardRegistry::boot();

        ExtPoint::register(
            ExtPoints::MENU_ITEM,
            'devtools',
            static fn (): \app\admin\service\DevToolMenuProvider => new \app\admin\service\DevToolMenuProvider()
        );

        $this->app->bind('wise_login_security.user_resolver', function (Request $request) {
            $userId = auth_id('admin');
            if (!$userId) {
                return null;
            }
            return \app\admin\model\Admin::find($userId);
        });

        $this->registerAuthGuards();

        $this->registerQueueHandlers();

        $this->registerRecycleProviders();

        $this->registerChangeLogSink();

        $this->app->event->listen(\think\event\HttpRun::class, function () {
            ExtPoint::applyPersistedOverrides();
        });

        $this->app->event->listen(\think\event\HttpEnd::class, function () {
            RequestContext::clear();
        });

        RequestStateRegistry::register(
            'slot-manager',
            \app\common\resetter\SlotManagerStateResetter::class,
            150
        );

        $this->guardMigrationCommands();
    }

    /**
     * CLI 守卫：多路径迁移命令是否仍被注册。
     *
     * 背景：`migrate:*` 的多路径扫描能力由 `wise\command\MigrationOverrideService` 提供，
     * 它必须排在 `vendor/services.php` 末尾——因为 `App::boot()` 按 `$services` 顺序执行，
     * 只有晚于 think-migration 的 `boot()`，其 `Console::starting()` 回调才会后执行并覆盖原生命令。
     *
     * 风险：`service:discover` 会重写 `vendor/services.php`，把 `plugin:migrate:register`
     * 追加的条目清掉；此时 `migrate:run` 会**静默退回**单路径原生命令，只扫
     * `database/migrations`，`extend/wise/plugin` 与各包 `registerMigrations()` 的路径被跳过，
     * 表现为「输出看起来跑完了，但包的表没建」。
     *
     * composer 的 post-autoload-dump 链已保证两者按序执行，此守卫只针对手工单独跑
     * `service:discover` 的场景，给出一条可见提示而不是让问题静默发生。
     */
    protected function guardMigrationCommands(): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        $app = $this->app;

        \think\Console::starting(function ($console) use ($app): void {

            $servicesFile = $app->getRootPath() . 'vendor' . DIRECTORY_SEPARATOR . 'services.php';
            if (!is_file($servicesFile)) {
                return;
            }

            if (str_contains((string) file_get_contents($servicesFile), 'wise\command\MigrationOverrideService')) {
                return;
            }

            fwrite(STDERR, PHP_EOL
                . '[wise] 警告：多路径迁移命令未注册（vendor/services.php 缺少 MigrationOverrideService）' . PHP_EOL
                . '        migrate:* 当前只扫描 database/migrations，extend/wise/plugin 与各包注册的迁移路径会被跳过。' . PHP_EOL
                . '        修复：php think plugin:migrate:register' . PHP_EOL);
        });
    }

    /**
     * 注册宿主业务 handler（闭包任务）
     *
     * 与 `QueueService::registerBuiltinHandlers()` 的框架内置 handler 区分：
     * 本方法注册的是**业务能力**（导出等），随宿主应用启动注册。
     */
    protected function registerQueueHandlers(): void
    {
        if (!class_exists(\app\common\service\QueueService::class)) {
            return;
        }

        \app\common\service\QueueService::registerHandler(
            \app\common\service\ExportJobService::HANDLER,
            static function (array $data, ?\app\common\job\TaskContext $context = null): array {
                return (new \app\common\service\ExportJobService())->run($data, $context);
            }
        );

        \app\common\service\QueueService::registerHandler(
            \app\common\service\ChangeLogJobService::HANDLER,
            static function (array $data, ?\app\common\job\TaskContext $context = null): array {
                return (new \app\common\service\ChangeLogJobService())->run($data, $context);
            }
        );
    }

    /**
     * 注册字段级变更 sink＼�
     *
     * wise-support 只定义采集与分发，落地实现（表结构 / 脱敏 / 队列 / 回滚）
     * 由宿主提供；未注册时模型侧采集为静默 no-op，包可独立使用不产生副作用。
     */
    protected function registerChangeLogSink(): void
    {
        if (!class_exists(\wise\support\changelog\ChangeLogManager::class)) {
            return;
        }

        if (!(bool) config('change_log.enable', true)) {
            \wise\support\changelog\ChangeLogManager::setEnabled(false);
            return;
        }

        \wise\support\changelog\ChangeLogManager::registerSink(
            new \app\common\service\ChangeLogService()
        );
    }

    /**
     * 注册回收站宿主侧模型＼�
     *
     * 只注册 `config/recycle.php` 里的宿主模型；包/插件在各自 provider 注册。
     * 用闭包而非直接传数组：config 在请求期已合并完成，闭包求值时拿到最终值。
     */
    protected function registerRecycleProviders(): void
    {
        if (!class_exists(ExtPoint::class) || !class_exists(ExtPoints::class)) {
            return;
        }

        ExtPoint::register(
            ExtPoints::RECYCLE_PROVIDER,
            'system',
            static fn (): array => (array) config('recycle.models', [])
        );
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
            10,
        );

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
                    return (object) [
                        'id'  => $userId,
                        'jti' => $payload['jti'] ?? null,
                    ];
                });
                return $guard;
            },
            20,
        );

        ExtPoint::register(
            ExtPoints::AUTH_GUARD,
            'member',
            function () {
                $guard = new SessionGuard('member');
                $guard->setUserResolver(function ($id) {
                    if (class_exists(\plugin\member\model\Member::class)) {
                        return \plugin\member\model\Member::find($id);
                    }
                    return (object) ['id' => (int) $id];
                });
                return $guard;
            },
            30,
        );
    }

}
