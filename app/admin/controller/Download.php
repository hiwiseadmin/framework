<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\common\model\AsyncTask as AsyncTaskModel;
use app\common\service\ExportJobService;
use wise\operationlog\attribute\OperationLog;

/**
 * 下载中心＼�
 *
 * 数据源是 `async_tasks`（handler = export:list），**不新建表**：
 *   - 进度 / 取消 / 结果留痕全部复用受管任务框架；
 *   - 只展示**自己创建的**导出任务（归属校验，防 IDOR）；
 *   - 文件超过 `config('crud.export.expire_days')` 后标记「已过期」，不再提供下载；
 *   - 下载用 `sendfile()`，FPM / Swoole 通用。
 *
 * 权限：由迁移补种的「系统工具 → 下载中心」菜单派生（index/getlist/file/delete/clear）。
 * 未授权调用一律由 RBAC 中间件 403，不依赖本控制器内判断。
 */
class Download extends BaseController
{
    /** 单页最大条数 */
    private const MAX_PAGE_SIZE = 100;

    /**
     * 下载中心页面
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 导出任务列表（仅本人）
     */
    public function getList()
    {
        $adminId = (int) ($this->currentAdminId() ?? 0);
        if ($adminId <= 0) {
            return $this->error('未登录', [], 401);
        }

        $page   = max(1, (int) $this->request->param('page', 1));
        $limit  = min(self::MAX_PAGE_SIZE, max(1, (int) $this->request->param('limit', 20)));
        $status = trim((string) $this->request->param('status', ''));

        $build = function () use ($adminId, $status) {
            $query = AsyncTaskModel::where('handler', ExportJobService::HANDLER)
                ->where('created_by', $adminId)
                ->order('id', 'desc');

            if ($status !== '') {
                $query->where('status', $status);
            }

            return $query;
        };

        $total = (int) $build()->count();
        $list  = $build()->page($page, $limit)->select();

        $rows = [];
        foreach ($list as $task) {
            $rows[] = $this->toRow($task);
        }

        return $this->success('ok', [
            'total' => $total,
            'list'  => $rows,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 下载文件（GET）
     *
     * 归属校验：任务必须属于当前管理员；否则与「不存在」同一响应，避免探测。
     *
     * @return \think\Response|\think\response\Json
     */
    public function file()
    {
        $task = $this->findOwnTask((int) $this->request->param('id', 0));
        if ($task === null) {
            return $this->error('任务不存在', [], 404);
        }

        $result = $this->taskResult($task);
        $path   = (string) ($result['path'] ?? '');

        if ($path === '' || !is_file($path)) {
            return $this->error('文件已删除或已过期，请重新导出');
        }

        $expireAt = (string) ($result['expire_at'] ?? '');
        if ($expireAt !== '' && strtotime($expireAt) < time()) {
            return $this->error('文件已过期（保留 ' . (int) config('crud.export.expire_days', 7) . ' 天），请重新导出');
        }

        $name = (string) ($result['file'] ?? basename($path));

        return response('', 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($name) . '"',
            'Content-Length'      => (string) filesize($path),
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
            'Pragma'              => 'no-cache',
        ])->sendfile($path);
    }

    /**
     * 删除导出任务（含文件，仅本人）
     */
    #[OperationLog(template: '删除导出任务 {id}', level: 'WARNING')]
    public function delete()
    {
        $task = $this->findOwnTask((int) $this->request->param('id', 0));
        if ($task === null) {
            return $this->error('任务不存在', [], 404);
        }

        $result = $this->taskResult($task);
        $path   = (string) ($result['path'] ?? '');

        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }

        $task->delete();

        return $this->success('已删除');
    }

    /**
     * 清理过期文件与记录（仅本人）
     */
    #[OperationLog(template: '清理过期导出文件', level: 'WARNING')]
    public function clear()
    {
        $adminId = (int) ($this->currentAdminId() ?? 0);
        if ($adminId <= 0) {
            return $this->error('未登录', [], 401);
        }

        $deleted = 0;
        $tasks   = AsyncTaskModel::where('handler', ExportJobService::HANDLER)
            ->where('created_by', $adminId)
            ->where('status', AsyncTaskModel::STATUS_SUCCESS)
            ->select();

        foreach ($tasks as $task) {
            $result   = $this->taskResult($task);
            $path     = (string) ($result['path'] ?? '');
            $expireAt = (string) ($result['expire_at'] ?? '');

            if ($expireAt === '' || strtotime($expireAt) >= time()) {
                continue;
            }

            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }

            $task->delete();
            $deleted++;
        }

        return $this->success("已清理 {$deleted} 条过期导出", ['deleted' => $deleted]);
    }


    /**
     * 取当前管理员名下的导出任务
     */
    protected function findOwnTask(int $id): ?AsyncTaskModel
    {
        $adminId = (int) ($this->currentAdminId() ?? 0);
        if ($id <= 0 || $adminId <= 0) {
            return null;
        }

        return AsyncTaskModel::where('id', $id)
            ->where('handler', ExportJobService::HANDLER)
            ->where('created_by', $adminId)
            ->find();
    }

    /**
     * 解析任务结果（handler 返回的数组）
     *
     * @return array<string, mixed>
     */
    protected function taskResult(AsyncTaskModel $task): array
    {
        $raw    = (string) ($task->getAttr('result') ?? '');
        $result = $raw === '' ? [] : json_decode($raw, true);

        return is_array($result) ? $result : [];
    }

    /**
     * 任务行 → 前端结构（含可下载状态与过期标记）
     */
    protected function toRow(AsyncTaskModel $task): array
    {
        $status   = (string) $task->getAttr('status');
        $result   = $this->taskResult($task);
        $path     = (string) ($result['path'] ?? '');
        $expireAt = (string) ($result['expire_at'] ?? '');

        $expired = $expireAt !== '' && strtotime($expireAt) < time();
        $fileOk  = $path !== '' && is_file($path) && !$expired;

        return [
            'id'            => (int) $task->getAttr('id'),
            'task_no'       => (string) $task->getAttr('task_no'),
            'file'          => (string) ($result['file'] ?? ''),
            'size'          => (int) ($result['size'] ?? 0),
            'size_text'     => $this->humanSize((int) ($result['size'] ?? 0)),
            'rows'          => (int) ($result['rows'] ?? 0),
            'total'         => (int) ($result['total'] ?? 0),
            'status'        => $status,
            'status_text'   => AsyncTaskModel::STATUS_TEXT[$status] ?? '未知',
            'progress'      => (int) $task->getAttr('progress'),
            'message'       => (string) $task->getAttr('message'),
            'error'         => (string) ($task->getAttr('error') ?? ''),
            'expire_at'     => $expireAt,
            'expired'       => $expired,
            'can_download'  => $status === AsyncTaskModel::STATUS_SUCCESS && $fileOk,
            'create_time'   => (string) ($task->getAttr('create_time') ?? ''),
            'finished_at'   => (string) ($task->getAttr('finished_at') ?? ''),
        ];
    }

    /** 人类可读体积 */
    protected function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '-';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return ($index === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$index];
    }
}
