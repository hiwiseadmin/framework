<?php

declare(strict_types=1);

namespace wise\plugin\command\lifecycle;

use think\console\input\Argument;
use think\console\input\Option;
use wise\plugin\exception\PluginInstallException;
use wise\plugin\PluginManager;

/**
 * PluginReinstall 命令用于重装插件，分两种模式：
 *
 *   - 默认（修复）：repairInstallState()，保留数据表与业务数据，仅补齐安装状态/菜单权限/配置默认值。
 *     适用于「表结构完好，只是状态键丢了」这类分裂态（列表会显示「数据残留·需修复」）。
 *   - --force（清库重装）：purgeResidual() 清空数据表与迁移记录后走 install() 完整重建，
 *     适用于表结构已被改坏、必须重建的场景；**会删除该插件全部业务数据**。
 *
 * 用法：
 *   php think plugin:reinstall <name>
 *   php think plugin:reinstall <name> --force
 */
class PluginReinstall extends PluginLifecycleCommand
{
    protected function configure(): void
    {
        $this->setName('plugin:reinstall')
            ->setDescription('重装插件（默认修复安装状态，--force 清库重装）')
            ->addArgument('name', Argument::REQUIRED, '插件名称')
            ->addOption('force', 'f', Option::VALUE_NONE, '清库重装：先删除该插件全部数据表与迁移记录，再完整安装（会删除业务数据）');
    }

    protected function getActionName(): string
    {
        return '重装';
    }

    protected function executeAction(PluginManager $manager, string $name): void
    {
        if (!$this->input->getOption('force')) {
            $result   = $manager->repairInstallState($name);
            $residual = (array) ($result['residual_tables'] ?? []);

            if ($this->output !== null) {
                $this->output->writeln(sprintf('  已修复安装状态（保留 %d 张数据表，业务数据未动）。', count($residual)));
            }

            return;
        }

        // --force：先清库，再完整安装（对齐后台 reinstall 的 force 分支）
        $purge    = $manager->purgeResidual($name);
        $residual = (array) ($purge['residual_tables'] ?? []);

        if ($residual !== []) {
            throw new PluginInstallException(sprintf(
                '清库未完成，仍有 %d 张表未删除：%s',
                count($residual),
                implode('、', $residual)
            ));
        }

        $manager->install($name);

        if ($this->output !== null) {
            $this->output->writeln('  已清库重装完成（业务数据已删除）。');
        }
    }
}
