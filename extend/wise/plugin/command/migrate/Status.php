<?php
// +----------------------------------------------------------------------
// | 插件数据库迁移工具
// +----------------------------------------------------------------------
namespace wise\plugin\command\migrate;

use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;

/**
 * 查看插件迁移状态命令
 *
 * 用法：
 *   php think plugin:migrate:status -p demo
 *   php think plugin:migrate:status -p demo -f json
 */
class Status extends PluginMigrate
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugin:migrate:status')
             ->setDescription('查看插件数据库迁移状态')
             ->addOption('--format', '-f', InputOption::VALUE_REQUIRED, '输出格式：text 或 json，默认 text')
             ->setHelp(<<<EOT
The <info>plugin:migrate:status</info> 命令显示插件的所有迁移及其当前状态

<info>php think plugin:migrate:status -p demo</info>
<info>php think plugin:migrate:status -p demo -f json</info>
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
        $format = $input->getOption('format');

        if (null !== $format) {
            $output->writeln('<info>使用格式</info> ' . $format);
        }

        return $this->printStatus($format);
    }

    /**
     * 打印迁移状态
     *
     * @param string|null $format
     * @return int
     */
    protected function printStatus($format = null)
    {
        $output     = $this->output;
        $migrations = [];

        if (count($this->getMigrations())) {
            $output->writeln('');
            $output->writeln(' <info>插件 [' . $this->getPluginName() . '] 迁移状态</info>');
            $output->writeln('');
            $output->writeln(' Status  Migration ID    Started              Finished             Migration Name ');
            $output->writeln('----------------------------------------------------------------------------------');

            $versions      = $this->getVersionLog();
            $maxNameLength = $versions ? max(array_map(function ($version) {
                return strlen($version['migration_name']);
            }, $versions)) : 0;

            foreach ($this->getMigrations() as $migration) {
                $version = array_key_exists($migration->getVersion(), $versions) ? $versions[$migration->getVersion()] : false;
                if ($version) {
                    $status = '     <info>up</info> ';
                } else {
                    $status = '   <error>down</error> ';
                }
                $maxNameLength = max($maxNameLength, strlen($migration->getName()));

                $output->writeln(sprintf(
                    '%s %14.0f  %19s  %19s  <comment>%s</comment>',
                    $status,
                    $migration->getVersion(),
                    $version ? $version['start_time'] : '',
                    $version ? $version['end_time'] : '',
                    $migration->getName()
                ));

                if ($version && $version['breakpoint']) {
                    $output->writeln('         <error>BREAKPOINT SET</error>');
                }

                $migrations[] = [
                    'plugin'           => $this->getPluginName(),
                    'migration_status' => trim(strip_tags($status)),
                    'migration_id'     => sprintf('%14.0f', $migration->getVersion()),
                    'migration_name'   => $migration->getName(),
                ];
                unset($versions[$migration->getVersion()]);
            }

            // 显示缺失的迁移文件
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
            $output->writeln('There are no available migrations. Try creating one using the <info>plugin:migrate:create</info> command.');
        }

        $output->writeln('');

        if ($format !== null) {
            switch ($format) {
                case 'json':
                    $totalMigrations = count($this->getMigrations());
                    $executedCount   = count(array_filter($migrations, function ($m) {
                        return $m['migration_status'] === 'up';
                    }));
                    $output->writeln(json_encode([
                        'plugin'          => $this->getPluginName(),
                        'total_count'     => $totalMigrations,
                        'executed_count'  => $executedCount,
                        'pending_count'   => $totalMigrations - $executedCount,
                        'migrations'      => $migrations,
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    break;
                default:
                    $output->writeln('<info>不支持的格式: ' . $format . '</info>');
            }
        }

        return 0;
    }
}
