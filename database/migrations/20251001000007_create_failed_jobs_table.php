<?php
declare (strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 失败队列任务表 failed_jobs
 *
 * 列口径**必须**与 think-queue 的失败任务存储器
 * （vendor/topthink/think-queue/src/queue/failed/Database.php）一致：
 * connection / queue / payload / exception / fail_time，
 * 其中 fail_time 为 DATETIME 字符串（存储器用 Carbon::now()->toDateTimeString() 写入）。
 */
class CreateFailedJobsTable extends Migrator
{
    public function up()
    {
        $table = $this->table('failed_jobs', [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '失败队列任务表',
        ]);

        $table->addColumn(Column::text('connection')->setComment('连接名称'))
        ->addColumn(Column::text('queue')->setComment('队列名称'))
        ->addColumn(Column::longText('payload')->setComment('任务载荷'))
        ->addColumn(Column::longText('exception')->setComment('异常信息'))
        ->addColumn(Column::dateTime('fail_time')->setComment('失败时间'))
        ->create();
    }
    
    public function down(): void
    {
        if ($this->hasTable('failed_jobs')) {
            $this->table('failed_jobs')->drop()->save();
        }
    }
}