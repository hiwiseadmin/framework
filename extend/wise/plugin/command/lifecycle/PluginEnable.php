<?php

declare(strict_types=1);

namespace wise\plugin\command\lifecycle;

use wise\plugin\PluginManager;

/**
 * PluginEnable 命令用于启用已安装的插件。
 *
 * 用法：php think wise:plugin:enable <name>
 */
class PluginEnable extends PluginLifecycleCommand
{
    /**
     * 配置命令
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('plugin:enable')
            ->setDescription('启用插件')
            ->addArgument('name');
    }

    protected function getActionName(): string
    {
        return '启用';
    }

    protected function executeAction(PluginManager $manager, string $name): void
    {
        $manager->enable($name);
    }
}
