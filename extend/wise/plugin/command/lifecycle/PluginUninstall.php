<?php

declare(strict_types=1);

namespace wise\plugin\command\lifecycle;

use think\console\input\Argument;
use think\console\input\Option;
use wise\plugin\PluginManager;

/**
 * PluginUninstall 命令从应用中移除插件。
 *
 * 两种清理强度：
 *   - 默认（可恢复）：菜单/权限软删（写 delete_time），重装同名插件时自动 restore；
 *     安装目录默认**保留**，便于排查或改完再重装。
 *   - --purge（无痕）：菜单/权限**物理删除**（含角色绑定，重装不会恢复）、残留数据表
 *     强制 DROP 并清空迁移记录、public 下的静态资源一并删除 —— 系统里不留痕迹，
 *     **不可恢复**。
 *
 * 目录安全：只有显式 `--remove-files` 才删源码，且删除前会先打包到根目录
 * `backup/{name}_{Ymd-His}.zip`；备份失败则**不删**，宁可留目录也不丢源码。
 *
 * 用法：
 *   php think plugin:uninstall <name>
 *   php think plugin:uninstall <name> --purge
 *   php think plugin:uninstall <name> --remove-files
 *   php think plugin:uninstall <name> --purge --remove-files
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
            ->setDescription('卸载插件（默认保留安装目录；--purge 彻底清理无痕）')
            ->addArgument('name', Argument::OPTIONAL, '插件标识（plugins 目录名）')
            ->addOption(
                'purge',
                null,
                Option::VALUE_NONE,
                '无痕清理：物理删除菜单/权限及角色绑定、强制删除残留数据表并清空迁移记录、'
                . '删除 public 下的静态资源（不可恢复，重装不会 restore）'
            )
            ->addOption(
                'remove-files',
                null,
                Option::VALUE_NONE,
                '删除插件安装目录 plugins/{name}（默认保留；删除前自动打包到根目录 backup/）'
            );
    }

    protected function getActionName(): string
    {
        return '卸载';
    }

    protected function executeAction(PluginManager $manager, string $name): void
    {
        $purge       = (bool) $this->input->getOption('purge');
        $removeFiles = (bool) $this->input->getOption('remove-files');

        $result = $manager->uninstall($name, $removeFiles, $purge);

        if ($this->output === null) {
            return;
        }

        $cleanup  = (array) ($result['cleanup'] ?? []);
        $menusOk  = ($cleanup['menus_ok'] ?? true) !== false;
        $permsOk  = ($cleanup['permissions_ok'] ?? true) !== false;
        $assetsOk = ($cleanup['assets_ok'] ?? true) !== false;

        if ($menusOk && $permsOk) {
            $this->output->writeln($purge
                ? '  菜单与权限已物理删除（含角色绑定，重装不会恢复）'
                : '  菜单与权限已软删（写 delete_time，可恢复：重装同名插件时自动 restore）');
        } else {
            $this->output->warning(sprintf(
                '菜单/权限清理未全部成功（菜单=%s，权限=%s），请复核 ws_menus / ws_auth_permissions，详见 runtime 日志',
                $menusOk ? 'ok' : 'FAIL',
                $permsOk ? 'ok' : 'FAIL'
            ));
        }

        $this->output->writeln($assetsOk
            ? '  public 静态资源已清理（public/statics/plugins/' . $name . '）'
            : '  public 静态资源未清理干净，请手工删除 public/statics/plugins/' . $name);

        $backupArchive = (string) ($result['backup_archive'] ?? '');
        $filesRemoved  = ($cleanup['files_removed'] ?? false) === true;

        if (!$removeFiles) {
            $this->output->writeln('  插件安装目录已保留（加 --remove-files 可删除，删除前会自动备份到 backup/）');
        } elseif ($filesRemoved) {
            $this->output->writeln($backupArchive !== ''
                ? '  插件安装目录已删除，备份包：' . $this->relativePath($backupArchive)
                : '  插件安装目录已删除（目录原本不存在）');
        } else {
            $this->output->warning(
                '  插件安装目录未删除：备份失败或删除未完成，源码已保留。'
                . '请检查 runtime 日志与根目录 backup/ 是否可写'
            );
        }

        $dropped = (array) ($result['dropped_tables'] ?? []);
        if ($purge && $dropped !== []) {
            $this->output->writeln(sprintf(
                '  已强制删除残留数据表 %d 张：%s',
                count($dropped),
                implode(', ', $dropped)
            ));
        }

        $residual = (array) ($result['residual_tables'] ?? []);
        if ($residual === []) {
            return;
        }

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

        if ($purge) {
            $this->output->writeln('  说明：本次已按插件声明的建表迁移强制 DROP，仍有残留说明表名不在声明内，需人工 DROP。');
        }
    }

    /**
     * 绝对路径转「相对应用根目录」的展示路径（如 backup/demo_20261023-153012.zip）
     *
     * @param string $path 绝对路径
     * @return string
     */
    private function relativePath(string $path): string
    {
        $root = str_replace('\\', '/', rtrim($this->app->getRootPath(), '/\\'));
        $path = str_replace('\\', '/', $path);

        if ($root !== '' && str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        return $path;
    }
}
