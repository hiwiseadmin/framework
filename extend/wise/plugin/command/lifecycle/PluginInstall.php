<?php

declare(strict_types=1);

namespace wise\plugin\command\lifecycle;

use think\console\input\Argument;
use think\console\input\Option;
use wise\plugin\lifecycle\SeedPolicy;
use wise\plugin\PluginManager;

/**
 * PluginInstall 命令从市场或本地目录安装插件。
 *
 * 数据填充按 manifest `seed` 声明执行（见 {@see SeedPolicy}）：
 *   - `required`（缺省）：装完即执行，失败阻断；
 *   - `demo`：默认跳过，加 `--with-demo` 才写入演示数据；
 *   - `none`：不执行。
 *
 * 用法：
 *   php think plugin:install <name>                # 只装基线数据（demo 插件保持空库）
 *   php think plugin:install <name> --with-demo    # 额外写入演示数据（看效果用）
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
            ->addArgument('name', Argument::REQUIRED, '插件标识')
            ->addOption(
                'with-demo',
                null,
                Option::VALUE_NONE,
                '安装后写入演示数据（只对 manifest 声明 seed="demo" 的插件生效；失败不影响安装）'
            )
            ->addOption(
                'ignore-compat',
                null,
                Option::VALUE_NONE,
                '忽略 manifest compatible_with 声明强行安装（仅应急；声明不兼容时默认拒绝）'
            );
    }

    protected function getActionName(): string
    {
        return '安装';
    }

    protected function executeAction(PluginManager $manager, string $name): void
    {
        $withDemo     = (bool) $this->input->getOption('with-demo');
        $ignoreCompat = (bool) $this->input->getOption('ignore-compat');

        $manager->install($name, $withDemo, $ignoreCompat);

        if ($withDemo) {
            $this->output->writeln(
                '  已请求 --with-demo：仅 manifest 声明 seed="demo" 的插件会写入演示数据（required/none 不受影响）。'
            );
        }

        if ($ignoreCompat) {
            $this->output->writeln('  已按 --ignore-compat 跳过框架兼容检查（风险自负）。');
        }
    }
}
