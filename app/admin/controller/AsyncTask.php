<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\common\model\AsyncTask as AsyncTaskModel;
use app\common\service\AsyncTaskService;
use app\common\service\QueueService;
use app\service\QueueMonitorService;

/**
 * 任务中心（异步任务注册表后台）
 *
 * 提供「进度查询 / 协作式取消 / 重试」等只读与轻写接口，并在同一页面聚合
 * 队列健康数据（收编原孤儿服务 QueueMonitorService）。
 *
 * 路由：/admin/async_task/{action}，走 ThinkPHP 默认控制器路由，无需在
 * route/admin.php 显式声明。菜单与权限节点由迁移
 * 20261016120000_create_async_tasks_table 补种。
 *
 * ── 设计边界 ────────────────────────────────────────────────────────────
 *   · 「取消」只写 cancel_requested 标记，任务在 Handler 的检查点自行退出
 *     （协作式，见 TaskContext）。不埋检查点的任务不会被真正中止。
 *   · 本控制器**不做**进程探测/杀进程（HTTP 路径禁用 shell_exec）；
 *     队列健康数据读 QueueMonitorService 的缓存快照，由 CLI 侧刷新。
 *   · 列表/进度接口无写副作用；卡死回收是 CLI 定时任务职责。
 */
class AsyncTask extends BaseController
{
    /** 单次进度查询上限 */
    private const PROGRESS_BATCH_LIMIT = 100;

    /** 列表最大分页 */
    private const MAX_PAGE_SIZE = 100;

    /**
     * 任务中心页面
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 任务列表
     */
    public function getList()
    {
        $page    = max(1, (int) $this->request->param('page', 1));
        $limit   = min(self::MAX_PAGE_SIZE, max(1, (int) $this->request->param('limit', 20)));
        $status  = trim((string) $this->request->param('status', ''));
        $handler = trim((string) $this->request->param('handler', ''));
        $keyword = trim((string) $this->request->param('keyword', ''));

        $query = AsyncTaskModel::order('id', 'desc');

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($handler !== '') {
            $query->where('handler', $handler);
        }

        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($sub) use ($like) {
                $sub->whereLike('task_no', $like)
                    ->whereOr('handler', 'like', $like)
                    ->whereOr('name', 'like', $like);
            });
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select();

        $rows = [];
        foreach ($list as $task) {
            $rows[] = $this->taskToArray($task);
        }

        return $this->success('success', [
            'total' => $total,
            'list'  => $rows,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 任务详情（含原始载荷与结果）
     */
    public function detail()
    {
        $task = AsyncTaskService::instance()->find((int) $this->request->param('id', 0));

        if ($task === null) {
            return $this->error('任务不存在');
        }

        return $this->success('success', $this->taskToArray($task, true));
    }

    /**
     * 进度查询（供前端轮询，支持批量）
     */
    public function progress()
    {
        $ids = $this->request->param('ids/a', []);

        if (!is_array($ids) || empty($ids)) {
            $single = (int) $this->request->param('id', 0);
            $ids    = $single > 0 ? [$single] : [];
        }

        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));

        if (empty($ids)) {
            return $this->error('缺少任务 ID');
        }

        $ids  = array_slice($ids, 0, self::PROGRESS_BATCH_LIMIT);
        $list = AsyncTaskModel::whereIn('id', $ids)->select();

        $rows = [];
        foreach ($list as $task) {
            $rows[] = $this->taskToArray($task);
        }

        return $this->success('success', ['list' => $rows]);
    }

    /**
     * 队列健康（任务统计 + 队列积压 + 缓存快照）
     *
     * 数据来源均不触碰 shell_exec：
     *   - 任务统计：async_tasks
     *   - 队列积压：QueueService::getQueueStats()（DB / Redis 计数）
     *   - 进程快照：QueueMonitorService::getCachedStatus()（CLI 写入的缓存）
     */
    public function health()
    {
        $taskStats  = AsyncTaskService::instance()->stats();
        $queueStats = QueueService::instance()->getQueueStats();
        $monitor    = new QueueMonitorService();
        $snapshot   = $monitor->getCachedStatus();

        return $this->success('success', [
            'tasks' => $taskStats,
            'queue' => [
                'connection' => (string) ($queueStats['connection'] ?? ''),
                'pending'    => (int) ($queueStats['pending'] ?? 0),
                'failed'     => (int) ($queueStats['failed'] ?? 0),
                'delayed'    => (int) ($queueStats['delayed'] ?? 0),
                'reserved'   => (int) ($queueStats['reserved'] ?? 0),
                'handlers'   => count((array) ($queueStats['handlers'] ?? [])),
            ],
            'monitor' => [
                'available'      => $snapshot !== null,
                'queue_driver'   => (string) ($snapshot['queue_driver'] ?? ''),
                'listen_running' => (bool) ($snapshot['listen_running'] ?? false),
                'work_running'   => (bool) ($snapshot['work_running'] ?? false),
                'last_check'     => (int) ($snapshot['last_check'] ?? 0),
                'last_check_text' => !empty($snapshot['last_check'])
                    ? date('Y-m-d H:i:s', (int) $snapshot['last_check'])
                    : '',
                'stats'          => $monitor->getMonitorStats(),
            ],
        ]);
    }

    /**
     * 取消任务（协作式：只写标记，任务在下一个检查点退出）
     */
    public function cancel()
    {
        // 用 param() 而非 post()：前端可能以 JSON body 提交（Content-Type: application/json）
        $id = (int) $this->request->param('id', 0);

        if ($id <= 0) {
            return $this->error('参数错误');
        }

        if (!AsyncTaskService::instance()->requestCancel($id)) {
            return $this->error('任务不存在或已结束');
        }

        return $this->success('已提交取消请求，任务将在下一个检查点停止');
    }

