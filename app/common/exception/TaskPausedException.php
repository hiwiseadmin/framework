<?php
declare(strict_types=1);

namespace app\common\exception;

use RuntimeException;

/**
 * 任务暂停（协作式，Phase 2 预留）
 *
 * 由 TaskContext::checkpoint() 在检测到 pause_requested 标记后抛出，
 * 由 AsyncTaskJob 捕获并把任务置为 paused、以 $job->release($delay) 重新入队。
 *
 * ⚠️ 恢复语义依赖 Handler **可重入**（幂等或支持从断点续跑）；
 * 从零重跑的 Handler 请勿启用暂停能力。
 */
class TaskPausedException extends RuntimeException
{
    public function __construct(string $message = '任务已暂停')
    {
        parent::__construct($message);
    }
}
