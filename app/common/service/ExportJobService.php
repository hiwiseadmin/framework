<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\job\TaskContext;

/**
 * 异步导出任务（handler: export:list）
 *
 * 为什么是 handler 而不是 `ShouldQueue` Job 类：
 *   项目已有一套受管任务框架（`QueueService::dispatch()` + `AsyncTaskService` +
 *   `async_tasks` 注册表 + 任务中心页）。导出只需要「进度 / 取消 / 结果留痕」，
 *   复用这套即可，不必再引入一种队列写法。
 *
 * 数据一致性：
 *   提交任务时已把**筛选结果的主键快照**写到 `ids_file`（顺序与列表一致），
 *   队列里不再重建查询 —— 这样 `buildSearchQuery()` 等控制器钩子的结果不会丢失，
 *   也避免了 Worker 里伪造请求上下文。
 *
 * 结果契约：
 *   handler 返回数组会被 `AsyncTaskJob` 写入 `async_tasks.result`，
 *   下载中心据此展示文件名 / 大小 / 行数 / 过期时间。
 */
class ExportJobService
{
    /** 注册到 QueueService 的 handler 名 */
    public const HANDLER = 'export:list';

    /**
     * 执行导出
     *
     * @param array<string, mixed> $payload model / ids_file / columns / pk / filename / admin_id
     * @param TaskContext|null     $context 受管任务上下文（进度 / 取消）
     * @return array<string, mixed> 结果（写入 async_tasks.result）
     *
     * @throws \RuntimeException 模型或快照无效、写文件失败
     */
    public function run(array $payload, ?TaskContext $context = null): array
    {
        $modelClass = trim((string) ($payload['model'] ?? ''));
        if ($modelClass === '' || !class_exists($modelClass)) {
            throw new \RuntimeException('导出模型无效：' . $modelClass);
        }

        $idsFile = (string) ($payload['ids_file'] ?? '');
        if ($idsFile === '' || !is_file($idsFile)) {
            throw new \RuntimeException('导出数据快照已丢失，请重新提交导出');
        }

        $ids = json_decode((string) file_get_contents($idsFile), true);
        if (!is_array($ids)) {
            throw new \RuntimeException('导出数据快照格式错误');
        }

        $columns  = (array) ($payload['columns'] ?? []);
        $pk       = (string) ($payload['pk'] ?? 'id');
        $adminId  = (int) ($payload['admin_id'] ?? 0);
        $fileName = (string) ($payload['filename'] ?? 'export');

        $config  = (array) config('crud.export', []);
        $service = new ExportService($config);

        try {
            $service->cleanExpired((int) ($config['expire_days'] ?? 7));
        } catch (\Throwable $e) {
        }

        $target = $service->makeTarget($fileName, $adminId, 'csv');

        try {
            $rows = $service->csvByIds(
                $target['path'],
                static fn () => new $modelClass(),
                array_values($ids),
                $columns,
                $pk,
                $context === null ? null : static function (int $done, int $total) use ($context): void {
                    $context->progress($done, $total, '正在写入文件…');
                },
                $context === null ? null : static function () use ($context): void {
                    $context->checkpoint();
                }
            );
        } catch (\Throwable $e) {
            if (is_file($target['path'])) {
                @unlink($target['path']);
            }

            throw $e;
        }

        if (is_file($idsFile)) {
            @unlink($idsFile);
        }

        $expireDays = max(1, (int) ($config['expire_days'] ?? 7));

        return [
            'file'      => $target['filename'],
            'path'      => $target['path'],
            'size'      => (int) (filesize($target['path']) ?: 0),
            'rows'      => $rows,
            'total'     => count($ids),
            'expire_at' => date('Y-m-d H:i:s', time() + $expireDays * 86400),
        ];
    }
}
