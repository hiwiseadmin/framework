<?php
declare(strict_types=1);

namespace plugin\pipe_game;

use plugin\BasePlugin;

/**
 * 水管工小游戏插件主类
 */
class Plugin extends BasePlugin
{
    public function install(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }
}
