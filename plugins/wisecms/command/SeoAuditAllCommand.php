<?php
namespace plugin\wisecms\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use plugin\wisecms\service\seo\SeoAuditService;
use plugin\wisecms\model\Content;

class SeoAuditAllCommand extends Command
{
    protected function configure()
    {
        $this->setName('wisecms:seo:audit-all')
            ->setDescription('全站SEO审查');
    }

    protected function execute(Input $input, Output $output)
    {
        $auditor = new SeoAuditService();
        $contents = Content::whereNull('delete_time')->select();
        $count = 0;

        foreach ($contents as $c) {
            $result = $auditor->auditContent($c->id);
            $count++;
            $output->writeln("  [{$c->id}] {$c->title} — SEO: {$result['total_score']}/{$result['level']}");
        }

        $output->writeln("<info>完成: {$count} 篇内容已审查</info>");
    }
}
