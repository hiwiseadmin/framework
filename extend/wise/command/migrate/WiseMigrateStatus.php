<?php
namespace wise\command\migrate;

use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;
use wise\command\concern\HasJsonOutput;

class WiseMigrateStatus extends WiseMigrate
{
    use HasJsonOutput;

    protected function configure()
    {
        $this->setName('migrate:status')
             ->setDescription('显示迁移状态（多路径）')
             ->addOption('format', 'f', InputOption::VALUE_REQUIRED, '输出格式：text / json（与 --json 等价）', 'text')
             ->addJsonOption()
             ->setHelp(<<<EOT
The <info>migrate:status</info> command prints a list of all migrations across all registered paths, along with their current status.

<info>php think migrate:status</info>
<info>php think migrate:status --json</info>

EOT
             );
    }

    protected function execute(Input $input, Output $output)
    {
        $format = strtolower(trim((string) ($input->getOption('format') ?: 'text')));

        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>Unsupported format: ' . $format . '（text / json）</error>');

            return 2;
        }

        $json = $this->isJsonOutput($input);
        $paths = $this->getPaths();

        if (!$json) {
            $output->writeln('<info>Migration paths:</info>');

            foreach ($paths as $i => $path) {
                $count = count(glob($path . DIRECTORY_SEPARATOR . '*.php') ?: []);
                $output->writeln(sprintf('  [%d] %s (%d files)', $i + 1, $path, $count));
            }
        }

        return $this->printStatus($json ? 'json' : 'text', $paths);
    }

    /**
     * @param string             $format text|json
     * @param array<int, string> $paths  扫描到的迁移路径（仅 JSON 输出时使用）
     */
    protected function printStatus($format = 'text', array $paths = [])
    {
        $json       = $format === 'json';
        $output     = $this->output;
        $migrations = [];
        $missing    = [];

        $allMigrations = $this->getMigrations();

        if (count($allMigrations)) {
            if (!$json) {
                $output->writeln('');
                $output->writeln(' Status  Migration ID    Started              Finished             Migration Name ');
                $output->writeln('----------------------------------------------------------------------------------');
            }

            $versions      = $this->getVersionLog();
            $maxNameLength = $versions ? max(array_map(function ($version) {
                return strlen($version['migration_name']);
            }, $versions)) : 0;

            foreach ($allMigrations as $migration) {
                $version = array_key_exists($migration->getVersion(), $versions)
                    ? $versions[$migration->getVersion()]
                    : false;

                if (!$json) {
                    $output->writeln(sprintf(
                        '%s %14.0f  %19s  %19s  <comment>%s</comment>',
                        $version ? '     <info>up</info> ' : '   <error>down</error> ',
                        $migration->getVersion(),
                        $version['start_time'] ?? '',
                        $version['end_time'] ?? '',
                        $migration->getName()
                    ));

                    if ($version && !empty($version['breakpoint'])) {
                        $output->writeln('         <error>BREAKPOINT SET</error>');
                    }
                }

                $migrations[] = [
                    'migration_status' => $version ? 'up' : 'down',
                    'migration_id'     => sprintf('%14.0f', $migration->getVersion()),
                    'migration_name'   => $migration->getName(),
                ];

                if ($version) {
                    unset($versions[$migration->getVersion()]);
                }
            }

            if (count($versions)) {
                foreach ($versions as $versionId => $version) {
                    if (!$json) {
                        $output->writeln(sprintf(
                            '     <error>up</error>  %14.0f  %19s  %19s  <comment>%s</comment>  <error>** MISSING **</error>',
                            $versionId,
                            $version['start_time'],
                            $version['end_time'],
                            str_pad($version['migration_name'], $maxNameLength, ' ')
                        ));

                        if (!empty($version['breakpoint'])) {
                            $output->writeln('         <error>BREAKPOINT SET</error>');
                        }
                    }

                    $missing[] = [
                        'migration_status' => 'up',
                        'migration_id'     => sprintf('%14.0f', $versionId),
                        'migration_name'   => $version['migration_name'],
                    ];
                }
            }
        } elseif (!$json) {
            $output->writeln('');
            $output->writeln('所有路径中均无可用的迁移文件。使用 <info>migrate:create</info> 命令创建。');
        }

        if ($json) {
            $total    = count($allMigrations);
            $executed = count(array_filter($migrations, static fn (array $row): bool => $row['migration_status'] === 'up'));

            $this->writeJson($output, [
                'paths'          => array_values($paths),
                'total_count'    => $total,
                'executed_count' => $executed,
                'pending_count'  => $total - $executed,
                'migrations'     => $migrations,
                'missing'        => $missing,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            return 0;
        }

        $output->writeln('');

        return 0;
    }
}
