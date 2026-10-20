<?php

declare(strict_types=1);

namespace wise\plugin\command\lifecycle;

use wise\plugin\PluginManager;

/**
 * PluginDisable 命令用于禁用已启用的插件。
 *
 * 用法：php think wise:plugin:disable <name>
 */
class PluginDisable extends PluginLifecycleCommand
{
    /**
     * 配置命令
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('plugin:disable')
            ->setDescription('禁用插件')
            ->addArgument('name');
    }

    protected function getActionName(): string
    {
        return '禁用';
    }

    protected function executeAction(PluginManager $manager, string $name): void
    {
        $manager->disable($name);
    }
}
