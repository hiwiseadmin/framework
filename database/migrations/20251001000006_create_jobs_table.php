<?php
declare (strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 队列任务表 jobs
 *
 * 列口径**必须**与 think-queue 的 Database 连接器
 * （vendor/topthink/think-queue/src/queue/connector/Database.php）一致：
 * queue / payload / attempts / reserve_time / available_time / create_time，
 * 其中三个时间列为 Unix 秒时间戳（连接器用 Carbon::now()->getTimestamp() 写入/比较）：
 * - reserve_time   NULL=未被保留；非 NULL=已被某个 worker 保留（不可再领取）
 * - available_time 任务可被领取的最早时刻（延迟任务大于当前时间）
 * 运行时的队列统计（QueueService / QueueMonitorService）也按这套列名查询。
 */
class CreateJobsTable extends Migrator
{
    public function up()
    {
        $table = $this->table('jobs', [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '队列任务表',
        ]);

        $table->addColumn(Column::string('tab', 50)->setDefault('')->setComment('配置分类'))
        ->addColumn(Column::string('queue', 255)->setDefault('default')->setComment('队列名称'))
        ->addColumn(Column::longText('payload')->setComment('任务载荷'))
        ->addColumn(Column::tinyInteger('attempts')->setUnsigned()->setDefault(0)->setComment('尝试次数'))
        ->addColumn(Column::integer('reserve_time')->setUnsigned()->setNullable()->setComment('保留时间戳（Unix 秒，NULL=未保留）'))
        ->addColumn(Column::integer('available_time')->setUnsigned()->setComment('可执行时间戳（Unix 秒）'))
        ->addColumn(Column::integer('create_time')->setUnsigned()->setComment('创建时间戳（Unix 秒）'))
        ->addIndex(['queue'], ['name' => 'idx_queue'])
        ->addIndex(['reserve_time'], ['name' => 'idx_reserve_time'])
        ->addIndex(['available_time'], ['name' => 'idx_available_time'])
        ->addIndex(['create_time'], ['name' => 'idx_create_time'])
        ->create();
    }
    
    public function down(): void
    {
        if ($this->hasTable('jobs')) {
            $this->table('jobs')->drop()->save();
        }
    }
}