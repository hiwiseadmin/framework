<?php
declare(strict_types=1);

namespace app\common\exception;

use RuntimeException;

/**
 * 任务取消（协作式）
 *
 * 由 TaskContext::checkpoint() 在检测到 cancel_requested 标记后抛出，
 * 由 AsyncTaskJob 捕获并把任务置为 canceled、删除队列消息。
 *
 * ⚠️ 控制流异常，**仅**用于任务执行体内部；不会冒泡到全局异常处理器。
 */
class TaskCanceledException extends RuntimeException
{
    public function __construct(string $message = '任务已被取消')
    {
        parent::__construct($message);
    }
}
