<?php
declare(strict_types=1);

namespace app\admin\controller\traits;

use wise\config\ConfigManager;
use \wise\config\manager\TagManager;

/**
 * WiseConfig 系列控制器的共享基础设施
 *
 * 提供 ConfigManager 和 TagManager 的懒加载初始化，以及公共工具方法。
 */
trait WiseConfigTrait
{
    /** @var ConfigManager|null */
    protected $configManager;

    /** @var \wise\config\manager\TagManager|null */
    protected $tagManager = null;

    /**
     * 初始化 ConfigManager 和 TagManager
     */
    protected function initWiseConfig(): void
    {
        $this->configManager = app(ConfigManager::class);
        $this->tagManager = app(TagManager::class);
    }
}
