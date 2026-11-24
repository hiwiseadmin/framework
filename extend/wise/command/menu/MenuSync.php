<?php
declare(strict_types=1);

namespace wise\command\menu;

use app\admin\service\MenuItemSync;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * menu:sync —— 后台菜单声明同步 / 生命周期管理
 *
 * 消费 ExtPoints::MENU_ITEM：把各包/插件声明的菜单幂等落地到 menus 表
 * （含菜单派生权限）。与插件 manifest 菜单共用同一落库原语，可重复执行。
 *
 * 归属说明：本命令属「框架级命令」，放在 extend/wise/command/menu/，由
 * extend/wise/extend.json 的 commands 声明、ExtendLoaderService 统一注册。
 *
 * 动作：
 *   sync（默认）  同步声明 → 菜单表（已存在默认保留运营改动）
 *   --disable    停用：status=0（隐藏，保留配置与权限绑定）
 *   --enable     启用：status=1
 *   --uninstall  卸载：菜单软删（权限保留；重装可恢复）
 *                └ --purge 连用：物理删菜单并软删其权限（不可逆）
 *
 * 用法：
 *   php think menu:sync                          # 同步全部（保留运营改动）
 *   php think menu:sync -p wise-member           # 仅同步指定实现
 *   php think menu:sync --force                  # 以声明为准覆盖已存在菜单字段
 *   php think menu:sync --prune                  # 软删声明中已不存在的菜单
 *   php think menu:sync --prune-orphans          # 软删「实现已不存在」的孤儿菜单
 *   php think menu:sync --silent                 # 静默（composer 脚本用；环境未就绪时返回 0）
 *   php think menu:sync -p wise-member --disable # 停用该实现的全部菜单
 *   php think menu:sync -p wise-member --enable  # 启用
 *   php think menu:sync -p wise-member --uninstall           # 卸载（软删）
 *   php think menu:sync -p wise-member --uninstall --purge   # 彻底清理
 */
class MenuSync extends Command
{
    protected function configure(): void
    {
        $this->setName('menu:sync')
            ->addOption('package', 'p', Option::VALUE_OPTIONAL, '指定实现标识（menus.plugin），留空表示全部', '')
            ->addOption('force', 'f', Option::VALUE_NONE, '同步时以声明为准覆盖已存在菜单的字段（默认保留运营改动）')
            ->addOption('prune', null, Option::VALUE_NONE, '同步时软删除「声明中已不存在」的该实现菜单')
            ->addOption('prune-orphans', null, Option::VALUE_NONE, '同步时软删除「实现已不存在」的孤儿菜单')
            ->addOption('disable', null, Option::VALUE_NONE, '停用：菜单 status=0（隐藏，保留配置与权限）')
            ->addOption('enable', null, Option::VALUE_NONE, '启用：菜单 status=1')
            ->addOption('uninstall', null, Option::VALUE_NONE, '卸载：菜单软删（权限保留，重装可恢复）')
            ->addOption('purge', null, Option::VALUE_NONE, '与 --uninstall 连用：物理删除菜单并软删其权限')
            ->addOption('silent', null, Option::VALUE_NONE, '静默模式：无输出，且环境未就绪时返回 0（供 composer 脚本调用）')
            ->setDescription('同步 / 停用 / 启用 / 卸载扩展点声明的后台菜单（ExtPoints::MENU_ITEM）');
    }

    protected function execute(Input $input, Output $output)
    {
        $silent = (bool) $input->getOption('silent');

        $say = static function (string $message) use ($output, $silent): void {
            if (!$silent) {
                $output->writeln($message);
            }
        };

        if (!$this->tableReady()) {
            if ($silent) {
                return 0;
            }

            $say('<error>menus 表不存在，请先完成安装/迁移后再执行菜单同步。</error>');
            return 1;
        }

        $package = trim((string) $input->getOption('package'));
        $purge   = (bool) $input->getOption('purge');

        $actions = array_filter([
            'disable'   => (bool) $input->getOption('disable'),
            'enable'    => (bool) $input->getOption('enable'),
            'uninstall' => (bool) $input->getOption('uninstall'),
        ]);

        if (count($actions) > 1) {
            $say('<error>--disable / --enable / --uninstall 互斥，请只指定一个。</error>');
            return $silent ? 0 : 1;
        }

        $sync = new MenuItemSync();

        if ($actions) {
            if ($package === '') {
                $say('<error>生命周期动作必须用 -p 指定实现标识，避免误操作全量菜单。</error>');
                return $silent ? 0 : 1;
            }

            $action = (string) array_key_first($actions);

            if ($action === 'disable') {
                $count = $sync->disable($package);
            } elseif ($action === 'enable') {
                $count = $sync->enable($package);
            } else {
                $count = $sync->uninstall($package, $purge);
            }

            $say(sprintf(
                '<info>%s：%s，共 %d 项菜单</info>',
                $package,
                $this->actionText($action, $purge),
                $count
            ));

            return 0;
        }

        $stat = $sync->syncAll([
            'package'      => $package,
            'force'        => (bool) $input->getOption('force'),
            'prune'        => (bool) $input->getOption('prune'),
            'pruneOrphans' => (bool) $input->getOption('prune-orphans'),
        ]);

        foreach ($stat['messages'] as $message) {
            $say('  ' . $message);
        }

        if ($stat['packages'] === 0 && $stat['disabled'] === 0 && $stat['skipped'] === 0) {
            $say('<comment>未发现任何已注册的菜单声明实现（ExtPoints::MENU_ITEM）。</comment>');
            return 0;
        }

        $suffix = '';
        if (!empty($stat['orphans'])) {
            $suffix = sprintf('，孤儿 %d（已清理 %d）', count($stat['orphans']), $stat['orphanPruned']);
        }

        $say(sprintf(
            '<info>菜单同步完成：实现 %d，新增 %d，已存在 %d，停用 %d，跳过 %d，清理 %d%s</info>',
            $stat['packages'],
            $stat['created'],
            $stat['existing'],
            $stat['disabled'],
            $stat['skipped'],
            $stat['pruned'],
            $suffix
        ));

        return 0;
    }

    /**
     * 生命周期动作的中文描述
     */
    protected function actionText(string $action, bool $purge): string
    {
        return match ($action) {
            'disable'   => '已停用（status=0，配置与权限保留）',
            'enable'    => '已启用（status=1）',
            'uninstall' => $purge
                ? '已彻底清理（物理删除菜单 + 软删权限）'
                : '已卸载（菜单软删，权限保留，重装可恢复）',
            default     => $action,
        };
    }

    /**
     * 探测 menus 表是否可用（安装前执行时应给出提示而不是抛异常）
     */
    protected function tableReady(): bool
    {
        try {
            Db::name('menus')->where('id', 0)->count();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
