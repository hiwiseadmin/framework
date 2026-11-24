<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\AsyncTask;
use think\facade\Config;
use think\facade\Db;
use wise\support\context\ContextualLogger;

/**
 * 异步任务注册表服务（进度查询 / 取消控制面）
 *
 * ── 定位 ────────────────────────────────────────────────────────────────
 * think-queue 是「传输层」：只保证把消息从生产端送到消费端，成功即删除记录，
 * 没有任何 status/progress 字段，也没有「停止某个任务」的通道。
 * 本服务在其之上维护一张独立注册表 `async_tasks`，提供：
 *   - 进度查询（按任务 ID / 任务号）
 *   - 协作式取消（写 cancel_requested，任务在检查点自行退出）
 *
 * ── 读写策略 ────────────────────────────────────────────────────────────
 *   - 读：走 ORM（自动应用软删除 scope）；
 *   - 热路径写（进度 / 心跳 / 状态流转）：走 `Db::name()` 轻量更新，
 *     显式写 update_time（原生 SQL 不触发模型自动时间戳）。
 *
 * ── 硬边界（务必知悉）──────────────────────────────────────────────────
 *   「停止」是**协作式**的：只对在 Handler 中调用 TaskContext::checkpoint()
 *   的任务生效。不埋检查点的普通 Job 无法被停止，也不建议纳入本注册表。
 *
 * @see \app\common\job\TaskContext   Handler 侧协作协议
 * @see \app\common\job\AsyncTaskJob  执行侧埋点
 */
class AsyncTaskService
{
    /** 进度回写最小间隔（秒）：防止高频 report 打爆数据库 */
    public const REPORT_INTERVAL = 1.0;

    /** 取消/暂停标记检查最小间隔（秒） */
    public const CHECK_INTERVAL = 2.0;

    /** 心跳超时阈值（秒）：超过则判定为 stale（疑似卡死） */
    public const STALE_SECONDS = 900;

    /** 错误信息落库截断长度 */
    private const ERROR_MAX_LENGTH = 2000;

    /** 注册表逻辑表名（不含前缀，与 Db::name() 口径一致） */
    private const TABLE = 'async_tasks';

    /** @var static|null 单例 */
    protected static $instance = null;

    public static function instance(): self
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    protected function __construct()
    {
    }

    protected function __clone()
    {
    }


    /**
     * 创建任务记录（状态 pending）
     *
     * @param string               $handler 回调标识
     * @param array<string, mixed> $payload 任务载荷
     * @param array<string, mixed> $options name / queue / connection / created_by
     * @return AsyncTask
     * @throws \RuntimeException 落库失败
     */
    public function create(string $handler, array $payload = [], array $options = []): AsyncTask
    {
        $task = new AsyncTask();
        $task->save([
            'task_no'    => $this->generateTaskNo(),
            'handler'    => $handler,
            'name'       => (string) ($options['name'] ?? '') !== '' ? (string) $options['name'] : $handler,
            'queue'      => (string) ($options['queue'] ?? $this->queueConfig('connections.' . $this->defaultConnection() . '.queue', 'default')),
            'connection' => (string) ($options['connection'] ?? $this->defaultConnection()),
            'status'     => AsyncTask::STATUS_PENDING,
            'progress'   => 0,
            'total'      => 0,
            'done'       => 0,
            'message'    => '',
            'payload'    => $this->encodePayload($payload),
            'pid'        => 0,
            'created_by' => (int) ($options['created_by'] ?? $this->currentAdminId()),
        ]);

        return $task;
    }

    /**
     * 生成对外任务号（1 前缀 + 时间 + 随机，便于人工识别与检索）
     */
    public function generateTaskNo(): string
    {
        return 'T' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
    }


    /**
     * 置为执行中（幂等；首次写入 started_at）
     */
    public function markRunning(int $id, int $pid = 0): void
    {
        $this->guard(function () use ($id, $pid) {
            $now = date('Y-m-d H:i:s');

            $current = Db::name($this->table())->where('id', $id)->find();
            if (!$current) {
                return;
            }

            $data = [
                'status'       => AsyncTask::STATUS_RUNNING,
                'pid'          => $pid,
                'heartbeat_at' => $now,
                'update_time'  => $now,
            ];

            if (empty($current['started_at'])) {
                $data['started_at'] = $now;
            }

            Db::name($this->table())->where('id', $id)->update($data);
        }, 'markRunning');
    }

