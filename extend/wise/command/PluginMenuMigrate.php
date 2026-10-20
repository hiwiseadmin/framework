<?php

declare(strict_types=1);

namespace wise\command;

use app\admin\model\Menu;
use think\console\Command;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;
use wise\plugin\PluginManager;
use wise\plugin\adapter\MenuRegistrar;

/**
 * 插件菜单归属迁移 / 重注册命令
 *
 * 按「插件顶级菜单数量」重构已安装插件的菜单归属：
 *   - 顶级菜单 = 1  → 入口挂到「插件扩展」容器下（不可钉选）
 *   - 顶级菜单 >= 2 → 入口挂到顶级（parent_id=0），默认进启动台，可钉选
 *
 * 并用 manifest 声明幂等重注册菜单（补建缺失项 / 恢复软删除项）。
 *
 * **覆盖策略**（`register()` 默认保留）：
 *   - 默认：已存在菜单的 `name/icon/sort/status/type/description` **保持不动**，
 *     运营在后台的改名、排序、停用不会被重注册冲掉（§8 G5）；
 *   - `--force`：以 manifest 为准**覆盖**上述字段（改文案 / 改 path / 重置排序时用）。
 *
 * **清理策略**（`--prune`，默认关闭）：
 *   - 软删 manifest 已不再声明的菜单（作者删掉的页面），可恢复：重新声明即恢复同一行；
 *   - 根容器、仍被声明子树引用的父目录、手工建的菜单一律不动；
 *   - `--dry-run` 预览将清理哪些节点（不落库）。
 *
 * 用法：
 *   php think plugin:menu-migrate                        # 只补建/恢复 + 纠正结构，保留运营改动
 *   php think plugin:menu-migrate --force                # 用 manifest 覆盖已存在菜单的字段
 *   php think plugin:menu-migrate --prune                # 顺带清理 manifest 已删除的旧菜单（软删）
 *   php think plugin:menu-migrate --prune --dry-run      # 只预览会清理哪些菜单
 */
class PluginMenuMigrate extends Command
{
    protected function configure()
    {
        $this->setName('plugin:menu-migrate')
            ->addOption('force', null, InputOption::VALUE_NONE, '以 manifest 为准覆盖已存在菜单的字段（默认保留运营改动）')
            ->addOption('prune', null, InputOption::VALUE_NONE, '清理 manifest 已不再声明的菜单（软删除，可恢复；默认不清理）')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '只预览 --prune 会清理哪些菜单，不落库')
            ->setDescription('重注册插件菜单（纠正归属 + 补建缺失项；可选清理 manifest 已删除项）');
    }

    protected function execute(Input $input, Output $output)
    {
        $force         = (bool) $input->getOption('force');
        $prune         = (bool) $input->getOption('prune');
        $dryRun        = (bool) $input->getOption('dry-run');
        $pluginManager = new PluginManager($this->getApp());
        $registrar     = new MenuRegistrar();
        $allPlugins    = $pluginManager->getAllPlugins();

        if ($dryRun && !$prune) {
            $output->writeln('<comment>提示：--dry-run 只在 --prune 下生效（本命令的其余动作都是幂等补建/保留）。</comment>');
        }

        // 确保「插件扩展」容器存在
        $extendId = Menu::ensurePluginExtendRoot();
        $output->writeln(sprintf('<info>插件扩展容器节点 #%d</info>', $extendId));

        $migrated = 0;
        $skipped  = 0;
        $pruned   = 0;

        /** @var string $ident  */
        foreach ($allPlugins as $ident => $metadata) {
            if (!$pluginManager->isInstalled($ident)) {
                $skipped++;
                continue;
            }

            $menus = $metadata->getMenus();
            if (empty($menus)) {
                $output->writeln(sprintf('  <comment>%s</comment>: 无菜单定义，跳过', $ident));
                $skipped++;
                continue;
            }

            $topCount = count($menus);

            // 1. 先确保插件根存在（只建根，不挂菜单，避免旧拍平分组被重复创建）
            $root = Menu::findPluginRoot($ident);
            if (!$root) {
                Menu::createPluginRoot($ident, [
                    'name'      => $metadata->getDisplayName(),
                    'icon'      => $metadata->getMenuRoot()['icon'] ?? 'bi bi-plugin',
                    'parent_id' => $topCount <= 1 ? Menu::ensurePluginExtendRoot() : 0,
                    'mount'     => $topCount <= 1 ? Menu::MOUNT_SIDEBAR_GROUP : Menu::MOUNT_LAUNCHER,
                ]);
                $root = Menu::findPluginRoot($ident);
            }
            $rootId = (int)$root->getAttr('id');

            // 2. 把旧版拍平的顶级分组（parent_id=0 且非插件根）挂到根下
            Menu::rebasePluginTopMenus($ident, $rootId);

            // 3. 幂等重注册（纠正 parent_id/mount，同步菜单树，命中已重挂载菜单则原位更新）
            //    默认保留已存在菜单的字段；--force 时才以 manifest 为准覆盖
            $registrar->register($metadata, $force);

            $output->writeln(sprintf(
                '  <info>%s</info>: 顶级 %d 个 → %s',
                $ident,
                $topCount,
                $topCount <= 1 ? '插件扩展' : '启动台'
            ));

            // 4. 清理 manifest 已不再声明的菜单（软删可恢复；--dry-run 只预览）
            if ($prune) {
                $removed = $registrar->prune($metadata, $dryRun);

                foreach ($removed as $node) {
                    $output->writeln(sprintf(
                        '      %s #%d %s%s',
                        $dryRun ? '<comment>[将清理]</comment>' : '<comment>已清理</comment>',
                        $node['id'],
                        $node['name'],
                        $node['path'] !== '' ? '（' . $node['path'] . '）' : ''
                    ));
                }

                $pruned += count($removed);
            }

            $migrated++;
        }

        // 校验残留的孤立插件顶级节点（非插件根但 plugin 非 system）
        $orphans = Menu::where('plugin', '<>', 'system')
            ->where('parent_id', 0)
            ->where('is_plugin_root', 0)
            ->count();

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>迁移完成：处理插件 %d 个，跳过 %d 个（%s）</info>',
            $migrated,
            $skipped,
            $force ? '已按 manifest 覆盖已存在菜单' : '已保留运营改动的字段，如需覆盖请加 --force'
        ));
        if ($prune) {
            $output->writeln(sprintf(
                $dryRun
                    ? '<comment>--dry-run 预览：本次将清理 %d 项 manifest 已不再声明的菜单（未落库）</comment>'
                    : '<info>已清理 %d 项 manifest 已不再声明的菜单（软删，重新声明即恢复）</info>',
                $pruned
            ));
        } else {
            $output->writeln('<comment>提示：如需清理 manifest 已删除的旧菜单，请加 --prune（软删可恢复，--dry-run 可预览）。</comment>');
        }
        if ($orphans > 0) {
            $output->writeln(sprintf('<warning>仍存在 %d 个孤立的插件顶级菜单，请检查插件是否已启用或手工处理。</warning>', $orphans));
        } else {
            $output->writeln('<info>无残留孤立插件顶级菜单。</info>');
        }

        return 0;
    }
}
