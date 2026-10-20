<?php
declare(strict_types=1);

namespace app\api\controller\admin;

use app\common\model\AsyncTask as AsyncTaskModel;
use app\common\service\AsyncTaskService;
use app\common\service\QueueService;
use think\Request;
use think\response\Json;
use wise\api\response\ErrorCode;

/**
 * 任务中心（后台 App API）
 *
 * GET  /api/admin/tasks             列表（page/limit/status/handler/keyword）
 * GET  /api/admin/tasks/{id}        详情（含 payload / result）
 * GET  /api/admin/tasks/progress    进度批量查询（ids[]）
 * POST /api/admin/tasks/{id}/cancel 协作式取消（只写 cancel_requested）
 * POST /api/admin/tasks/{id}/retry  重试（以相同载荷新建一条任务）
 *
 * 权限：admin.async_task.getlist / detail / progress / cancel / retry
 *
 * 与 Web 侧 `app\admin\controller\AsyncTask` 的关系：
 *   行为、字段、状态语义完全对齐（同一套 AsyncTaskService / AsyncTaskModel）。
 *   字段映射在此重写一份而非复用 Web 控制器的 protected 方法 —— App 侧需要更严的
 *   输出白名单，且不应把 Web 控制器（依赖 Session / 视图基类）引入 API 进程。
 */
class Task extends Base
{
    /**
     * 列表最大分页（与 Web 侧一致）
     */
    protected const MAX_PAGE_SIZE = 100;

    /**
     * 进度批量上限（与 Web 侧一致）
     */
    protected const PROGRESS_BATCH_LIMIT = 100;

    /**
     * 任务列表
     */
    public function index(Request $request): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $page    = max(1, (int) $request->param('page', 1));
        $limit   = min(self::MAX_PAGE_SIZE, max(1, (int) $request->param('limit', 20)));
        $status  = trim((string) $request->param('status', ''));
        $handler = trim((string) $request->param('handler', ''));
        $keyword = trim((string) $request->param('keyword', ''));

        $build = function () use ($status, $handler, $keyword) {
            $query = AsyncTaskModel::field([
                'id', 'task_no', 'handler', 'name', 'queue', 'connection', 'status',
                'progress', 'total', 'done', 'message', 'error', 'pid', 'created_by',
                'cancel_requested', 'create_time', 'started_at', 'finished_at', 'heartbeat_at',
            ]);

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

            return $query;
        };

        $total = (int) $build()->count();
        $list  = $total > 0
            ? $build()->order('id', 'desc')->page($page, $limit)->select()
            : [];

        $rows = [];
        foreach ($list as $task) {
            $rows[] = $this->toArray($task);
        }

        return $this->success('ok', [
            'total' => $total,
            'list'  => $rows,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 任务详情
     */
    public function show(Request $request, int $id = 0): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $id   = $id > 0 ? $id : (int) $request->param('id', 0);
        $task = $id > 0 ? AsyncTaskService::instance()->find($id) : null;

        if ($task === null) {
            return $this->error('任务不存在', null, ErrorCode::NOT_FOUND);
        }

        return $this->success('ok', $this->toArray($task, true));
    }

    /**
     * 进度批量查询（前端轮询用）
     */
    public function progress(Request $request): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $ids = $request->param('ids/a', []);
        if (!is_array($ids) || $ids === []) {
            $single = (int) $request->param('id', 0);
            $ids    = $single > 0 ? [$single] : [];
        }

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0));
        if ($ids === []) {
            return $this->error('缺少任务 ID');
        }

        $ids  = array_slice($ids, 0, self::PROGRESS_BATCH_LIMIT);
        $rows = [];

        foreach (AsyncTaskModel::whereIn('id', $ids)->select() as $task) {
            $rows[] = $this->toArray($task);
        }

        return $this->success('ok', ['list' => $rows]);
    }

    /**
     * 取消任务（协作式：只写标记，任务在检查点自行退出）
     */
    public function cancel(Request $request, int $id = 0): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $id = $id > 0 ? $id : (int) $request->param('id', 0);
        if ($id <= 0) {
            return $this->error('缺少任务 ID');
        }

        if (!AsyncTaskService::instance()->requestCancel($id)) {
            return $this->error('任务不存在或已结束');
        }

        return $this->success('已提交取消请求，任务将在下一个检查点停止');
    }

    /**
     * 重试任务（新建一条任务，保留原次执行痕迹）
     */
    public function retry(Request $request, int $id = 0): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $id   = $id > 0 ? $id : (int) $request->param('id', 0);
        $task = $id > 0 ? AsyncTaskService::instance()->find($id) : null;

        if ($task === null) {
            return $this->error('任务不存在', null, ErrorCode::NOT_FOUND);
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
     * 任务 → 输出数组
     *
     * 与 Web 侧 AsyncTask::taskToArray() 同口径；App 侧额外收窄字段，
     * 不返回 payload/result（仅详情接口返回，避免列表响应体过大）。
     */
    protected function toArray(AsyncTaskModel $task, bool $withDetail = false): array
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
            'status'           => $status,
            'status_text'      => AsyncTaskModel::STATUS_TEXT[$status] ?? '未知',
            'progress'         => (int) $task->getAttr('progress'),
            'progress_percent' => $this->percent($total, $done),
            'total'            => $total,
            'done'             => $done,
            'message'          => (string) $task->getAttr('message'),
            'error'            => (string) ($task->getAttr('error') ?? ''),
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

            $data['payload']   = is_array($payload) ? $payload : [];
            $data['result']    = $result;
            $data['pid']       = (int) $task->getAttr('pid');
            $data['created_by'] = (int) $task->getAttr('created_by');
        }

        return $data;
    }

    /**
     * 进度百分比；总数未知返回 PROGRESS_INDETERMINATE(-1)
     */
    protected function percent(int $total, int $done): int
    {
        if ($total <= 0) {
            return AsyncTaskModel::PROGRESS_INDETERMINATE;
        }

        return max(0, min(100, (int) floor($done / $total * 100)));
    }
}
