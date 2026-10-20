<?php
namespace plugin\wisecms\command;

use plugin\wisecms\model\Tag;
use plugin\wisecms\service\content\TagService;
use plugin\wisecms\service\url\UrlService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 回填标签 URL 别名（A-2 / P5）
 *
 * 存量标签的 `wisecms_tag.alias` 从未被写入（恒为 ''），导致伪静态开启后标签页
 * 只能退化为查询串 URL。本命令按 slug 规则批量回填：
 *
 *   php think wisecms:tag:alias              仅回填空别名（默认，安全）
 *   php think wisecms:tag:alias --force      按当前标签名重新生成（覆盖已有别名）
 *   php think wisecms:tag:alias --dry-run    仅预览，不写库
 *
 * 别名规则（详见 docs/wisecms-recommend-design.md §5.4）：
 *   小写 + 非 [a-z0-9] 转 '-' + 去首尾 '-'；纯中文回退 `tag-{id}`；冲突追加 -2/-3。
 *
 * 按主键游标分批处理，避免全表载入内存；回填后可执行 URL 索引重建使伪静态生效。
 */
class TagAliasCommand extends Command
{
    protected function configure()
    {
        $this->setName('wisecms:tag:alias')
            ->addOption('dry-run', null, Option::VALUE_NONE, '仅预览，不写库')
            ->addOption('force', null, Option::VALUE_NONE, '按当前标签名重新生成（覆盖已有别名）')
            ->setDescription('回填标签 URL 别名（slug 化，空则 tag-{id}）');
    }

    protected function execute(Input $input, Output $output)
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');

        $urls    = new UrlService();
        $updated = 0;
        $skipped = 0;
        $failed  = 0;
        $lastId  = 0;

        // 主键游标分批，避免全表载入内存
        while (true) {
            $tags = Tag::where('id', '>', $lastId)->order('id', 'asc')->limit(200)->select();
            if ($tags->isEmpty()) {
                break;
            }

            foreach ($tags as $tag) {
                $lastId = (int) $tag->id;

                if (!$force && (string) $tag->alias !== '') {
                    $skipped++;
                    continue;
                }

                // P2 标签站点隔离：别名唯一性收敛到站点，故必须以**标签所属站点**去重
                // （makeAlias 第 2 参是 siteId、第 3 参才是 excludeId，切勿只传 $lastId）
                $siteId = max(1, (int) $tag->site_id);
                $alias  = TagService::makeAlias((string) $tag->name, $siteId, $lastId);
                if ($alias === '') {
                    $alias = 'tag-' . $lastId;
                }

                if ($alias === (string) $tag->alias) {
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $output->writeln("  #{$lastId} {$tag->name} => {$alias}");
                    $updated++;
                    continue;
                }

                try {
                    $tag->save(['alias' => $alias]);
                    $urls->syncTag($tag);
                    $updated++;
                } catch (\Throwable $e) {
                    $failed++;
                    cms_log('warning', '标签别名回填失败', [
                        'tag_id' => $lastId,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }
        }

        $verb = $dryRun ? '待更新' : '已更新';
        $output->writeln("<info>{$verb} {$updated} 条；跳过 {$skipped} 条</info>");
        if ($failed > 0) {
            $output->writeln("<error>失败 {$failed} 条（详见日志）</error>");
        }
        if ($dryRun) {
            $output->writeln('<comment>--dry-run：未写库。</comment>');
        }

        return 0;
    }
}
