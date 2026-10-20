<?php
namespace plugin\wisecms\command;

use plugin\wisecms\model\ContentVersion;
use plugin\wisecms\service\content\ContentVersionService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 清理内容版本快照（A-1 保留策略）
 *
 * 版本表每条记录是全量 JSON（含正文），若不裁剪会随编辑次数持续膨胀。
 * 本命令按 config('wisecms.version_keep')（默认 20）保留最新的 N 条版本。
 *
 *   php think wisecms:version:cleanup                  按配置保留条数全量裁剪
 *   php think wisecms:version:cleanup --keep=5         指定保留条数
 *   php think wisecms:version:cleanup --content=123    仅清理指定内容
 *   php think wisecms:version:cleanup --dry-run        仅统计，不删除（生产预检）
 *
 * 注意：keep 必须 >= 2，否则回滚后无法再回退到回滚前版本。
 *
 * 详见 docs/wisecms-content-version-design.md
 */
class VersionCleanupCommand extends Command
{
    protected function configure()
    {
        $this->setName('wisecms:version:cleanup')
            ->addOption('keep', null, Option::VALUE_REQUIRED, '每条内容保留的版本条数（0=不限制）')
            ->addOption('content', null, Option::VALUE_REQUIRED, '仅清理指定的内容 ID')
            ->addOption('dry-run', null, Option::VALUE_NONE, '仅统计，不删除')
            ->setDescription('清理内容版本快照（按保留条数裁剪，超出部分物理删除）');
    }

    protected function execute(Input $input, Output $output)
    {
        $service = new ContentVersionService();

        $keepOption = $input->getOption('keep');
        $keep       = $keepOption === null ? $service->keepLimit() : (int) $keepOption;
        $dryRun     = (bool) $input->getOption('dry-run');
        $contentId  = (int) $input->getOption('content');

        if ($keep <= 0) {
            $output->writeln('<comment>保留条数为 0（不限制），无需清理。</comment>');

            return 0;
        }

        if ($keep < 2) {
            $output->writeln('<comment>提示：keep < 2 时，回滚后无法再回退到回滚前版本，请确认。</comment>');
        }

        // 单内容模式
        if ($contentId > 0) {
            $doomed = ContentVersionService::planPrune(
                ContentVersion::where('content_id', $contentId)->column('version'),
                $keep
            );

            if (empty($doomed)) {
                $output->writeln("内容 {$contentId}：无需清理（未超过 {$keep} 条）。");

                return 0;
            }

            if ($dryRun) {
                $output->writeln("<comment>--dry-run：内容 {$contentId} 将删除 " . count($doomed) . " 条快照（保留 {$keep} 条）。</comment>");

                return 0;
            }

            $deleted = $service->prune($contentId, $keep);
            $output->writeln("<info>内容 {$contentId}：已删除 {$deleted} 条快照，保留 {$keep} 条</info>");

            return 0;
        }

        // 全量模式
        $result = $service->pruneAll($keep, $dryRun);

        if ($dryRun) {
            $output->writeln("<comment>--dry-run：将清理 {$result['contents']} 条内容、共 {$result['deleted']} 条快照（每条保留 {$keep} 条）。</comment>");

            return 0;
        }

        $output->writeln("<info>已清理 {$result['contents']} 条内容、共删除 {$result['deleted']} 条快照（每条保留 {$keep} 条）</info>");

        return 0;
    }
}
