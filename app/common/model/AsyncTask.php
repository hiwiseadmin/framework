<?php
declare(strict_types=1);

namespace app\common\model;

use think\model\concern\SoftDelete;

/**
 * 异步任务注册表模型
 *
 * 承载「进度查询 / 取消」控制面数据，与 think-queue 的 jobs 表解耦：
 *   - jobs / failed_jobs：think-queue 原生传输层，随时被驱动改写、成功即删；
 *   - async_tasks      ：业务层注册表，跨驱动（database / redis）语义一致。
 *
 * 状态机：
 *   pending → running → success | failed | canceled
 *                     ↳ paused → running（Phase 2 预留）
 *
 * @property int    $id
 * @property string $task_no
 * @property string $handler
 * @property string $name
 * @property string $queue
 * @property string $connection
 * @property string $status
 * @property int    $progress
 * @property int    $total
 * @property int    $done
 * @property string $message
 * @property int    $cancel_requested
 * @property int    $pause_requested
 */
class AsyncTask extends BaseModel
{
    use SoftDelete;

    protected $name = 'async_tasks';

    /**
     * 可写字段白名单（防批量赋值）
     *
     * @var array<int, string>
     */
    protected $allowWriteField = [
        'task_no', 'handler', 'name', 'queue', 'connection', 'status',
        'progress', 'total', 'done', 'message', 'result', 'error', 'payload',
        'pid', 'created_by', 'cancel_requested', 'pause_requested',
        'started_at', 'finished_at', 'heartbeat_at',
    ];


    public const STATUS_PENDING  = 'pending';
    public const STATUS_RUNNING  = 'running';
    public const STATUS_PAUSED   = 'paused';
    public const STATUS_SUCCESS  = 'success';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_STALE    = 'stale';

    /** 进度数值：不确定（总数未知） */
    public const PROGRESS_INDETERMINATE = -1;

    /**
     * 状态 → 中文文案
     *
     * @var array<string, string>
     */
    public const STATUS_TEXT = [
        self::STATUS_PENDING  => '等待中',
        self::STATUS_RUNNING  => '执行中',
        self::STATUS_PAUSED   => '已暂停',
        self::STATUS_SUCCESS  => '成功',
        self::STATUS_FAILED   => '失败',
        self::STATUS_CANCELED => '已取消',
        self::STATUS_STALE    => '已卡死',
    ];


    /**
     * 是否为「已结束」状态（不再变化）
     */
    public static function isFinished(?string $status): bool
    {
        return in_array($status, [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
            self::STATUS_STALE,
        ], true);
    }

    /**
     * 是否为「活跃」状态（可被取消 / 需要轮询进度）
     */
    public static function isActive(?string $status): bool
    {
        return in_array($status, [
            self::STATUS_PENDING,
            self::STATUS_RUNNING,
            self::STATUS_PAUSED,
        ], true);
    }

    /** 状态文案 */
    public function getStatusTextAttr(): string
    {
        return self::STATUS_TEXT[(string) $this->getAttr('status')] ?? '未知';
    }

    /**
     * 计算百分比（0-100）；总数未知返回 -1
     */
    public function getProgressPercentAttr(): int
    {
        $total = (int) $this->getAttr('total');
        if ($total <= 0) {
            return self::PROGRESS_INDETERMINATE;
        }

        $done = (int) $this->getAttr('done');
        $percent = (int) floor($done / $total * 100);

        return max(0, min(100, $percent));
    }
}
