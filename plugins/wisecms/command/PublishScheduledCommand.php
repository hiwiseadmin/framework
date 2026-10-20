<?php
namespace plugin\wisecms\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use plugin\wisecms\service\engine\SchedulerService;

class PublishScheduledCommand extends Command
{
    protected function configure()
    {
        $this->setName('wisecms:publish-scheduled')
            ->setDescription('执行排期发布');
    }

    protected function execute(Input $input, Output $output)
    {
        $scheduler = new SchedulerService();
        $result = $scheduler->executeScheduledPublish();
        $output->writeln("<info>排期发布完成：{$result['published']} 篇内容已发布</info>");
    }
}
