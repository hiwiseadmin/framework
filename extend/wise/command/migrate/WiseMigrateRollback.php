<?php
namespace wise\command\migrate;

use Phinx\Migration\MigrationInterface;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;

class WiseMigrateRollback extends WiseMigrate
{
    protected function configure()
    {
        $this->setName('migrate:rollback')
             ->setDescription('回滚最近一次或到指定版本的迁移')
             ->addOption('--target', '-t', InputOption::VALUE_REQUIRED, '回滚到的目标版本号')
             ->addOption('--date', '-d', InputOption::VALUE_REQUIRED, '回滚到的目标日期')
             ->addOption('--force', '-f', InputOption::VALUE_NONE, '强制回滚（忽略断点）')
             ->setHelp(<<<EOT
The <info>migrate:rollback</info> command reverts the last migration, or optionally up to a specific version.

<info>php think migrate:rollback</info>
<info>php think migrate:rollback -t 20240101120000</info>
<info>php think migrate:rollback -d 20240101</info>
<info>php think migrate:rollback -f</info>

EOT
             );
    }

    protected function execute(Input $input, Output $output)
    {
        $version = $input->getOption('target');
        $date    = $input->getOption('date');
        $force   = !!$input->getOption('force');

        $start = microtime(true);
        if (null !== $date) {
            $this->rollbackToDateTime(new \DateTime($date), $force);
        } else {
            $this->rollback($version, $force);
        }
        $end = microtime(true);

        $output->writeln('');
        $output->writeln('<comment>全部完成。耗时 ' . sprintf('%.4fs', $end - $start) . '</comment>');
    }

    protected function rollback($version = null, $force = false)
    {
        $migrations = $this->getMigrations();
        $versionLog = $this->getVersionLog();
        $versions   = array_keys($versionLog);

        ksort($migrations);
        sort($versions);

        if (empty($versions) || $version == end($versions)) {
            $this->output->writeln('<error>没有可以回滚的迁移</error>');
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
            $this->output->writeln("<error>目标版本 ($version) 未找到</error>");
            return;
        }

        krsort($migrations);
        foreach ($migrations as $migration) {
            if ($migration->getVersion() <= $version) {
                break;
            }

            if (in_array($migration->getVersion(), $versions)) {
                if (isset($versionLog[$migration->getVersion()]) &&
                    0 != $versionLog[$migration->getVersion()]['breakpoint'] &&
                    !$force
                ) {
                    $this->output->writeln('<error>遇到断点。进一步的回滚已被禁止。</error>');
                    break;
                }
                $this->executeMigration($migration, MigrationInterface::DOWN);
            }
        }
    }

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
                $this->output->writeln('Rolling back all migrations');
                $migration = 0;
            } else {
                $this->output->writeln('Rolling back to version ' . $earlierVersion);
                $migration = $earlierVersion;
            }
            $this->rollback($migration, $force);
        }
    }
}
