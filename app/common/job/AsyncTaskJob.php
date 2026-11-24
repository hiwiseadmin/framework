<?php
declare (strict_types = 1);

namespace app\common\job;

use app\common\exception\TaskCanceledException;
use app\common\exception\TaskPausedException;
use app\common\service\AsyncTaskService;
use app\common\service\QueueService;
use think\queue\Job;
use wise\support\context\ContextualLogger;

/**
 * 通用异步任务 Job
 *
 * 用于 QueueService::task() / QueueService::dispatch() 的闭包任务机制。
 * 通过回调标识（handler）查找已注册的回调函数并执行。
 *
 * ── 两种投递形态 ────────────────────────────────────────────────────────
 *   1. 受管任务（QueueService::dispatch()）—— payload 携带 task_id，
 *      本类负责注册表埋点（running / 进度 / 终态）并注入 TaskContext；
 *   2. 普通任务（QueueService::task()）  —— 无 task_id，行为与历史完全一致。
 *
 * ── 协作式停止 ─────────────────────────────────────────────────────────
 *   Handler 在检查点调用 TaskContext::checkpoint()，命中取消标记时抛
 *   TaskCanceledException → 本类置 canceled 并删除队列消息。
 *   未埋检查点的任务无法被停止（think-queue 无中断通道，属架构约束）。
 *
 * 使用示例：
 *   // 受管（可查进度 / 可取消）
 *   QueueService::registerHandler('cms:export', function (array $data, TaskContext $ctx) { ... });
 *   QueueService::instance()->dispatch('cms:export', ['article_id' => 1]);
 *
 *   // 普通（不落注册表）
 *   QueueService::instance()->task('system:cleanup', [...]);
 */
class AsyncTaskJob
{
    /**
     * 最大重试次数
     */
    const MAX_ATTEMPTS = 3;

    /**
     * 首次重试延迟（秒）
     */
    const RETRY_DELAY = 10;

    /**
     * 暂停后重新入队的延迟（秒）
     */
    const PAUSE_RETRY_DELAY = 30;

    /**
     * 执行异步任务
     *
     * @param Job   $job  队列任务实例
     * @param array $data 任务数据，必须包含 handler 键
     */
    public function fire(Job $job, $data)
    {
        $handler = $data['handler'] ?? '';
        $payload = $data['payload'] ?? [];
        $taskId  = (int) ($data['task_id'] ?? 0);
        $taskNo  = (string) ($data['task_no'] ?? '');
        $attempt = $job->attempts();

        if (empty($handler)) {
            ContextualLogger::error('AsyncTaskJob: 缺少 handler 参数', ['queue', 'async_task']);
            $job->delete();
            return;
        }

        if ($taskId > 0 && AsyncTaskService::instance()->controlState($taskId)['cancel']) {
            AsyncTaskService::instance()->markCanceled($taskId, '任务在执行前已被取消');
            ContextualLogger::info("AsyncTaskJob: 执行前已取消，跳过 [{$handler}]", ['queue', 'async_task']);
            $job->delete();
            return;
        }

        $callback = QueueService::getHandler($handler);

        if (!is_callable($callback)) {
            ContextualLogger::error("AsyncTaskJob: 未注册的回调处理器 [{$handler}]", ['queue', 'async_task']);
            if ($taskId > 0) {
                AsyncTaskService::instance()->markFailed($taskId, "未注册的回调处理器 [{$handler}]");
            }
            $job->delete();
            return;
        }

        $context = $taskId > 0 ? new TaskContext($taskId, $taskNo) : null;

        if ($context !== null) {
            AsyncTaskService::instance()->markRunning($taskId, (int) getmypid());
        }

        try {
            ContextualLogger::info("AsyncTaskJob: 开始执行 [{$handler}]", ['queue', 'async_task']);

            $result = $this->invoke($callback, $payload, $context);

            if ($result === false) {
                ContextualLogger::warning("AsyncTaskJob: 处理器显式返回 false [{$handler}]", ['queue', 'async_task']);
                $this->releaseOrFail($job, $attempt, $handler, $taskId, '处理器显式返回 false');
            } else {
                if ($context !== null) {
                    AsyncTaskService::instance()->markSuccess($taskId, $result);
                }
                ContextualLogger::info("AsyncTaskJob: 执行成功 [{$handler}]", ['queue', 'async_task']);
                $job->delete();
            }
        } catch (TaskCanceledException $e) {
            if ($context !== null) {
                AsyncTaskService::instance()->markCanceled($taskId, $e->getMessage());
            }
            ContextualLogger::info("AsyncTaskJob: 已按请求取消 [{$handler}]", ['queue', 'async_task']);
            $job->delete();
        } catch (TaskPausedException $e) {
            if ($context !== null) {
                AsyncTaskService::instance()->markPaused($taskId, $e->getMessage());
            }
            ContextualLogger::info("AsyncTaskJob: 已暂停并重新入队 [{$handler}]", ['queue', 'async_task']);
            $job->release(self::PAUSE_RETRY_DELAY);
        } catch (\Throwable $e) {
            ContextualLogger::error("AsyncTaskJob: 执行异常 [{$handler}] - {$e->getMessage()}", ['queue', 'async_task']);
            $this->releaseOrFail($job, $attempt, $handler, $taskId, $e->getMessage());
        }
    }

