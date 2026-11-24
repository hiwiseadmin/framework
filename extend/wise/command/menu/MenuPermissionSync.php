<?php
declare(strict_types=1);

namespace wise\command\menu;

use app\admin\service\PermissionSyncService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 菜单 → 权限同步命令（`menu:permission-sync`）
 *
 * ## 职责单一
 *
 * 只做「menus 表 → auth_permissions」的**下游转换**：
 *   - 不扫描控制器、不创建菜单、不猜方法名与图标；
 *   - 框架菜单由人工/迁移维护，插件菜单由 `plugin:menu-derive` 维护；
 *   - 与网页「权限配置 → 同步权限」共用 {@see PermissionSyncService}，不存在两套逻辑。
 *
 * ## 用法
 *
 * ```bash
 * php think menu:permission-sync                 # 全量同步（幂等）
 * php think menu:permission-sync --dry-run       # 只统计，不写库
 * php think menu:permission-sync --path=/admin/menu
 * php think menu:permission-sync --fix-method    # 用当前配置重算历史权限的 method
 * php think menu:permission-sync --prune         # 回收「菜单已删除、权限仍残留」的节点
 * php think menu:permission-sync --packages      # 顺带同步扩展包/插件声明
 * php think menu:permission-sync --json          # CI 消费
 * ```
 *
 * 退出码：`0` 成功；`1` 有菜单同步失败。
 */
class MenuPermissionSync extends Command
{
    protected function configure()
    {
        $this->setName('menu:permission-sync')
            ->addOption('dry-run', null, Option::VALUE_NONE, '只统计，不写库')
            ->addOption('path', null, Option::VALUE_OPTIONAL, '只处理指定 path 前缀，如 /admin/menu')
            ->addOption('packages', null, Option::VALUE_NONE, '同时同步扩展包/插件声明的权限')
            ->addOption('prune', null, Option::VALUE_NONE, '回收「菜单已软删除、权限仍残留」的权限节点')
            ->addOption('fix-method', null, Option::VALUE_NONE, '用 config/menu_permission.php 覆盖已有权限的 method')
            ->addOption('json', null, Option::VALUE_NONE, 'JSON 输出（CI / 脚本消费）')
            ->setDescription('菜单 → 权限同步（只做转换，不生成菜单）');
    }

    /**
     * @return int 0=成功 1=存在同步失败
     */
    protected function execute(Input $input, Output $output)
    {
        $dryRun = (bool) $input->getOption('dry-run');

        $result = app(PermissionSyncService::class)->sync([
            'menus'      => true,
            'packages'   => (bool) $input->getOption('packages'),
            'dry_run'    => $dryRun,
            'path'       => (string) ($input->getOption('path') ?? ''),
            'prune'      => (bool) $input->getOption('prune'),
            'fix_method' => (bool) $input->getOption('fix-method'),
        ]);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ));
        } else {
            $menus = (array) ($result['menus'] ?? []);
            $prefix = $dryRun ? '[dry-run] ' : '';

            $output->writeln(sprintf(
                '<info>%s菜单→权限：扫描 %d，同步 %d，跳过目录 %d，失败 %d</info>',
                $prefix,
                (int) ($menus['scanned'] ?? 0),
                (int) ($menus['synced'] ?? 0),
                (int) ($menus['skipped'] ?? 0),
                (int) ($menus['failed'] ?? 0)
            ));

            foreach ((array) ($menus['errors'] ?? []) as $error) {
                $output->writeln('<error>' . $error . '</error>');
            }

            if (!empty($result['prune'])) {
                $prune = (array) $result['prune'];
                $output->writeln(sprintf(
                    '<info>%s回收残留权限：扫描 %d，回收 %d，跳过 %d</info>',
                    $prefix,
                    (int) ($prune['scanned'] ?? 0),
                    (int) ($prune['pruned'] ?? 0),
                    (int) ($prune['skipped'] ?? 0)
                ));
                foreach ((array) ($prune['samples'] ?? []) as $sample) {
                    $output->writeln('  - ' . $sample);
                }
                foreach ((array) ($prune['errors'] ?? []) as $error) {
                    $output->writeln('<error>' . $error . '</error>');
                }
            }

            if (!empty($result['packages'])) {
                $packages = (array) $result['packages'];
                $output->writeln(sprintf(
                    '<info>扩展包：新增 %d，更新 %d，删除 %d，提供者 %d</info>',
                    (int) ($packages['created'] ?? 0),
                    (int) ($packages['updated'] ?? 0),
                    (int) ($packages['deleted'] ?? 0),
                    (int) ($packages['providers'] ?? 0)
                ));
                foreach ((array) ($packages['errors'] ?? []) as $error) {
                    $output->writeln('<error>' . $error . '</error>');
                }
            }

            $drift = (array) ($result['drift'] ?? []);
            $output->writeln(sprintf(
                '<comment>漂移：无菜单来源的权限 %d，未绑定权限的菜单 %d</comment>',
                (int) ($drift['orphan_count'] ?? 0),
                (int) ($drift['unbound_count'] ?? 0)
            ));
        }

        return (int) (($result['menus']['failed'] ?? 0) > 0 ? 1 : 0);
    }
}
