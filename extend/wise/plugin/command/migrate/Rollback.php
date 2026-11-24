<?php
namespace wise\plugin\command\migrate;

use Phinx\Migration\MigrationInterface;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;

/**
 * 回滚插件迁移命令
 *
 * 用法：
 *   php think plugin:migrate:rollback -p demo
 *   php think plugin:migrate:rollback -p demo --target=20240101120000
 *   php think plugin:migrate:rollback -p demo --force
 *   php think plugin:migrate:rollback -p demo --all
 *
 * --all 与默认行为的区别（卸载链路专用）：
 *   默认（记录法）只回滚 ws_plugin_migrations 中 plugin=<本插件> 已记录的版本；
 *   --all（文件法）不看记录，对插件目录下每个迁移文件都执行一次 DOWN。
 */
class Rollback extends PluginMigrate
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugin:migrate:rollback')
             ->setDescription('回滚插件数据库迁移')
             ->addOption('--target', '-t', InputOption::VALUE_REQUIRED, '回滚到指定版本号')
             ->addOption('--date', '-d', InputOption::VALUE_REQUIRED, '回滚到指定日期')
             ->addOption('--force', '-f', InputOption::VALUE_NONE, '强制回滚（忽略断点）')
             ->addOption('--all', '-a', InputOption::VALUE_NONE, '全量回滚：按迁移文件逐个 DOWN，不依赖迁移记录（卸载用）')
             ->setHelp(<<<EOT
The <info>plugin:migrate:rollback</info> 命令回滚插件的最后一次迁移，或回滚到指定版本

<info>php think plugin:migrate:rollback -p demo</info>
<info>php think plugin:migrate:rollback -p demo -t 20240101120000</info>
<info>php think plugin:migrate:rollback -p demo -d 20240101</info>
<info>php think plugin:migrate:rollback -p demo -f</info>
<info>php think plugin:migrate:rollback -p demo -a</info>

EOT
             );
    }

    /**
     * 执行回滚
     *
     * @param Input  $input
     * @param Output $output
     * @return void
     */
    protected function execute(Input $input, Output $output)
    {
        $version = $input->getOption('target');
        $date    = $input->getOption('date');
        $force   = !!$input->getOption('force');
        $all     = !!$input->getOption('all');

        if (is_numeric($version)) {
            $version = (int) $version;
        }

        $output->writeln('<info>== 插件 [' . $this->getPluginName() . '] 回滚开始 ==</info>');

        $start = microtime(true);

        if ($all) {
            $this->rollbackAll();
        } elseif (null !== $date) {
            $this->rollbackToDateTime(new \DateTime($date), $force);
        } else {
            $this->rollback($version, $force);
        }

        $end = microtime(true);

        $output->writeln('');
        $output->writeln('<info>== 插件 [' . $this->getPluginName() . '] 回滚完成. 耗时 ' . sprintf('%.4fs', $end - $start) . ' ==</info>');
    }

    /**
     * 全量回滚：按迁移文件逐个执行 DOWN，**不查询** ws_plugin_migrations 记录。
     *
     * 为什么必须有这条路径（卸载链路的关键补丁）：
     *   rollback() 的回滚范围 = 「ws_plugin_migrations 中 plugin=<本插件> 的版本」，
     *   该集合为空时它只打印「没有可回滚的迁移」就 return，于是数据表全部残留，
     *   再次安装重跑 up() 必然撞 "Table ... already exists"。
     *   "记录为空 / 记录不在本插件名下"的三种常见来源：
     *     1) 表是全局 `php think migrate:run` 建的（记录落在全局 migrations 表）；
     *     2) 站点从未执行过任何插件迁移，ws_plugin_migrations 表还不存在；
     *     3) 迁移记录被手工清理过。
     *   卸载语义是"把插件留下的数据库痕迹清干净"，因此这里退化为文件法：
     *   插件目录下每个迁移文件都执行一次 DOWN ——
     *   up/down 风格走 down()，change() 风格走 ProxyAdapter 反转。
     *
     * 容错：单个迁移失败只记录并继续。卸载要尽量清干净，不能因为某一个文件
     * 失败（例如只写了 up() 没写 down()、或 change() 里用了不可反转的原生 SQL）
     * 就放弃其余文件的清理；失败清单在结尾汇总，供 MigrationRunner 判定残留。
     *
     * 注意：本模式刻意忽略断点（breakpoint）；卸载/重装不需要人工放行。
     *
     * @return void
     */
    protected function rollbackAll(): void
    {
        $migrations = $this->getMigrations();

        if (empty($migrations)) {
            $this->output->writeln('<error>没有找到任何迁移文件</error>');
            return;
        }

        krsort($migrations);

        $succeeded = 0;
        $failed    = [];

        foreach ($migrations as $version => $migration) {
            try {
                $this->executeMigration($migration, MigrationInterface::DOWN);
                $succeeded++;
            } catch (\Throwable $e) {
                $failed[$version] = $e->getMessage();
                $this->output->writeln(sprintf(
                    '<error> == %s 回滚失败：%s</error>',
                    $version,
                    $e->getMessage()
                ));
            }
        }

        $this->output->writeln('');
        $this->output->writeln(sprintf(
            '<info>== 插件 [%s] 全量回滚完成：成功 %d 个 / 失败 %d 个 ==</info>',
            $this->getPluginName(),
            $succeeded,
            count($failed)
        ));

        foreach ($failed as $version => $message) {
            $this->output->writeln(sprintf('<error>  - 失败 %s：%s</error>', $version, $message));
        }
    }

    /**
     * 回滚到指定版本号
     *
     * 参考 think\migration\command\migrate\Rollback::rollback()
     *
     * @param int|string|null $version
     * @param bool $force
     * @return void
     */
    protected function rollback($version = null, $force = false)
    {
        $migrations = $this->getMigrations();
        $versionLog = $this->getVersionLog();
        $versions   = array_keys($versionLog);

        ksort($migrations);
        sort($versions);

        if (empty($versions) || (string) $version === (string) end($versions)) {
            $this->output->writeln('<error>没有可回滚的迁移</error>');
            return;
        }

        if (null === $version) {
            $prev    = count($versions) - 2;
            $version = $prev < 0 ? 0 : $versions[$prev];
        } else {
            $first = $versions[0];
            if ($version < $first) {
                $version = 0;
            }
        }

        if (0 !== $version && !isset($migrations[$version])) {
            $this->output->writeln("<error>目标版本 ($version) 不存在</error>");
            return;
        }

        krsort($migrations);
        foreach ($migrations as $migration) {
            if ($migration->getVersion() <= $version) {
                break;
            }

            if (in_array($migration->getVersion(), $versions)) {
                if (isset($versionLog[$migration->getVersion()]) && 0 != $versionLog[$migration->getVersion()]['breakpoint'] && !$force) {
                    $this->output->writeln('<error>已到达断点，回滚被阻止。使用 --force 强制回滚。</error>');
                    break;
                }
                $this->executeMigration($migration, MigrationInterface::DOWN);
            }
        }
    }

    /**
     * 回滚到指定日期时间
     *
     * @param \DateTime $dateTime
     * @param bool $force
     * @return void
     */
    protected function rollbackToDateTime(\DateTime $dateTime, $force = false)
    {
        $versions   = $this->getVersions();
        $dateString = $dateTime->format('YmdHis');
        sort($versions);

        $earlierVersion      = null;
        $availableMigrations = array_filter($versions, function ($version) use ($dateString, &$earlierVersion) {
            if ($version <= $dateString) {
                $earlierVersion = $version;
            }
            return $version >= $dateString;
        });

        if (count($availableMigrations) > 0) {
            if (is_null($earlierVersion)) {
                $this->output->writeln('回滚所有迁移');
                $migration = 0;
            } else {
                $this->output->writeln('回滚到版本 ' . $earlierVersion);
                $migration = $earlierVersion;
            }
            $this->rollback($migration, $force);
        }
    }
}
