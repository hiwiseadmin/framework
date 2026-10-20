<?php

declare(strict_types=1);

namespace wise\plugin\command\lifecycle;

use think\console\input\Option;
use wise\plugin\PluginManager;
// use wise\plugin\composer_bridge\ComposerEngine;
// use wise\plugin\composer_bridge\PluginComposerInstaller;

/**
 * PluginInstall 命令从市场或本地目录安装插件。
 *
 * 用法：
 *   php think plugin:install <name>
 *   // php think plugin:install <name> -c                # 以 Composer 引擎安装，包名推导为 wiseadmin/<name>
 *   // php think plugin:install <name> -c=wiseadmin/blog -v=^1.2
 */
class PluginInstall extends PluginLifecycleCommand
{
    /**
     * 配置命令
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('plugin:install')
            ->setDescription('安装插件')
            ->addArgument('name', \think\console\input\Argument::REQUIRED, '插件标识或 composer 包名');
            // ->addOption('composer', 'c', Option::VALUE_OPTIONAL, '以 Composer 引擎安装，值为 composer 包名（省略则按 wiseadmin/<name> 推导）', null)
            // ->addOption('version', 'v', Option::VALUE_OPTIONAL, '指定版本约束，如 ^1.2', '');
    }

    protected function getActionName(): string
    {
        return '安装';
    }

    protected function executeAction(PluginManager $manager, string $name): void
    {
        // $composerPackage = $this->input->getOption('composer');
        // $version = (string) $this->input->getOption('version');

        // // Composer 引擎模式：装前冲突预检 + composer require + 框架集成
        // if ($composerPackage !== null) {
        //     $package = $composerPackage === '' ? ('wiseadmin/' . $name) : $composerPackage;
        //     $engine = new ComposerEngine();
        //     $installer = new PluginComposerInstaller($engine, $manager);
        //     $installer->install($package, $version, $name);
        //     return;
        // }

        $manager->install($name);
    }
}
