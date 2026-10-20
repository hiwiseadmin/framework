<?php

declare(strict_types=1);

namespace plugin\wisedocs\service;

use plugin\wisedocs\model\Document as DocumentModel;
use plugin\wisedocs\model\Feedback as FeedbackModel;
use think\facade\Db;
use think\facade\Log;

/**
 * 统计服务（P4-2）
 *
 * 原实现 `Stat::recordView()` / `recordFeedback()` 从未被任何控制器调用，
 * 统计表始终为空；且其「先查后插」在并发下会产生重复行。
 *
 * 本服务改为 `INSERT ... ON DUPLICATE KEY UPDATE` 原子累加（依赖 P1-3 建立的
 * `(document_id, date)` 唯一索引），同时维护 `documents` 表的冗余计数。
 */
class StatService
{
    protected const TABLE = 'wisedocs_stats';

    /** 可自增列白名单（防注入；列名来自调用方常量而非用户输入） */
    protected const COLUMNS = ['view_count', 'helpful_count', 'unhelpful_count', 'share_count'];

    /**
     * 记录浏览（按 文档 × 天）
     */
    public function recordView(int $documentId): void
    {
        if ($documentId <= 0) {
            return;
        }

        $this->bump($documentId, 'view_count');
        DocumentModel::where('id', $documentId)->inc('view_count')->update();
    }

    /**
     * 记录反馈（有用 / 无用）
     */
    public function recordFeedback(int $documentId, int $type): void
    {
        if ($documentId <= 0) {
            return;
        }

        if ($type === FeedbackModel::TYPE_HELPFUL) {
            $this->bump($documentId, 'helpful_count');
            DocumentModel::where('id', $documentId)->inc('helpful_count')->update();
        } elseif ($type === FeedbackModel::TYPE_UNHELPFUL) {
            $this->bump($documentId, 'unhelpful_count');
            DocumentModel::where('id', $documentId)->inc('unhelpful_count')->update();
        }
    }

    /**
     * 记录分享
     */
    public function recordShare(int $documentId): void
    {
        if ($documentId <= 0) {
            return;
        }

        $this->bump($documentId, 'share_count');
    }

    /**
     * 原子累加「文档 × 天」统计行
     */
    protected function bump(int $documentId, string $column): void
    {
        if (!in_array($column, self::COLUMNS, true)) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        try {
            $table = (string) Db::name(self::TABLE)->getTable();

            Db::execute(
                "INSERT INTO `{$table}` " .
                "(`document_id`, `date`, `view_count`, `helpful_count`, `unhelpful_count`, `share_count`, `create_time`, `update_time`) " .
                'VALUES (?, ?, 0, 0, 0, 0, ?, ?) ' .
                "ON DUPLICATE KEY UPDATE `{$column}` = `{$column}` + 1, `update_time` = VALUES(`update_time`)",
                [$documentId, date('Y-m-d'), $now, $now]
            );
        } catch (\Throwable $e) {
            // 统计失败不影响主流程（如唯一索引尚未建立时）
            Log::warning('[wisedocs] stat bump failed: ' . $e->getMessage());
        }
    }
}
