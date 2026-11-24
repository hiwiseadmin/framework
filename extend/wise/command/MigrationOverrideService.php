<?php

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
