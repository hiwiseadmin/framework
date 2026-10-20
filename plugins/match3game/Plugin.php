<?php
declare(strict_types=1);

namespace plugin\match3game;

use plugin\BasePlugin;

/**
 * 消消乐小游戏插件主类
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
