<?php
namespace plugin\wisecms\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use plugin\wisecms\service\engine\QualityScorer;
use plugin\wisecms\model\Content;

class QualityRescoreCommand extends Command
{
    protected function configure()
    {
        $this->setName('wisecms:quality:rescore')
            ->setDescription('全站内容质量重评分');
    }

    protected function execute(Input $input, Output $output)
    {
        $scorer = new QualityScorer();
        $contents = Content::whereNull('delete_time')->select();
        $count = 0;

        foreach ($contents as $c) {
            try {
                $scorer->scoreAndSave($c->id);
                $count++;
                $output->writeln("  [{$c->id}] {$c->title} — OK");
            } catch (\Exception $e) {
                $output->writeln("  [{$c->id}] {$c->title} — Error: {$e->getMessage()}");
            }
        }

        $output->writeln("<info>完成: {$count} 篇内容已重新评分</info>");
    }
}