    /**
     * 上报进度（Handler 经 TaskContext 调用，已在外层做过节流）
     *
     * @param int    $id      任务 ID
     * @param int    $done    已完成数
     * @param int    $total   总数（0 = 未知 → progress 记 -1）
     * @param string $message 进度消息
     */
    public function report(int $id, int $done, int $total = 0, string $message = ''): void
    {
        $this->guard(function () use ($id, $done, $total, $message) {
            $now = date('Y-m-d H:i:s');

            $data = [
                'done'         => max(0, $done),
                'heartbeat_at' => $now,
                'update_time'  => $now,
            ];

            if ($total > 0) {
                $data['total']    = $total;
                $data['progress'] = max(0, min(100, (int) floor($done / $total * 100)));
            } else {
                $data['progress'] = AsyncTask::PROGRESS_INDETERMINATE;
            }

            if ($message !== '') {
                $data['message'] = mb_substr($message, 0, 500);
            }

            Db::name($this->table())->where('id', $id)->update($data);
        }, 'report');
    }

    /**
     * 心跳续期（不改变进度，仅防误判卡死）
     */
    public function touch(int $id): void
    {
        $this->guard(function () use ($id) {
            $now = date('Y-m-d H:i:s');
            Db::name($this->table())->where('id', $id)->update([
                'heartbeat_at' => $now,
                'update_time'  => $now,
            ]);
        }, 'touch');
    }

    /**
     * 置为成功
     *
     * @param mixed $result 结果留痕：
     *                      - true / null        → 不写 result
     *                      - 数组               → 原样存为 JSON
     *                      - 其它标量/对象      → 包成 {result: ...}
     */
    public function markSuccess(int $id, $result = null): void
    {
        $this->guard(function () use ($id, $result) {
            $now = date('Y-m-d H:i:s');

            if ($result === true || $result === null) {
                $encoded = null;
            } elseif (is_array($result)) {
                $encoded = $this->encodePayload($result);
            } else {
                $encoded = $this->encodePayload(['result' => $result]);
            }

            Db::name($this->table())->where('id', $id)->update([
                'status'       => AsyncTask::STATUS_SUCCESS,
                'progress'     => 100,
                'result'       => $encoded,
                'error'        => null,
                'finished_at'  => $now,
                'heartbeat_at' => $now,
                'update_time'  => $now,
            ]);
        }, 'markSuccess');
    }

    /**
     * 置为「等待重试」（本次尝试失败但未达上限）
     *
     * 前台表现为回到等待中，并展示下次重试时间。
     */
    public function markRetrying(int $id, int $delay, string $message = ''): void
    {
        $this->guard(function () use ($id, $delay, $message) {
            $now = date('Y-m-d H:i:s');

            $data = [
                'status'      => AsyncTask::STATUS_PENDING,
                'message'     => mb_substr(
                    $message !== '' ? $message : ('本次尝试失败，' . $delay . ' 秒后重试'),
                    0,
                    500
                ),
                'update_time' => $now,
            ];

            Db::name($this->table())->where('id', $id)->update($data);
        }, 'markRetrying');
    }

    /**
     * 置为失败
     */
    public function markFailed(int $id, string $error): void
    {
        $this->guard(function () use ($id, $error) {
            $now = date('Y-m-d H:i:s');

            Db::name($this->table())->where('id', $id)->update([
                'status'      => AsyncTask::STATUS_FAILED,
                'error'       => mb_substr($error, 0, self::ERROR_MAX_LENGTH),
                'finished_at' => $now,
                'update_time' => $now,
            ]);
        }, 'markFailed');
    }

