<?php
namespace plugin\wisecms;

use plugin\BasePlugin;

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

    public function enable(): bool
    {
        return true;
    }

    public function disable(): bool
    {
        return true;
    }

    public function getConfig(): array
    {
        return [];
    }
}
