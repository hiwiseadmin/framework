<?php
namespace plugin\wisecms\command;

use plugin\wisecms\model\Content;
use plugin\wisecms\model\ContentVersion;
use plugin\wisecms\service\content\ContentVersionService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 重建内容版本快照（A-1）
 *
 * 版本语义已改为「保存后快照」（v1 = 创建后状态，vN = 第 N 次保存后状态），
 * 旧格式快照不兼容且从未被任何代码读取，故整体丢弃后按当前状态重建 v1。
 *
 *   php think wisecms:version:rebase            清空存量 + 按当前状态重建 v1
 *   php think wisecms:version:rebase --purge    只清空，不重建
 *   php think wisecms:version:rebase --dry-run  仅统计，不写库
 *
 * 详见 docs/wisecms-content-version-design.md
 */
class VersionRebaseCommand extends Command
{
    protected function configure()
    {
        $this->setName('wisecms:version:rebase')
            ->addOption('dry-run', null, Option::VALUE_NONE, '仅统计，不写库')
            ->addOption('purge', null, Option::VALUE_NONE, '只清空存量快照，不重建')
            ->setDescription('重建内容版本快照（清空存量后按当前状态生成 v1）');
    }

    protected function execute(Input $input, Output $output)
    {
        $dryRun    = (bool) $input->getOption('dry-run');
        $purgeOnly = (bool) $input->getOption('purge');

        $existing     = (int) ContentVersion::count();
        $contentTotal = (int) Content::count();

        $output->writeln("存量快照：{$existing} 条；未删除内容：{$contentTotal} 条");

        if ($dryRun) {
            $tail = $purgeOnly ? '（仅清空）' : "，并为 {$contentTotal} 条内容生成 v1";
            $output->writeln("<comment>--dry-run：不写库。将清空 {$existing} 条快照{$tail}。</comment>");

            return 0;
        }

        // 清空：旧格式不做兼容，整体丢弃比逐行猜测语义更安全
        $table = Db::name('wisecms_content_version')->getTable();
        Db::execute("TRUNCATE TABLE `{$table}`");
        $output->writeln("<info>已清空 wisecms_content_version（{$existing} 条）</info>");

        if ($purgeOnly) {
            return 0;
        }

        $service = new ContentVersionService();
        $created = 0;
        $failed  = 0;
        $lastId  = 0;

        // 按主键游标分批，避免全表载入内存
        while (true) {
            $items = Content::where('id', '>', $lastId)->order('id', 'asc')->limit(200)->select();
            if ($items->isEmpty()) {
                break;
            }

            foreach ($items as $content) {
                $lastId = (int) $content->id;
                try {
                    if ($service->capture($content, 'rebase') !== null) {
                        $created++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    cms_log('warning', '版本快照重建失败', [
                        'content_id' => $lastId,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        $output->writeln("<info>已为 {$created} 条内容生成 v1 快照</info>");
        if ($failed > 0) {
            $output->writeln("<error>失败 {$failed} 条（详见日志）</error>");
        }

        return 0;
    }
}