    /**
     * 置为已取消
     */
    public function markCanceled(int $id, string $message = '任务已被取消'): void
    {
        $this->guard(function () use ($id, $message) {
            $now = date('Y-m-d H:i:s');

            Db::name($this->table())->where('id', $id)->update([
                'status'      => AsyncTask::STATUS_CANCELED,
                'message'     => mb_substr($message, 0, 500),
                'finished_at' => $now,
                'update_time' => $now,
            ]);
        }, 'markCanceled');
    }

    /**
     * 置为已暂停（Phase 2）
     */
    public function markPaused(int $id, string $message = '任务已暂停'): void
    {
        $this->guard(function () use ($id, $message) {
            $now = date('Y-m-d H:i:s');

            Db::name($this->table())->where('id', $id)->update([
                'status'       => AsyncTask::STATUS_PAUSED,
                'message'      => mb_substr($message, 0, 500),
                'heartbeat_at' => $now,
                'update_time'  => $now,
            ]);
        }, 'markPaused');
    }


    /**
     * 请求取消任务
     *
     * 只写标记，不直接中断执行体；Handler 在下一个检查点自行退出。
     *
     * @return bool 任务存在且处于活跃状态时返回 true
     */
    public function requestCancel(int $id): bool
    {
        return $this->requestFlag($id, 'cancel_requested');
    }

    /**
     * 请求暂停任务（Phase 2）
     */
    public function requestPause(int $id): bool
    {
        return $this->requestFlag($id, 'pause_requested');
    }

    /**
     * 读取取消/暂停标记（单次查询同时取两列，供 checkpoint 用）
     *
     * @return array{cancel:bool, pause:bool}
     */
    public function controlState(int $id): array
    {
        try {
            $row = Db::name($this->table())
                ->field('cancel_requested,pause_requested,status')
                ->where('id', $id)
                ->find();
        } catch (\Throwable $e) {
            return ['cancel' => false, 'pause' => false];
        }

        if (!$row) {
            return ['cancel' => true, 'pause' => false];
        }

        return [
            'cancel' => (int) ($row['cancel_requested'] ?? 0) === 1,
            'pause'  => (int) ($row['pause_requested'] ?? 0) === 1,
        ];
    }


    public function find(int $id): ?AsyncTask
    {
        if ($id <= 0) {
            return null;
        }

        $task = AsyncTask::find($id);

        return $task ?: null;
    }

    public function findByTaskNo(string $taskNo): ?AsyncTask
    {
        $taskNo = trim($taskNo);
        if ($taskNo === '') {
            return null;
        }

        $task = AsyncTask::where('task_no', $taskNo)->find();

        return $task ?: null;
    }

    /**
     * 进度查询（供后台轮询）
     *
     * @return array<string, mixed>
     */
    public function progress(int $id): array
    {
        $task = $this->find($id);
        if ($task === null) {
            return ['found' => false];
        }

        return [
            'found'         => true,
            'id'            => (int) $task->getAttr('id'),
            'task_no'       => (string) $task->getAttr('task_no'),
            'handler'       => (string) $task->getAttr('handler'),
            'name'          => (string) $task->getAttr('name'),
            'status'        => (string) $task->getAttr('status'),
            'status_text'   => $task->getStatusTextAttr(),
            'progress'      => (int) $task->getAttr('progress'),
            'progress_percent' => $task->getProgressPercentAttr(),
            'total'         => (int) $task->getAttr('total'),
            'done'          => (int) $task->getAttr('done'),
            'message'       => (string) $task->getAttr('message'),
            'error'         => (string) ($task->getAttr('error') ?? ''),
            'finished'      => AsyncTask::isFinished((string) $task->getAttr('status')),
            'finished_at'   => (string) ($task->getAttr('finished_at') ?? ''),
        ];
    }

