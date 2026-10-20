<?php
namespace wise\command\migrate;

use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;

class WiseMigrateStatus extends WiseMigrate
{
    protected function configure()
    {
        $this->setName('migrate:status')
             ->setDescription('显示迁移状态（多路径）')
             ->addOption('--format', '-f', InputOption::VALUE_REQUIRED, '输出格式: text 或 json。默认 text。')
             ->setHelp(<<<EOT
The <info>migrate:status</info> command prints a list of all migrations across all registered paths, along with their current status.

<info>php think migrate:status</info>
<info>php think migrate:status -f json</info>

EOT
             );
    }

    protected function execute(Input $input, Output $output)
    {
        $format = $input->getOption('format');

        // 显示扫描的路径
        $paths = $this->getPaths();
        $output->writeln('<info>Migration paths:</info>');
        foreach ($paths as $i => $path) {
            $count = count(glob($path . DIRECTORY_SEPARATOR . '*.php') ?: []);
            $output->writeln(sprintf('  [%d] %s (%d files)', $i + 1, $path, $count));
        }

        return $this->printStatus($format);
    }

    protected function printStatus($format = null)
    {
        $output     = $this->output;
        $migrations = [];

        if (count($this->getMigrations())) {
            $output->writeln('');
            $output->writeln(' Status  Migration ID    Started              Finished             Migration Name ');
            $output->writeln('----------------------------------------------------------------------------------');

            $versions      = $this->getVersionLog();
            $maxNameLength = $versions ? max(array_map(function ($version) {
                return strlen($version['migration_name']);
            }, $versions)) : 0;

            foreach ($this->getMigrations() as $migration) {
                $version = array_key_exists($migration->getVersion(), $versions)
                    ? $versions[$migration->getVersion()]
                    : false;

                if ($version) {
                    $status = '     <info>up</info> ';
                } else {
                    $status = '   <error>down</error> ';
                    $version = [];
                    $version['start_time'] = $version['end_time'] = $version['breakpoint'] = '';
                }
                $maxNameLength = max($maxNameLength, strlen($migration->getName()));

                $output->writeln(sprintf(
                    '%s %14.0f  %19s  %19s  <comment>%s</comment>',
                    $status,
                    $migration->getVersion(),
                    $version['start_time'],
                    $version['end_time'],
                    $migration->getName()
                ));

                if ($version && $version['breakpoint']) {
                    $output->writeln('         <error>BREAKPOINT SET</error>');
                }

                $migrations[] = [
                    'migration_status' => trim(strip_tags($status)),
                    'migration_id'     => sprintf('%14.0f', $migration->getVersion()),
                    'migration_name'   => $migration->getName()
                ];
                unset($versions[$migration->getVersion()]);
            }

            if (count($versions)) {
                foreach ($versions as $missing => $version) {
                    $output->writeln(sprintf(
                        '     <error>up</error>  %14.0f  %19s  %19s  <comment>%s</comment>  <error>** MISSING **</error>',
                        $missing,
                        $version['start_time'],
                        $version['end_time'],
                        str_pad($version['migration_name'], $maxNameLength, ' ')
                    ));

                    if ($version && $version['breakpoint']) {
                        $output->writeln('         <error>BREAKPOINT SET</error>');
                    }
                }
            }
        } else {
            $output->writeln('');
            $output->writeln('所有路径中均无可用的迁移文件。使用 <info>migrate:create</info> 命令创建。');
        }

        $output->writeln('');

        if ($format !== null) {
            switch ($format) {
                case 'json':
                    $output->writeln(json_encode([
                        'pending_count' => count($this->getMigrations()),
                        'migrations'    => $migrations
                    ]));
                    break;
                default:
                    $output->writeln('<info>Unsupported format: ' . $format . '</info>');
            }
        }
    }
}
