<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\job\TaskContext;

/**
 * 字段级变更落库 handler＼�
 *
 * 采集侧只做数组组装并 `QueueService::task(self::HANDLER, ...)` 投递（轻量、不落
 * async_tasks 注册表），本 handler 在 worker 上下文批量写入。
 *
 * 注册位置：`app\AppService::registerQueueHandlers()`（与 export:list 同级）。
 */
class ChangeLogJobService
{
    /** 队列 handler 标识 */
    public const HANDLER = 'changelog:persist';

    /**
     * @param array<string, mixed> $data ['rows' => array<int, array<string, mixed>>]
     * @param TaskContext|null     $context 受管任务上下文（本 handler 为轻量任务，恒为 null）
     * @return array{saved:int}
     */
    public function run(array $data, ?TaskContext $context = null): array
    {
        unset($context);

        $rows = $data['rows'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            return ['saved' => 0];
        }

        return ['saved' => (new ChangeLogService())->persist($rows)];
    }
}