    /**
     * 状态统计（任务中心顶部概览）
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        $stats = [
            'total'    => 0,
            'pending'  => 0,
            'running'  => 0,
            'paused'   => 0,
            'success'  => 0,
            'failed'   => 0,
            'canceled' => 0,
            'stale'    => 0,
        ];

        try {
            $rows = AsyncTask::field('status, COUNT(*) AS num')
                ->group('status')
                ->select()
                ->toArray();

            foreach ($rows as $row) {
                $status = (string) ($row['status'] ?? '');
                $num    = (int) ($row['num'] ?? 0);

                if (isset($stats[$status])) {
                    $stats[$status] = $num;
                }
                $stats['total'] += $num;
            }
        } catch (\Throwable $e) {
            ContextualLogger::error('AsyncTaskService::stats 失败: ' . $e->getMessage(), ['async_task']);
        }

        return $stats;
    }


    /**
     * 回收疑似卡死任务（心跳超时）
     *
     * ⚠️ 只能依赖心跳：未调用 TaskContext::progress()/checkpoint() 的长任务
     * 不会续期心跳，请相应调大 $seconds 或让 Handler 定期 touch()。
     *
     * @return int 受影响行数
     */
    public function reapStale(int $seconds = self::STALE_SECONDS): int
    {
        try {
            $now       = date('Y-m-d H:i:s');
            $threshold = date('Y-m-d H:i:s', time() - max(60, $seconds));

            return (int) Db::name($this->table())
                ->where('status', AsyncTask::STATUS_RUNNING)
                ->where(function ($query) use ($threshold) {
                    $query->whereNull('heartbeat_at')->whereOr('heartbeat_at', '<', $threshold);
                })
                ->update([
                    'status'      => AsyncTask::STATUS_STALE,
                    'error'       => '心跳超时，判定为卡死（阈值 ' . $seconds . ' 秒）',
                    'finished_at' => $now,
                    'update_time' => $now,
                ]);
        } catch (\Throwable $e) {
            ContextualLogger::error('AsyncTaskService::reapStale 失败: ' . $e->getMessage(), ['async_task']);
            return 0;
        }
    }

    /**
     * 软删除已结束的历史任务
     *
     * @param int $days 保留天数
     * @return int 删除条数
     */
    public function purgeFinished(int $days = 7): int
    {
        $days = max(1, $days);

        try {
            $threshold = date('Y-m-d H:i:s', time() - $days * 86400);
            $tasks = AsyncTask::whereIn('status', [
                AsyncTask::STATUS_SUCCESS,
                AsyncTask::STATUS_FAILED,
                AsyncTask::STATUS_CANCELED,
                AsyncTask::STATUS_STALE,
            ])->where('update_time', '<', $threshold)->select();

            $count = 0;
            foreach ($tasks as $task) {
                $task->delete();
                $count++;
            }

            return $count;
        } catch (\Throwable $e) {
            ContextualLogger::error('AsyncTaskService::purgeFinished 失败: ' . $e->getMessage(), ['async_task']);
            return 0;
        }
    }


    /**
     * 写单个控制标记
     */
    protected function requestFlag(int $id, string $field): bool
    {
        try {
            $row = Db::name($this->table())->field('id,status')->where('id', $id)->find();
            if (!$row) {
                return false;
            }

            if (AsyncTask::isFinished((string) $row['status'])) {
                return false;
            }

            Db::name($this->table())->where('id', $id)->update([
                $field        => 1,
                'update_time' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Throwable $e) {
            ContextualLogger::error("AsyncTaskService::requestFlag({$field}) 失败: " . $e->getMessage(), ['async_task']);
            return false;
        }
    }

    /**
     * 热路径写入兜底：任何异常都不得影响任务执行
     */
    protected function guard(callable $callback, string $scene): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            try {
                ContextualLogger::error("AsyncTaskService::{$scene} 失败: " . $e->getMessage(), ['async_task']);
            } catch (\Throwable) {
            }
        }
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function defaultConnection(): string
    {
        return (string) (Config::get('queue.default') ?: 'database');
    }

    /**
     * 读取队列配置（脱离框架时降级为默认值）
     */
    protected function queueConfig(string $key, $default = null)
    {
        try {
            return Config::get('queue.' . $key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * 载荷 JSON 编码（失败时降级为 null，不阻断任务）
     */
    protected function encodePayload($payload): ?string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 当前后台操作人（CLI/队列环境返回 0）
     */
    protected function currentAdminId(): int
    {
        try {
            if (function_exists('auth_id')) {
                return (int) (auth_id('admin') ?: 0);
            }
        } catch (\Throwable) {
        }

        return 0;
    }
}
