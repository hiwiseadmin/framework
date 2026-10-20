<?php
namespace plugin\wisecms\command;

use plugin\wisecms\model\Comment;
use plugin\wisecms\model\CommentLike;
use plugin\wisecms\model\Content;
use plugin\wisecms\model\SeoAudit;
use plugin\wisecms\model\Tag;
use plugin\wisecms\model\TagRelation;
use plugin\wisecms\service\content\ContentRelationService;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\site\CacheService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 数据层维护（P1-C）
 *
 *   php think wisecms:maintain --dry-run     # 只统计不写入
 *   php think wisecms:maintain               # 执行
 *   php think wisecms:maintain --metrics     # 额外回填 content.word_count / read_time
 *
 * 覆盖四类历史不一致：
 *   ① 标签引用计数（`tag.count`）—— 口径：已发布 + 未回收，与标签页可见列表一致；
 *   ② 孤儿数据 —— 关联表 `content_id` 悬空 / 点赞 `comment_id` 悬空 / 副表 `id` 悬空；
 *   ③ 评论点赞·踩计数（`comment.like_count` / `dislike_count`）；
 *   ④ SEO 审计历史行裁剪（每个内容只保留最近 N 条，防全站重审导致表膨胀）。
 *
 * 建议在低峰期执行；`--dry-run` 可先看清影响面。
 */
class MaintainCommand extends Command
{
    protected function configure()
    {
        $this->setName('wisecms:maintain')
            ->addOption('dry-run', null, Option::VALUE_NONE, '只统计不写入')
            ->addOption('keep-audit', null, Option::VALUE_REQUIRED, '每个内容保留的 SEO 审计行数（默认 5）', '5')
            ->addOption('metrics', null, Option::VALUE_NONE, '回填字数为空的 content.word_count / read_time')
            ->setDescription('数据层维护：标签计数重算 / 孤儿数据清理 / 评论计数重算 / 审计行裁剪');
    }

    protected function execute(Input $input, Output $output)
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $keep   = max(1, (int) $input->getOption('keep-audit'));

        $output->writeln($dryRun
            ? '<comment>DRY RUN：只统计不写入</comment>'
            : '<info>开始数据层维护</info>');

        $this->maintainTagCount($output, $dryRun);
        $this->maintainOrphans($output, $dryRun);
        $this->maintainCommentCounters($output, $dryRun);
        $this->pruneSeoAudit($output, $dryRun, $keep);

        if ((bool) $input->getOption('metrics')) {
            $this->maintainContentMetrics($output, $dryRun);
        }

        if (!$dryRun) {
            (new CacheService())->flush();
            $output->writeln('<info>完成（插件缓存已整体失效）</info>');
        }
    }

    /** ① 标签引用计数全量重算 */
    protected function maintainTagCount(Output $output, bool $dryRun): void
    {
        $tagIds = array_map('intval', Tag::column('id'));
        if ($tagIds === []) {
            return;
        }

        if ($dryRun) {
            $output->writeln('  ① 标签计数：待重算 ' . count($tagIds) . ' 个标签');

            return;
        }

        (new ContentService())->recountTags($tagIds);
        $output->writeln('  ① 标签计数：已重算 ' . count($tagIds) . ' 个标签');
    }

    /** ② 孤儿数据统计 / 清理 */
    protected function maintainOrphans(Output $output, bool $dryRun): void
    {
        $report = (new ContentRelationService())->purgeOrphans($dryRun);

        if ($report === []) {
            $output->writeln('  ② 孤儿数据：无');

            return;
        }

        foreach ($report as $table => $count) {
            // 表名 + 可读说明：与后台「系统工具」页共用同一映射（ContentRelationService::ORPHAN_LABELS）
            $output->writeln('     - ' . str_pad($table, 26) . $count . ' 行  '
                . ContentRelationService::labelOf($table));
        }
        $output->writeln('  ② 孤儿数据：' . ($dryRun ? '待清理 ' : '已清理 ') . array_sum($report) . ' 行');
    }

    /** ③ 评论点赞 / 踩计数重算（只更新不一致的行） */
    protected function maintainCommentCounters(Output $output, bool $dryRun): void
    {
        $rows = CommentLike::field('comment_id, type, COUNT(*) AS c')
            ->group('comment_id, type')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['comment_id']][(int) $row['type']] = (int) $row['c'];
        }

        $fixed = 0;
        $scanned = 0;

        Comment::chunk(500, function ($comments) use ($map, $dryRun, &$fixed, &$scanned): void {
            foreach ($comments as $comment) {
                $scanned++;
                $likes    = $map[(int) $comment->id][1] ?? 0;
                $dislikes = $map[(int) $comment->id][0] ?? 0;

                if ($likes === (int) $comment->like_count && $dislikes === (int) $comment->dislike_count) {
                    continue;
                }

                if (!$dryRun) {
                    Comment::where('id', (int) $comment->id)
                        ->update(['like_count' => $likes, 'dislike_count' => $dislikes]);
                }
                $fixed++;
            }
        });

        $output->writeln(sprintf(
            '  ③ 评论计数：扫描 %d 条，%s %d 条',
            $scanned,
            $dryRun ? '待修正' : '已修正',
            $fixed
        ));
    }

    /** ④ SEO 审计历史行裁剪（每内容保留最近 N 条） */
    protected function pruneSeoAudit(Output $output, bool $dryRun, int $keep): void
    {
        $groups = SeoAudit::field('content_id, COUNT(*) AS c')
            ->group('content_id')
            ->having('c > ' . $keep)
            ->select()
            ->toArray();

        $deleted = 0;
        foreach ($groups as $group) {
            $contentId = (int) $group['content_id'];
            $keepIds   = SeoAudit::where('content_id', $contentId)->order('id', 'desc')->limit($keep)->column('id');
            if ($keepIds === []) {
                continue;
            }

            if ($dryRun) {
                $deleted += (int) $group['c'] - count($keepIds);
                continue;
            }

            $deleted += (int) SeoAudit::where('content_id', $contentId)->whereNotIn('id', $keepIds)->delete();
        }

        $output->writeln(sprintf(
            '  ④ 审计行裁剪：%d 个内容超限，%s %d 行（保留最近 %d 条）',
            count($groups),
            $dryRun ? '待清理' : '已清理',
            $deleted,
            $keep
        ));
    }

    /** ⑤ 回填 content.word_count / read_time（历史数据补齐，可选） */
    protected function maintainContentMetrics(Output $output, bool $dryRun): void
    {
        $fixed = 0;

        Content::withTrashed()
            ->where('word_count', 0)
            ->whereNotNull('content')
            ->where('content', '<>', '')
            ->chunkById(200, function ($contents) use ($dryRun, &$fixed): void {
                foreach ($contents as $content) {
                    $wordCount = mb_strlen(strip_tags((string) $content->content));
                    if ($wordCount <= 0) {
                        continue;
                    }

                    if (!$dryRun) {
                        Content::withTrashed()->where('id', (int) $content->id)->update([
                            'word_count' => $wordCount,
                            'read_time'  => (int) ceil($wordCount / 500),
                        ]);
                    }
                    $fixed++;
                }
            });

        $output->writeln('  ⑤ 字数回填：' . ($dryRun ? '待回填 ' : '已回填 ') . $fixed . ' 篇');
    }
}
