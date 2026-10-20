<?php
namespace wise\command\migrate;

use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;

class WiseMigrateBreakpoint extends WiseMigrate
{
    protected function configure()
    {
        $this->setName('migrate:breakpoint')
             ->setDescription('管理迁移断点')
             ->addOption('--target', '-t', InputOption::VALUE_REQUIRED, '设置或清除断点的版本号')
             ->addOption('--remove-all', '-r', InputOption::VALUE_NONE, '移除所有断点')
             ->setHelp(<<<EOT
The <info>breakpoint</info> command allows you to set or clear a breakpoint against a specific target to inhibit rollbacks beyond a certain target.

<info>php think migrate:breakpoint</info>
<info>php think migrate:breakpoint -t 20240101120000</info>
<info>php think migrate:breakpoint -r</info>

EOT
             );
    }

    protected function execute(Input $input, Output $output)
    {
        $version   = $input->getOption('target');
        $removeAll = $input->getOption('remove-all');

        if ($version && $removeAll) {
            throw new \InvalidArgumentException('不能同时设置断点和移除所有断点。');
        }

        if ($removeAll) {
            $this->removeBreakpoints();
        } else {
            $this->toggleBreakpoint($version);
        }
    }

    protected function toggleBreakpoint($version)
    {
        $migrations = $this->getMigrations();
        $versions   = $this->getVersionLog();

        if (empty($versions) || empty($migrations)) {
            return;
        }

        if (null === $version) {
            $lastVersion = end($versions);
            $version     = $lastVersion['version'];
        }

        if (0 != $version && !isset($migrations[$version])) {
            $this->output->writeln(sprintf('<comment>warning</comment> %s is not a valid version', $version));
            return;
        }

        $this->getAdapter()->toggleBreakpoint($migrations[$version]);

        $versions = $this->getVersionLog();

        $this->output->writeln(
            ' Breakpoint ' .
            ($versions[$version]['breakpoint'] ? 'set' : 'cleared') .
            ' for <info>' . $version . '</info>' .
            ' <comment>' . $migrations[$version]->getName() . '</comment>'
        );
    }

    protected function removeBreakpoints()
    {
        $this->output->writeln(
            sprintf(' %d breakpoints cleared.', $this->getAdapter()->resetAllBreakpoints())
        );
    }
}
