<?php

declare(strict_types=1);

namespace wise\plugin\command\lifecycle;

use wise\plugin\PluginManager;

/**
 * PluginUninstall 命令从应用中移除插件。
 *
 * 用法：php think wise:plugin:uninstall <name>
 */
class PluginUninstall extends PluginLifecycleCommand
{
    /**
     * 配置命令
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('plugin:uninstall')
            ->setDescription('卸载插件')
            ->addArgument('name');
    }

    protected function getActionName(): string
    {
        return '卸载';
    }

    protected function executeAction(PluginManager $manager, string $name): void
    {
        // CLI 默认 $removeFiles=true（连同插件源码目录一起删除）；后台卸载按钮传 false（保留目录）。
        $result = $manager->uninstall($name);

        $cleanup = (array) ($result['cleanup'] ?? []);
        if ($this->output !== null) {
            $menusOk = ($cleanup['menus_ok'] ?? true) !== false;
            $permsOk = ($cleanup['permissions_ok'] ?? true) !== false;

            if ($menusOk && $permsOk) {
                // 说清楚语义，避免把"软删可恢复"误读成"没清干净"（按 plugin 直接查库仍能看到这些行）
                $this->output->writeln('  菜单与权限已软删（写 delete_time，可恢复：重装同名插件时自动 restore）');
                $this->output->writeln('  插件源码目录已删除（后台「卸载」按钮只卸载、保留目录）');
            } else {
                $this->output->warning(sprintf(
                    '菜单/权限清理未全部成功（菜单=%s，权限=%s），请复核 ws_menus / ws_auth_permissions，详见 runtime 日志',
                    $menusOk ? 'ok' : 'FAIL',
                    $permsOk ? 'ok' : 'FAIL'
                ));
            }
        }

        $residual = (array) ($result['residual_tables'] ?? []);
        if ($residual === [] || $this->output === null) {
            return;
        }

        // 基类随后会无条件打印「已成功卸载」，这里必须把残留事实说清楚：
        // 否则用户以为库里已经干净，再安装时才撞 1050「数据表已存在」。
        $this->output->warning(sprintf(
            '插件 "%s" 的 %d 张数据表未清理干净：%s',
            $name,
            count($residual),
            implode(', ', $residual)
        ));
        $this->output->writeln('  已在状态中登记为「数据残留·需修复」，插件列表该行会出现「重装」按钮。');
        $this->output->writeln('  处理方式：列表「重装」→「清库重装」；或直接执行下面命令：');
        $this->output->writeln('  排查：php think plugin:migrate:status -p ' . $name);
        $this->output->writeln('  清理：php think plugin:migrate:rollback -p ' . $name . ' --all');
    }
}
