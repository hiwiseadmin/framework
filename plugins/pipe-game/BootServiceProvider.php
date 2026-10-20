<?php
declare(strict_types=1);

namespace plugin\pipe_game;

use wise\plugin\WiseBootServiceProvider;

/**
 * 水管工插件服务提供者
 *
 * 在 boot 阶段加载 wise-game-stage 的 Provider/Validator 注册配置，
 * 使其能被 GameStageServiceProvider::bootProviders/Validators() 校验并注册到容器。
 */
class BootServiceProvider extends WiseBootServiceProvider
{
    public function register(): void
    {
        $this->mergeConfig($this->getPluginIdent());
    }

    public function boot(): void
    {
        parent::boot();
    }
}
