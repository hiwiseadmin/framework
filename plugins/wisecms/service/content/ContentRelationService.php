<?php
namespace plugin\wisecms\service\content;

use plugin\wisecms\model\Comment;
use plugin\wisecms\model\CommentLike;
use plugin\wisecms\model\Content;
use plugin\wisecms\model\ContentQuality;
use plugin\wisecms\model\ContentVersion;
use plugin\wisecms\model\Model as ContentModel;
use plugin\wisecms\model\Schedule;
use plugin\wisecms\model\SeoAudit;
use plugin\wisecms\model\SeoPushLog;
use plugin\wisecms\model\TagRelation;
use think\facade\Db;

/**
 * 内容关联数据清理（**应用层级联**，替代外键）
 *
 * 背景：插件全表零外键（历史设计），而删除入口分散在多处，导致
 * `content_quality` / `seo_audit` / `seo_push_log` / `schedule` /
 * `comment`(含 `comment_like`) / 自定义字段副表 在内容被彻底删除后留下孤儿行，
 * 且「单条软删」与「批量软删」对 `tag_relation` 的处理曾不一致。
 *
 * 约定（本类即唯一事实源）：
 *   · **软删除**（回收站）：保留全部关联（供恢复），仅移除 URL 别名索引；
 *   · **彻底删除**（含回收站清空）：调用 {@see purge()} 一次性清理全部关联表；
 *   · 标签引用计数由调用方在清理后重算（见 ContentService::recountTags）。
 *
 * `CONTENT_SCOPED_TABLES` 同时被数据维护命令用于扫描历史孤儿数据。
 */
class ContentRelationService
{
    /**
     * 以 `content_id` 归属内容的关联表（表名 => 外键列）
     *
     * 用于维护命令扫描孤儿行；新增此类关联表时必须同步登记。
     */
    public const CONTENT_SCOPED_TABLES = [
        'wisecms_tag_relation'    => 'content_id',
        'wisecms_comment'         => 'content_id',
        'wisecms_content_quality' => 'content_id',
        'wisecms_seo_audit'       => 'content_id',
        'wisecms_seo_push_log'    => 'content_id',
        'wisecms_schedule'        => 'content_id',
        'wisecms_content_version' => 'content_id',
    ];

    /**
     * 孤儿数据表的中文说明（维护命令 + 后台「系统工具」页共用，不把裸表名抛给用户）
     *
     * 键 = 表名（不含前缀）；动态自定义字段副表（`wisecms_content_{model}_data`）
     * 无法穷举，由 {@see labelOf()} 兜底生成。
     */
    public const ORPHAN_LABELS = [
        'wisecms_tag_relation'    => '内容-标签关联',
        'wisecms_comment_like'    => '评论点赞 / 踩',
        'wisecms_comment'         => '评论',
        'wisecms_content_quality' => '内容质量评分',
        'wisecms_seo_audit'       => 'SEO 审计记录',
        'wisecms_seo_push_log'    => 'SEO 推送日志',
        'wisecms_schedule'        => '定时发布任务',
        'wisecms_content_version' => '内容版本快照',
    ];

    /**
     * 表名 → 可读说明（未登记的动态副表走兜底文案）
     */
    public static function labelOf(string $table): string
    {
        if (isset(self::ORPHAN_LABELS[$table])) {
            return self::ORPHAN_LABELS[$table];
        }

        if (preg_match('/^wisecms_content_[a-z0-9_]+_data$/', $table)) {
            return '自定义字段副表（' . $table . '）';
        }

        return $table;
    }

