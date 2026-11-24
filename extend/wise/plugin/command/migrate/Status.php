<?php
namespace wise\plugin\command\migrate;

use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;
use wise\command\concern\HasJsonOutput;

/**
 * 查看插件迁移状态命令
 *
 * 用法：
 *   php think plugin:migrate:status -p demo
 *   php think plugin:migrate:status -p demo --json            # 推荐（stdout 只有 JSON）
 *   php think plugin:migrate:status -p demo --format=json     # 兼容写法
 */
class Status extends PluginMigrate
{
    use HasJsonOutput;

    protected function configure()
    {
        parent::configure();

        $this->setName('plugin:migrate:status')
             ->setDescription('查看插件数据库迁移状态')
             ->addOption('format', 'f', InputOption::VALUE_REQUIRED, '输出格式：text / json（与 --json 等价）', 'text')
             ->addJsonOption()
             ->setHelp(<<<EOT
The <info>plugin:migrate:status</info> 命令显示插件的所有迁移及其当前状态

<info>php think plugin:migrate:status -p demo</info>
<info>php think plugin:migrate:status -p demo --json</info>
EOT
             );
    }

    /**
     * 执行查看状态
     *
     * @param Input  $input
     * @param Output $output
     * @return int
     */
    protected function execute(Input $input, Output $output)
    {
        $format = strtolower(trim((string) ($input->getOption('format') ?: 'text')));

        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>不支持的格式：' . $format . '（可选 text / json）</error>');

            return 2;
        }

        if ($this->isJsonOutput($input)) {
            $format = 'json';
        }

        return $this->printStatus($format);
    }

    /**
     * 打印迁移状态
     *
     * JSON 模式下 **stdout 只有 JSON**（不打印表头、不打印「使用格式」等提示），
     * 否则 `jq` / Agent 解析会失败。
     *
     * @param string $format text|json
     * @return int
     */
    protected function printStatus($format = 'text')
    {
        $json       = $format === 'json';
        $output     = $this->output;
        $migrations = [];
        $missing    = [];

        $allMigrations = $this->getMigrations();

        if (count($allMigrations)) {
            if (!$json) {
                $output->writeln('');
                $output->writeln(' <info>插件 [' . $this->getPluginName() . '] 迁移状态</info>');
                $output->writeln('');
                $output->writeln(' Status  Migration ID    Started              Finished             Migration Name ');
                $output->writeln('----------------------------------------------------------------------------------');
            }

            $versions      = $this->getVersionLog();
            $maxNameLength = $versions ? max(array_map(function ($version) {
                return strlen($version['migration_name']);
            }, $versions)) : 0;

            foreach ($allMigrations as $migration) {
                $version = array_key_exists($migration->getVersion(), $versions) ? $versions[$migration->getVersion()] : false;
                $status  = $version ? 'up' : 'down';
                $maxNameLength = max($maxNameLength, strlen($migration->getName()));

                if (!$json) {
                    $output->writeln(sprintf(
                        '%s %14.0f  %19s  %19s  <comment>%s</comment>',
                        $version ? '     <info>up</info> ' : '   <error>down</error> ',
                        $migration->getVersion(),
                        $version ? $version['start_time'] : '',
                        $version ? $version['end_time'] : '',
                        $migration->getName()
                    ));

                    if ($version && $version['breakpoint']) {
                        $output->writeln('         <error>BREAKPOINT SET</error>');
                    }
                }

                $migrations[] = [
                    'plugin'           => $this->getPluginName(),
                    'migration_status' => $status,
                    'migration_id'     => sprintf('%14.0f', $migration->getVersion()),
                    'migration_name'   => $migration->getName(),
                ];
                unset($versions[$migration->getVersion()]);
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

                        if ($version && $version['breakpoint']) {
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
            $output->writeln('There are no available migrations. Try creating one using the <info>plugin:migrate:create</info> command.');
        }

        $total    = count($allMigrations);
        $executed = count(array_filter($migrations, static fn (array $row): bool => $row['migration_status'] === 'up'));

        if ($json) {
            $this->writeJson($output, [
                'plugin'         => $this->getPluginName(),
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
