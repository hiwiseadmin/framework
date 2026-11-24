<?php
declare(strict_types=1);

namespace wise\command\system;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 字段级变更记录归档＼�
 *
 * 用法：
 *   php think change-log:archive                 # 按 config/change_log.php 的 archive.days 归档
 *   php think change-log:archive --days=90       # 指定保留天数
 *   php think change-log:archive --dry-run       # 只统计不迁移
 *   php think change-log:archive --batch=2000    # 每批迁移行数
 *
 * 归档策略（与 wise-operation-log 的 LogArchiver 同口径）：
 *   - 归档表 `operation_log_changes_archive` 首次运行时按主表结构复制创建；
 *   - 逐批 `INSERT ... SELECT` 语义（先取行再插入），仅在插入成功后删除主表数据；
 *   - **不回迁**：历史数据保留在归档表，页面查询只读活跃表（与日志中心一致），
 *     需要追溯时直查归档表或按 id 回迁。
 *
 * 建议挂 wise-crontab 日任务（config/wise-crontab.php），凌晨低峰执行。
 */
class ChangeLogArchive extends Command
{
    private const TABLE = 'operation_log_changes';

    protected function configure(): void
    {
        $this->setName('change-log:archive')
            ->setDescription('归档旧的字段级变更记录（operation_log_changes → _archive）')
            ->addOption('days', null, Option::VALUE_REQUIRED, '保留天数（默认取配置 archive.days，0 = 不归档）', null)
            ->addOption('batch', null, Option::VALUE_REQUIRED, '每批迁移行数', null)
            ->addOption('dry-run', null, Option::VALUE_NONE, '只统计待归档行数，不执行迁移');
    }

    protected function execute(Input $input, Output $output): int
    {
        $days  = $input->getOption('days');
        $days  = $days === null ? (int) config('change_log.archive.days', 180) : (int) $days;
        $batch = (int) ($input->getOption('batch') ?: config('change_log.archive.batch', 5000));
        $batch = max(100, min(50000, $batch));
        $dry   = (bool) $input->getOption('dry-run');

        if ($days <= 0) {
            $output->writeln('<comment>未启用归档</comment>：保留天数为 0（config/change_log.php → archive.days）');
            return 0;
        }

        if (!$this->hasTable(self::TABLE)) {
            $output->writeln('<comment>主表不存在</comment>：operation_log_changes 尚未创建（先执行迁移）');
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $total  = (int) Db::name(self::TABLE)->where('create_time', '<', $cutoff)->count();

        $output->writeln(sprintf(
            '保留 %d 天（早于 %s 的记录归档），待归档 %d 行，批量 %d',
            $days,
            $cutoff,
            $total,
            $batch
        ));

        if ($total === 0) {
            return 0;
        }

        if ($dry) {
            $output->writeln('<info>dry-run</info>：未做任何写入');
            return 0;
        }

        $archiveTable = (string) config('change_log.archive.table', 'operation_log_changes_archive');

        if (!$this->hasTable($archiveTable)) {
            $this->createArchiveTable($archiveTable);
            $output->writeln(sprintf('<info>已创建归档表</info>：%s', $archiveTable));
        }

        $moved = 0;
        while (true) {
            $rows = Db::name(self::TABLE)
                ->where('create_time', '<', $cutoff)
                ->order('id', 'asc')
                ->limit($batch)
                ->select()
                ->toArray();

            if (empty($rows)) {
                break;
            }

            $ids = array_column($rows, 'id');

            try {
                Db::transaction(static function () use ($rows, $ids, $archiveTable): void {
                    Db::name($archiveTable)->insertAll($rows);
                    Db::name(self::TABLE)->whereIn('id', $ids)->delete();
                });
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>批次失败</error>：%s（已迁移 %d 行后中止）', $e->getMessage(), $moved));
                return 1;
            }

            $moved += count($rows);
            $output->writeln(sprintf('已归档 %d / %d 行', $moved, $total));

            if (count($rows) < $batch) {
                break;
            }
        }

        $output->writeln(sprintf('<info>完成</info>：共归档 %d 行到 %s', $moved, $archiveTable));

        return 0;
    }

    /** 表是否存在（带前缀） */
    private function hasTable(string $table): bool
    {
        try {
            Db::name($table)->limit(1)->select();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 按主表结构复制创建归档表 */
    private function createArchiveTable(string $archiveTable): void
    {
        $prefix   = (string) Db::getConfig('prefix');
        $source   = $prefix . self::TABLE;
        $target   = $prefix . $archiveTable;

        Db::execute(sprintf('CREATE TABLE IF NOT EXISTS `%s` LIKE `%s`', $target, $source));
    }
}
