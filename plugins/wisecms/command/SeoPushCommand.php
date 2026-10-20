<?php
namespace plugin\wisecms\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use plugin\wisecms\service\seo\SeoPushService;
use plugin\wisecms\model\Content;

class SeoPushCommand extends Command
{
    protected function configure()
    {
        $this->setName('wisecms:seo:push')
            ->setDescription('推送到搜索引擎')
            ->addArgument('engine', Argument::OPTIONAL, 'baidu/google/bing/all', 'all');
    }

    protected function execute(Input $input, Output $output)
    {
        $engine = $input->getArgument('engine');
        $pusher = new SeoPushService();

        // 取最近 24 小时创建的内容（create_time 为 DATETIME）
        $contents = Content::where('status', 2)
            ->whereNull('delete_time')
            ->where('create_time', '>=', date('Y-m-d H:i:s', time() - 86400))
            ->select();

        $ids = $contents->column('id');
        if (empty($ids)) {
            $output->writeln('<comment>无最近发布的内容</comment>');
            return;
        }

        $result = match ($engine) {
            'baidu'  => $pusher->pushToBaidu($ids),
            'google' => $pusher->pushToGoogle($ids),
            'bing'   => $pusher->pushToBing($ids),
            default  => $pusher->pushAll($ids),
        };

        $output->writeln('<info>推送完成: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . '</info>');
    }
}
