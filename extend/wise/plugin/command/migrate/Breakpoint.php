<?php
// +----------------------------------------------------------------------
// | 插件数据库迁移工具
// +----------------------------------------------------------------------
namespace wise\plugin\command\migrate;

use InvalidArgumentException;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;

/**
 * 管理插件迁移断点命令
 *
 * 用法：
 *   php think plugin:migrate:breakpoint -p demo
 *   php think plugin:migrate:breakpoint -p demo --target=20240101120000
 *   php think plugin:migrate:breakpoint -p demo --remove-all
 */
class Breakpoint extends PluginMigrate
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugin:migrate:breakpoint')
             ->setDescription('管理插件迁移断点')
             ->addOption('--target', '-t', InputOption::VALUE_REQUIRED, '指定版本号设置或清除断点')
             ->addOption('--remove-all', '-r', InputOption::VALUE_NONE, '移除当前插件的所有断点')
             ->setHelp(<<<EOT
The <info>plugin:migrate:breakpoint</info> 命令允许你对指定迁移版本设置或清除断点，阻止回滚越过该版本。
如果未指定目标版本，则使用最近的迁移版本。

<info>php think plugin:migrate:breakpoint -p demo</info>
<info>php think plugin:migrate:breakpoint -p demo -t 20240101120000</info>
<info>php think plugin:migrate:breakpoint -p demo -r</info>
EOT
             );
    }

    /**
     * 执行断点管理
     *
     * @param Input  $input
     * @param Output $output
     * @return void
     */
    protected function execute(Input $input, Output $output)
    {
        $version   = $input->getOption('target');
        $removeAll = $input->getOption('remove-all');

        if ($version && $removeAll) {
            throw new InvalidArgumentException('不能同时切换断点和清除所有断点。');
        }

        if ($removeAll) {
            $this->removeBreakpoints();
        } else {
            $this->toggleBreakpoint($version);
        }
    }

    /**
     * 切换断点状态
     *
     * @param int|string|null $version
     * @return void
     */
    protected function toggleBreakpoint($version)
    {
        $migrations = $this->getMigrations();
        $versions   = $this->getVersionLog();

        if (empty($versions) || empty($migrations)) {
            $this->output->writeln('<comment>没有可用的迁移记录</comment>');
            return;
        }

        if (null === $version) {
            $lastVersion = end($versions);
            if (!is_array($lastVersion) || !isset($lastVersion['version'])) {
                $this->output->writeln('<error>无法确定最近的迁移版本</error>');
                return;
            }
            $version = $lastVersion['version'];
        }

        if (0 != $version && !isset($migrations[$version])) {
            $this->output->writeln(sprintf('<comment>warning</comment> %s is not a valid version', $version));
            return;
        }

        $this->getAdapter()->toggleBreakpoint($migrations[$version]);

        $versions = $this->getVersionLog();

        $this->output->writeln(' Breakpoint ' . ($versions[$version]['breakpoint'] ? 'set' : 'cleared') . ' for <info>' . $version . '</info>' . ' <comment>' . $migrations[$version]->getName() . '</comment>');
    }

    /**
     * 移除当前插件的所有断点
     *
     * @return void
     */
    protected function removeBreakpoints()
    {
        $count = $this->getAdapter()->resetAllBreakpoints();
        $this->output->writeln(sprintf(' <info>插件 [%s]</info> 已清除 %d 个断点。', $this->getPluginName(), $count));
    }
}
