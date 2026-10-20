<?php
namespace plugin\wisecms\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use plugin\wisecms\service\seo\SitemapService;

class SitemapRegenerateCommand extends Command
{
    protected function configure()
    {
        $this->setName('wisecms:sitemap:regenerate')
            ->setDescription('重新生成Sitemap');
    }

    protected function execute(Input $input, Output $output)
    {
        $sitemap = new SitemapService();

        // 生成 Sitemap Index
        $indexXml = $sitemap->generateSitemapIndex();
        $output->writeln("Sitemap Index 已生成");

        // 生成页面 Sitemap（栏目+标签）
        $pagesXml = $sitemap->generatePagesSitemap();
        $output->writeln("Pages Sitemap 已生成");

        // 生成内容 Sitemap（第1页）
        $contentXml = $sitemap->generateContentSitemap(1);
        $output->writeln("Content Sitemap 第1页已生成");

        $output->writeln("<info>Sitemap 重新生成完毕</info>");
    }
}