    /**
     * 重试任务（以相同载荷新建一条受管任务）
     *
     * 说明：重试不复用原任务行，而是新建记录以保留原次执行痕迹（含失败原因）。
     */
    public function retry()
    {
        $task = AsyncTaskService::instance()->find((int) $this->request->param('id', 0));

        if ($task === null) {
            return $this->error('任务不存在');
        }

        $status = (string) $task->getAttr('status');
        if (!AsyncTaskModel::isFinished($status) || $status === AsyncTaskModel::STATUS_SUCCESS) {
            return $this->error('仅失败 / 已取消 / 卡死的任务可重试');
        }

        $payload = json_decode((string) $task->getAttr('payload'), true);

        $newTask = QueueService::instance()->dispatch(
            (string) $task->getAttr('handler'),
            is_array($payload) ? $payload : [],
            [
                'name'       => (string) $task->getAttr('name'),
                'queue'      => (string) $task->getAttr('queue'),
                'connection' => (string) $task->getAttr('connection'),
            ]
        );

        if ($newTask === null) {
            return $this->error('重试投递失败，请检查队列配置');
        }

        return $this->success('已重新投递', [
            'id'      => (int) $newTask->getAttr('id'),
            'task_no' => (string) $newTask->getAttr('task_no'),
        ]);
    }

    /**
     * 删除任务记录（仅允许删除已结束的任务）
     */
    public function delete()
    {
        $ids = $this->resolveIds();

        if (empty($ids)) {
            return $this->error('请选择要删除的任务');
        }

        $deleted = 0;
        $skipped = 0;

        foreach (AsyncTaskModel::whereIn('id', $ids)->select() as $task) {
            if (!AsyncTaskModel::isFinished((string) $task->getAttr('status'))) {
                $skipped++;
                continue;
            }

            $task->delete();
            $deleted++;
        }

        if ($deleted === 0) {
            return $this->error($skipped > 0 ? '未结束的任务不可删除，请先取消' : '没有可删除的记录');
        }

        return $this->success(
            $skipped > 0 ? "已删除 {$deleted} 条，跳过 {$skipped} 条未结束任务" : "已删除 {$deleted} 条",
            ['deleted' => $deleted, 'skipped' => $skipped]
        );
    }

    /**
     * 清理已结束的历史任务
     */
    public function clear()
    {
        $days = max(1, (int) $this->request->param('days', 7));

        $count = AsyncTaskService::instance()->purgeFinished($days);

        return $this->success("已清理 {$count} 条 {$days} 天前结束的任务", ['count' => $count]);
    }

    // ========================================================================
    // 内部辅助
    // ========================================================================

    /**
     * 解析批量 ID（兼容 id / ids[] 两种传参）
     *
     * @return array<int, int>
     */
    protected function resolveIds(): array
    {
        $ids = $this->request->param('ids/a', []);

        if (!is_array($ids) || empty($ids)) {
            $single = (int) $this->request->param('id', 0);
            $ids    = $single > 0 ? [$single] : [];
        }

        return array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
    }

    /**
     * 任务 → 前端数组
     */
    protected function taskToArray(AsyncTaskModel $task, bool $withDetail = false): array
    {
        $status = (string) $task->getAttr('status');
        $total  = (int) $task->getAttr('total');
        $done   = (int) $task->getAttr('done');

        $data = [
            'id'               => (int) $task->getAttr('id'),
            'task_no'          => (string) $task->getAttr('task_no'),
            'handler'          => (string) $task->getAttr('handler'),
            'name'             => (string) $task->getAttr('name'),
            'queue'            => (string) $task->getAttr('queue'),
            'connection'       => (string) $task->getAttr('connection'),
            'status'           => $status,
            'status_text'      => AsyncTaskModel::STATUS_TEXT[$status] ?? '未知',
            'progress'         => (int) $task->getAttr('progress'),
            'progress_percent' => $this->percent($total, $done),
            'total'            => $total,
            'done'             => $done,
            'message'          => (string) $task->getAttr('message'),
            'error'            => (string) ($task->getAttr('error') ?? ''),
            'pid'              => (int) $task->getAttr('pid'),
            'created_by'       => (int) $task->getAttr('created_by'),
            'active'           => AsyncTaskModel::isActive($status),
            'finished'         => AsyncTaskModel::isFinished($status),
            'cancel_requested' => (int) $task->getAttr('cancel_requested') === 1,
            'create_time'      => (string) ($task->getAttr('create_time') ?? ''),
            'started_at'       => (string) ($task->getAttr('started_at') ?? ''),
            'finished_at'      => (string) ($task->getAttr('finished_at') ?? ''),
            'heartbeat_at'     => (string) ($task->getAttr('heartbeat_at') ?? ''),
        ];

        if ($withDetail) {
            $payload = json_decode((string) $task->getAttr('payload'), true);
            $result  = json_decode((string) ($task->getAttr('result') ?? ''), true);

            $data['payload'] = is_array($payload) ? $payload : [];
            $data['result']  = $result;
        }

        return $data;
    }

    /**
     * 计算百分比；总数未知返回 -1
     */
    protected function percent(int $total, int $done): int
    {
        if ($total <= 0) {
            return AsyncTaskModel::PROGRESS_INDETERMINATE;
        }

        return max(0, min(100, (int) floor($done / $total * 100)));
    }
}
