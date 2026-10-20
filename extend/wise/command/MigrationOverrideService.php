<?php
// +----------------------------------------------------------------------
// | WiseAdmin 迁移命令覆盖服务（框架级命令，位于 wise\command 命名空间）
// +----------------------------------------------------------------------
// | 通过 Service::commands() 注册 WiseMigrate* 覆盖 think-migration 原生命令。
// |
// | 加载/注册顺序依赖（关键）：
// |   think-migration 的原生命令在其 Service::boot() 中注册，而 boot() 阶段
// |   晚于所有 Service::register() 阶段。若本服务在 register() 中注册命令，
// |   回调会先于原生命令执行，覆盖将失效。
// |   故本服务必须在 boot() 中注册命令，并保证自身在服务注册序列中位于
// |   think-migration\Service 之后（composer.json post-autoload-dump 会将其
// |   追加到 vendor/services.php 末尾，见 MigrationRegister）。
// +----------------------------------------------------------------------

namespace wise\command;

use think\Service;
use wise\command\migrate\WiseMigrateRun;
use wise\command\migrate\WiseMigrateRollback;
use wise\command\migrate\WiseMigrateBreakpoint;
use wise\command\migrate\WiseMigrateStatus;
use wise\command\migrate\WiseMigrateCreate;

class MigrationOverrideService extends Service
{
    /**
     * 在 boot 阶段注册命令（此时 think-migration 的原生命令已注册，本服务最后注册以覆盖）
     */
    public function boot(): void
    {
        $this->commands([
            WiseMigrateRun::class,
            WiseMigrateRollback::class,
            WiseMigrateBreakpoint::class,
            WiseMigrateStatus::class,
            WiseMigrateCreate::class,
        ]);
    }
}
