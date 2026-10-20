<?php
namespace plugin\wisecms\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use plugin\wisecms\service\url\UrlService;

/**
 * 重建 CMS URL 别名索引
 *
 * 用于：启用伪静态（wisecms.url_rewrite=1）后，为存量内容/栏目/标签/单页
 * 批量生成 wisecms_url_alias，使旧内容也能通过伪静态 URL 访问。
 */
class UrlRebuildCommand extends Command
{
    protected function configure()
    {
        $this->setName('wisecms:url:rebuild')
            ->setDescription('重建 URL 别名索引（按当前 URL 规则生成全站伪静态映射）');
    }

    protected function execute(Input $input, Output $output)
    {
        $service = new UrlService();

        if (!$service->rewriteEnabled()) {
            $output->writeln('<comment>提示：wisecms.url_rewrite 未开启（当前为查询串模式），仍会按规则预生成索引，启用后即可直接生效。</comment>');
        }

        $stats = $service->rebuildAliases();

        foreach ($stats as $type => $count) {
            $output->writeln("<info>{$type}: {$count}</info>");
        }

        $output->writeln('<info>URL 别名索引重建完成</info>');
    }
}
