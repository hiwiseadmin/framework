<?php
declare(strict_types=1);

namespace wise\command\system;

use app\common\model\AsyncTask as AsyncTaskModel;
use app\common\service\AsyncTaskService;
use app\service\QueueMonitorService;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * 异步任务注册表维护命令
 *
 * 用法：
 *   php think async-task                  # 默认 reap：回收卡死任务
 *   php think async-task reap             # 同上，可加 --stale / --keep-days
 *   php think async-task health           # 刷新队列健康快照（收编 QueueMonitorService）
 *
 * 建议 crontab：
 *   *\/5 * * * * php think async-task reap --stale=900      # 每 5 分钟回收卡死任务
 *   *\/1 * * * * php think async-task health                # 每 1 分钟刷新队列健康快照
 *
 * ⚠️ 为什么必须放到 CLI 而不是后台接口：
 *   `async-task health` 依赖 QueueMonitorService 的**进程探测**（shell_exec / kill），
 *   属框架 DoD 明令禁止的「HTTP 请求路径内调用 shell_exec」；后台侧只读缓存快照。
 *   同理，卡死回收是写操作，属于定时任务职责，不能挂到 GET 列表接口上。
 *
 * 命名说明：
 *   - 框架级命令统一放 extend/wise/command/ 下按功能分的子目录（本命令在 system/），
 *     命名空间 wise\command\system，类名**不带 Command 后缀**（同 LangCheck / TestAdmin）；
 *   - 因类名与 app\common\model\AsyncTask 同名，模型以 AsyncTaskModel 别名引入，
 *     命令名采用连字符风格（async-task），与 api:perm-check 等保持一致。
 */
class AsyncTask extends Command
{
    protected function configure(): void
    {
        $this->setName('async-task')
            ->setDescription('异步任务注册表维护（卡死回收 / 历史清理 / 队列健康快照）')
            ->addArgument('action', Argument::OPTIONAL, 'reap=回收卡死任务并清理历史；health=刷新队列健康快照', 'reap')
            ->addOption('stale', null, Option::VALUE_OPTIONAL, '心跳超时阈值（秒），低于 60 会被抬高', AsyncTaskService::STALE_SECONDS)
            ->addOption('keep-days', null, Option::VALUE_OPTIONAL, '已结束任务保留天数（0 = 不清理）', 0);
    }

    protected function execute(Input $input, Output $output): int
    {
        $action = strtolower(trim((string) $input->getArgument('action')));

        return match ($action) {
            'health' => $this->refreshHealth($output),
            default  => $this->reap($input, $output),
        };
    }

    /**
     * 回收卡死任务 +（可选）清理历史
     */
    private function reap(Input $input, Output $output): int
    {
        $stale    = max(60, (int) $input->getOption('stale'));
        $keepDays = max(0, (int) $input->getOption('keep-days'));

        $reaped = AsyncTaskService::instance()->reapStale($stale);
        $output->writeln(sprintf('<info>卡死回收</info>：%d 条超过 %d 秒无心跳的任务已置为 %s', $reaped, $stale, AsyncTaskModel::STATUS_STALE));

        if ($keepDays > 0) {
            $purged = AsyncTaskService::instance()->purgeFinished($keepDays);
            $output->writeln(sprintf('<info>历史清理</info>：%d 条已结束任务（保留 %d 天）已删除', $purged, $keepDays));
        }

        $stats = AsyncTaskService::instance()->stats();
        $output->writeln(sprintf(
            '任务概览：总计 %d，等待 %d，执行中 %d，成功 %d，失败 %d，已取消 %d，卡死 %d',
            $stats['total'],
            $stats['pending'],
            $stats['running'],
            $stats['success'],
            $stats['failed'],
            $stats['canceled'],
            $stats['stale']
        ));

        return 0;
    }

    /**
     * 刷新队列健康快照（进程探测仅允许在 CLI 执行）
     */
    private function refreshHealth(Output $output): int
    {
        $service = new QueueMonitorService();
        $status  = $service->checkQueueStatus();

        $output->writeln(sprintf(
            '队列驱动：%s；listen 进程：%s；work 进程：%s；待处理 %d，失败 %d',
            (string) ($status['queue_driver'] ?? '-'),
            !empty($status['listen_running']) ? '运行中' : '未运行',
            !empty($status['work_running']) ? '运行中' : '未运行',
            (int) ($status['pending_jobs'] ?? 0),
            (int) ($status['failed_jobs'] ?? 0)
        ));

        return 0;
    }
}
