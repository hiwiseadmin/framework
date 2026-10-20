<?php
declare(strict_types=1);

namespace app\common\job;

use app\common\exception\TaskCanceledException;
use app\common\exception\TaskPausedException;
use app\common\service\AsyncTaskService;

/**
 * 任务上下文 —— Handler 侧协作协议
 *
 * think-queue 只负责投递，不提供进度回写与中断通道。任何「进度可见 / 可停止」
 * 的能力都必须由**任务自己配合**：在关键位置调用本对象的 progress() 上报进度、
 * 调用 checkpoint() 接受取消指令。这是架构约束，不是实现取舍。
 *
 * ── 典型用法 ────────────────────────────────────────────────────────────
 * <pre>
 * QueueService::registerHandler('cms:export', function (array $data, TaskContext $ctx) {
 *     $rows = fetchAll();
 *     $ctx->setTotal(count($rows));
 *
 *     foreach ($rows as $i => $row) {
 *         $ctx->checkpoint();          // 命中取消 → 抛 TaskCanceledException（框架会置 canceled）
 *         export($row);
 *         $ctx->progress($i + 1);      // 内部按 REPORT_INTERVAL 节流，可放心高频调用
 *     }
 * });
 * </pre>
 *
 * ── 成本 ────────────────────────────────────────────────────────────────
 *   - progress()   写库，默认最快 1 秒一次；
 *   - checkpoint() 读库，默认最快 2 秒一次；心跳最快 60 秒续一次。
 *   即单个任务的开销上界约「每 2 秒 1 次读 + 每 1 秒 1 次写」，与任务体量无关。
 *
 * @see \app\common\job\AsyncTaskJob    执行侧：注入本对象并捕获控制流异常
 * @see \app\common\service\AsyncTaskService
 */
class TaskContext
{
    /** 心跳续期间隔（秒）：checkpoint 顺带续心跳的最快频率 */
    private const HEARTBEAT_INTERVAL = 60.0;

    /** 任务 ID */
    protected int $taskId;

    /** 任务编号（对外可见） */
    protected string $taskNo;

    /** 上次进度回写时间戳（微秒） */
    protected float $lastReportAt = 0.0;

    /** 上次取消标记检查时间戳（微秒） */
    protected float $lastCheckAt = 0.0;

    /** 上次心跳续期时间戳（微秒） */
    protected float $lastHeartbeatAt = 0.0;

    /** 最近一次上报的已完成数 */
    protected int $lastDone = 0;

    /** 最近一次上报的总数 */
    protected int $lastTotal = 0;

    public function __construct(int $taskId, string $taskNo = '')
    {
        $this->taskId = $taskId;
        $this->taskNo = $taskNo;
    }

    public function taskId(): int
    {
        return $this->taskId;
    }

    public function taskNo(): string
    {
        return $this->taskNo;
    }

    /**
     * 声明总量（用于计算百分比；未知则不要调用）
     */
    public function setTotal(int $total): void
    {
        $this->lastTotal = max(0, $total);
        $this->forceReport();
    }

    /**
     * 上报进度
     *
     * @param int         $done    已完成数
     * @param int|null    $total   总数（null = 沿用上次 setTotal 的值）
     * @param string      $message 进度消息
     * @param bool        $force   忽略节流立即回写
     */
    public function progress(int $done, ?int $total = null, string $message = '', bool $force = false): void
    {
        $this->lastDone = max(0, $done);

        if ($total !== null) {
            $this->lastTotal = max(0, $total);
        }

        // 收尾必写：避免节流导致「已完成数」长期滞后于真实状态
        $isFinal = $this->lastTotal > 0 && $this->lastDone >= $this->lastTotal;

        if (!$force && !$isFinal && !$this->elapsed($this->lastReportAt, AsyncTaskService::REPORT_INTERVAL)) {
            return;
        }

        $this->lastReportAt = microtime(true);

        AsyncTaskService::instance()->report($this->taskId, $this->lastDone, $this->lastTotal, $message);
    }

    /**
     * 更新进度消息（不改变计数）
     */
    public function log(string $message): void
    {
        $this->progress($this->lastDone, null, $message, true);
    }

    /**
     * 检查点：接受取消 / 暂停指令，并顺带续心跳
     *
     * 命中取消 → 抛 TaskCanceledException
     * 命中暂停 → 抛 TaskPausedException
     *
     * @param bool $force 忽略节流立即检查
     *
     * @throws TaskCanceledException
     * @throws TaskPausedException
     */
    public function checkpoint(bool $force = false): void
    {
        if (!$force && !$this->elapsed($this->lastCheckAt, AsyncTaskService::CHECK_INTERVAL)) {
            return;
        }

        $this->lastCheckAt = microtime(true);

        $state = AsyncTaskService::instance()->controlState($this->taskId);

        if ($state['cancel']) {
            throw new TaskCanceledException();
        }

        if ($state['pause']) {
            throw new TaskPausedException();
        }

        // 长任务若长时间不上报进度，心跳会失真 → 这里兜底续期（低频率）
        if ($this->elapsed($this->lastHeartbeatAt, self::HEARTBEAT_INTERVAL)) {
            $this->lastHeartbeatAt = microtime(true);
            AsyncTaskService::instance()->touch($this->taskId);
        }
    }

    /**
     * 仅续心跳（不检查标记 / 不改进度）
     */
    public function touch(): void
    {
        $this->lastHeartbeatAt = microtime(true);
        AsyncTaskService::instance()->touch($this->taskId);
    }

    /**
     * 是否已被请求取消（不抛异常，供 Handler 自行决定收尾方式）
     */
    public function isCancelRequested(): bool
    {
        return AsyncTaskService::instance()->controlState($this->taskId)['cancel'];
    }

    // ========================================================================
    // 内部
    // ========================================================================

    /**
     * 是否已超过节流窗口（从未执行过视为可执行）
     *
     * @param float $lastAt  上次时间戳（微秒）
     * @param float $seconds 间隔（秒）
     */
    protected function elapsed(float $lastAt, float $seconds): bool
    {
        if ($lastAt <= 0.0) {
            return true;
        }

        return (microtime(true) - $lastAt) >= $seconds;
    }

    protected function forceReport(): void
    {
        $this->lastReportAt = microtime(true);
        AsyncTaskService::instance()->report($this->taskId, $this->lastDone, $this->lastTotal, '');
    }
}
