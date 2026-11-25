<?php
namespace wise\command\migrate;

use Phinx\Migration\MigrationInterface;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;

class WiseMigrateRun extends WiseMigrate
{
    protected function configure()
    {
        $this->setName('migrate:run')
             ->setDescription('执行数据库迁移（多路径扫描）')
             ->addOption('--target', '-t', InputOption::VALUE_REQUIRED, '迁移到指定版本号')
             ->addOption('--date', '-d', InputOption::VALUE_REQUIRED, '迁移到指定日期')
             ->setHelp(<<<EOT
The <info>migrate:run</info> command runs all available migrations from all registered paths (config/migration.php).

<info>php think migrate:run</info>
<info>php think migrate:run -t 20240101120000</info>
<info>php think migrate:run -d 20240101</info>

EOT
             );
    }

    protected function execute(Input $input, Output $output)
    {
        $paths   = $this->getPaths();
        $version = $input->getOption('target');
        $date    = $input->getOption('date');

        $output->writeln('<info>Migration paths:</info>');
        foreach ($paths as $i => $path) {
            $output->writeln(sprintf('  [%d] %s', $i + 1, $path));
        }
        $output->writeln('');

        $start = microtime(true);
        if (null !== $date) {
            $this->migrateToDateTime(new \DateTime($date));
        } else {
            $this->migrate($version);
        }
        $end = microtime(true);

        $output->writeln('');
        $output->writeln('<comment>全部完成。耗时 ' . sprintf('%.4fs', $end - $start) . '</comment>');
    }

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

    protected function migrate($version = null)
    {
        $migrations = $this->getMigrations();
        $versions   = $this->getVersions();
        $current    = $this->getCurrentVersion();

        if (empty($versions) && empty($migrations)) {
            $this->output->writeln('<comment>没有待执行的迁移文件</comment>');
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

        $direction = $version > $current ? MigrationInterface::UP : MigrationInterface::DOWN;

        if ($direction === MigrationInterface::DOWN) {
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

    protected function getCurrentVersion()
    {
        $versions = $this->getVersions();
        $version  = 0;

        if (!empty($versions)) {
            $version = end($versions);
        }

        return $version;
    }
}