    /**
     * 彻底清理内容关联数据（务必在内容主表行被物理删除**之前**调用）
     *
     * @param int[] $contentIds 内容 ID（自动去重取整）
     * @return int[] 受影响的标签 ID（供调用方重算 tag.count）
     */
    public function purge(array $contentIds): array
    {
        $contentIds = $this->normalizeIds($contentIds);
        if ($contentIds === []) {
            return [];
        }

        // 受影响标签须在删除关联前取出（删除后无法回溯）
        $tagIds = array_map('intval', TagRelation::whereIn('content_id', $contentIds)->column('tag_id'));

        // 副表表名依赖 model_id，须在内容行仍存在时读取
        $modelIds = array_map('intval', Content::withTrashed()->whereIn('id', $contentIds)->column('model_id'));

        Db::startTrans();
        try {
            $this->purgeComments($contentIds);

            TagRelation::whereIn('content_id', $contentIds)->delete();
            ContentQuality::whereIn('content_id', $contentIds)->delete();
            SeoAudit::whereIn('content_id', $contentIds)->delete();
            SeoPushLog::whereIn('content_id', $contentIds)->delete();
            Schedule::whereIn('content_id', $contentIds)->delete();
            ContentVersion::whereIn('content_id', $contentIds)->delete();

            $this->purgeCustomData($contentIds, $modelIds);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return array_values(array_unique($tagIds));
    }

    /**
     * 评论 + 点赞
     *
     * 点赞以 `comment_id` 归属评论（而非内容），必须先删点赞再删评论，
     * 否则评论被删后点赞行成为永久孤儿。
     */
    protected function purgeComments(array $contentIds): void
    {
        $commentIds = array_map('intval', Comment::whereIn('content_id', $contentIds)->column('id'));
        if ($commentIds === []) {
            return;
        }

        CommentLike::whereIn('comment_id', $commentIds)->delete();
        Comment::whereIn('id', $commentIds)->delete();
    }

    /**
     * 自定义字段副表（`wisecms_content_{model}_data`，主键 `id` 与主表同值）
     *
     * 副表随模型创建（DDL），模型被删除时表已被 DROP，故此处对每个模型做容错。
     *
     * @param int[] $contentIds
     * @param int[] $modelIds
     */
    protected function purgeCustomData(array $contentIds, array $modelIds): void
    {
        foreach (array_unique($modelIds) as $modelId) {
            if ($modelId <= 0) {
                continue;
            }

            $model = ContentModel::find($modelId);
            $table = $model ? (string) $model->table_name : '';
            if ($table === '' || !preg_match('/^wisecms_content_[a-z0-9_]+$/', $table)) {
                continue;
            }

            try {
                Db::name($table . '_data')->whereIn('id', $contentIds)->delete();
            } catch (\Throwable $e) {
                // 副表可能已随模型删除（DROP TABLE）：记录后继续，不阻断主流程
                cms_log('warning', '清理自定义字段副表失败: ' . $e->getMessage(), [
                    'table'      => $table . '_data',
                    'content_ids' => $contentIds,
                ]);
            }
        }
    }

    /**
     * 扫描历史孤儿数据（数据维护命令用）
     *
     * 覆盖：`CONTENT_SCOPED_TABLES`（content_id 悬空）+ `wisecms_comment_like`
     * （comment_id 悬空）+ 各模型自定义字段副表（id 悬空）。
     *
     * @param bool $dryRun true = 只统计不删除
     * @return array<string, int> 表名 => 孤儿行数（dryRun 时即「待清理行数」）
     */
    public function purgeOrphans(bool $dryRun = false): array
    {
        $report = [];
        $prefix = $this->tablePrefix();

        foreach (self::CONTENT_SCOPED_TABLES as $table => $column) {
            $report[$table] = $this->purgeOrphanGroup(
                $prefix . $table,
                '`' . $column . '`',
                $prefix . 'wisecms_content',
                '`id`',
                $dryRun
            );
        }

        // 点赞孤儿：评论已不存在
        $report['wisecms_comment_like'] = $this->purgeOrphanGroup(
            $prefix . 'wisecms_comment_like',
            '`comment_id`',
            $prefix . 'wisecms_comment',
            '`id`',
            $dryRun
        );

        // 自定义字段副表孤儿（主键 id 与主表同值）
        foreach (ContentModel::field('id,table_name')->select()->toArray() as $model) {
            $table = (string) ($model['table_name'] ?? '');
            if ($table === '' || !preg_match('/^wisecms_content_[a-z0-9_]+$/', $table)) {
                continue;
            }

            $report[$table . '_data'] = $this->purgeOrphanGroup(
                $prefix . $table . '_data',
                '`id`',
                $prefix . 'wisecms_content',
                '`id`',
                $dryRun
            );
        }

        return array_filter($report, static fn(int $count): bool => $count > 0);
    }

    /**
     * 单表孤儿统计 / 清理（LEFT JOIN 反查悬空外键）
     *
     * @return int 孤儿行数（清理模式下为实际删除行数）
     */
    protected function purgeOrphanGroup(
        string $table,
        string $fkColumn,
        string $refTable,
        string $refColumn,
        bool $dryRun
    ): int {
        $where = "r.{$refColumn} IS NULL";

        try {
            if ($dryRun) {
                $rows = Db::query(
                    "SELECT COUNT(*) AS c FROM `{$table}` t"
                    . " LEFT JOIN `{$refTable}` r ON r.{$refColumn} = t.{$fkColumn}"
                    . " WHERE {$where}"
                );

                return (int) ($rows[0]['c'] ?? 0);
            }

            return (int) Db::execute(
                "DELETE t FROM `{$table}` t"
                . " LEFT JOIN `{$refTable}` r ON r.{$refColumn} = t.{$fkColumn}"
                . " WHERE {$where}"
            );
        } catch (\Throwable $e) {
            // 表可能不存在（副表随模型删除）：记录后跳过，不阻断维护流程
            cms_log('warning', '孤儿数据扫描失败: ' . $e->getMessage(), ['table' => $table]);

            return 0;
        }
    }

    /**
     * @param mixed $ids
     * @return int[]
     */
    protected function normalizeIds($ids): array
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    protected function tablePrefix(): string
    {
        return (string) config('database.connections.mysql.prefix', 'ws_');
    }
}
