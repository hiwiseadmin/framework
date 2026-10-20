<?php
namespace plugin\wisecms\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use plugin\wisecms\service\seo\InternalLinkService;

class InternalLinkRebuildCommand extends Command
{
    protected function configure()
    {
        $this->setName('wisecms:internal-link:rebuild')
            ->setDescription('从内容库重建内链表');
    }

    protected function execute(Input $input, Output $output)
    {
        $service = new InternalLinkService();
        $count = $service->rebuildFromContent();
        $output->writeln("<info>内链表重建完成，新增 {$count} 条规则</info>");
    }
}