    /**
     * 调用 Handler（按形参数量决定是否注入 TaskContext）
     *
     * 说明：PHP 允许向用户函数传入多余实参，但**内部函数**会抛
     * ArgumentCountError；用反射判断更稳，也避免破坏只接收 $data 的历史 Handler。
     *
     * @param callable         $callback
     * @param array            $payload
     * @param TaskContext|null $context
     * @return mixed
     */
    protected function invoke(callable $callback, array $payload, ?TaskContext $context)
    {
        if ($context !== null && $this->acceptsContext($callback)) {
            return call_user_func($callback, $payload, $context);
        }

        return call_user_func($callback, $payload);
    }

    /**
     * Handler 是否接受第二个参数（TaskContext）
     */
    protected function acceptsContext(callable $callback): bool
    {
        try {
            if (is_array($callback)) {
                $ref = new \ReflectionMethod($callback[0], (string) $callback[1]);
            } elseif (is_string($callback) && strpos($callback, '::') !== false) {
                $ref = new \ReflectionMethod($callback);
            } elseif (is_object($callback) && !$callback instanceof \Closure) {
                $ref = new \ReflectionMethod($callback, '__invoke');
            } else {
                $ref = new \ReflectionFunction($callback);
            }

            return $ref->getNumberOfParameters() >= 2 || $ref->isVariadic();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 重试或标记失败
     *
     * @param Job    $job     队列任务实例
     * @param int    $attempt 当前尝试次数
     * @param string $handler 回调标识
     * @param int    $taskId  受管任务 ID（0 = 非受管）
     * @param string $reason  失败原因
     */
    protected function releaseOrFail(Job $job, int $attempt, string $handler, int $taskId = 0, string $reason = ''): void
    {
        if ($attempt >= self::MAX_ATTEMPTS) {
            ContextualLogger::error("AsyncTaskJob: 超过最大重试次数，任务失败 [{$handler}]", ['queue', 'async_task']);

            if ($taskId > 0) {
                AsyncTaskService::instance()->markFailed(
                    $taskId,
                    $reason !== '' ? $reason : '超过最大重试次数'
                );
            }

            $job->failed();
            return;
        }

        $delay = self::RETRY_DELAY * $attempt;

        if ($taskId > 0) {
            AsyncTaskService::instance()->markRetrying(
                $taskId,
                $delay,
                sprintf('第 %d 次尝试失败，%d 秒后重试：%s', $attempt, $delay, $reason)
            );
        }

        $job->release($delay);
    }
}
