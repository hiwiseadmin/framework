<?php
// +----------------------------------------------------------------------
// | 插件数据库迁移工具
// +----------------------------------------------------------------------
namespace wise\plugin\command\migrate;

use Phinx\Migration\MigrationInterface;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;

/**
 * 执行插件迁移命令
 *
 * 用法：
 *   php think plugin:migrate:run -p demo
 *   php think plugin:migrate:run -p demo --target=20240101120000
 *   php think plugin:migrate:run -p demo --date=20240101
 */
class Run extends PluginMigrate
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugin:migrate:run')
             ->setDescription('执行插件数据库迁移')
             ->addOption('--target', '-t', InputOption::VALUE_REQUIRED, '迁移到指定版本号')
             ->addOption('--date', '-d', InputOption::VALUE_REQUIRED, '迁移到指定日期')
             ->setHelp(<<<EOT
The <info>plugin:migrate:run</info> 命令执行插件的所有待迁移文件，可选指定目标版本或日期

<info>php think plugin:migrate:run -p demo</info>
<info>php think plugin:migrate:run -p demo -t 20240101120000</info>
<info>php think plugin:migrate:run -p demo -d 20240101</info>

EOT
             );
    }

    /**
     * 执行迁移
     *
     * @param Input  $input
     * @param Output $output
     * @return void
     */
    protected function execute(Input $input, Output $output)
    {
        $output->writeln('<info>== 插件 [' . $this->getPluginName() . '] 迁移开始 ==</info>');

        $start = microtime(true);

        $version = $input->getOption('target');
        $date    = $input->getOption('date');

        if (null !== $date) {
            $this->migrateToDateTime(new \DateTime($date));
        } else {
            $this->migrate($version);
        }

        $end = microtime(true);

        $output->writeln('');
        $output->writeln('<info>== 插件 [' . $this->getPluginName() . '] 迁移完成. 耗时 ' . sprintf('%.4fs', $end - $start) . ' ==</info>');
    }

    /**
     * 迁移到指定日期时间
     *
     * @param \DateTime $dateTime
     * @return void
     */
    protected function migrateToDateTime(\DateTime $dateTime)
    {
        $versions   = array_keys($this->getMigrations());
        $dateString = $dateTime->format('YmdHis');

        $outstandingMigrations = array_filter($versions, function ($version) use ($dateString) {
            return $version <= $dateString;
        });

        if (count($outstandingMigrations) > 0) {
            $migration = max($outstandingMigrations);
            $this->output->writeln('Migrating to version ' . $migration);
            $this->migrate($migration);
        }
    }

    /**
     * 执行迁移核心逻辑
     *
     * 参考 think\migration\command\migrate\Run::migrate()
     *
     * @param int|string|null $version
     * @return void
     */
    protected function migrate($version = null)
    {
        $migrations = $this->getMigrations();
        $versions   = $this->getVersions();
        $current    = $this->getCurrentVersion();

        if (empty($versions) && empty($migrations)) {
            $this->output->writeln('<comment>没有找到可执行的迁移</comment>');
            return;
        }

        if (null === $version) {
            $version = max(array_merge($versions, array_keys($migrations)));
        } else {
            if (0 != $version && !isset($migrations[$version])) {
                $this->output->writeln(sprintf('<comment>warning</comment> %s is not a valid version', $version));
                return;
            }
        }

        // 判断迁移方向
        $direction = $version > $current ? MigrationInterface::UP : MigrationInterface::DOWN;

        if ($direction === MigrationInterface::DOWN) {
            // 先执行 DOWN（回滚到目标版本之后的）
            krsort($migrations);
            foreach ($migrations as $migration) {
                if ($migration->getVersion() <= $version) {
                    break;
                }

                if (in_array($migration->getVersion(), $versions)) {
                    $this->executeMigration($migration, MigrationInterface::DOWN);
                }
            }
        }

        // 再执行 UP（执行目标版本及之前的）
        ksort($migrations);
        foreach ($migrations as $migration) {
            if ($migration->getVersion() > $version) {
                break;
            }

            if (!in_array($migration->getVersion(), $versions)) {
                $this->executeMigration($migration, MigrationInterface::UP);
            }
        }
    }
}
