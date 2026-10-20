<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsScheduleTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_schedule', [
            'engine' => 'InnoDB', 
            'comment' => 'CMS内容排期表', 
            'collation' => 'utf8mb4_general_ci',
        ]);
        
        $table->addColumn(Column::integer('content_id')->setSigned(false)->setComment('内容ID'))
            ->addColumn(Column::string('status', 20)->setDefault('pending')->setComment('pending/published/cancelled/failed'))
            ->addColumn(Column::string('remark', 255)->setDefault(''))
            ->addColumn(Column::datetime('scheduled_time')->setNullable()->setComment('计划发布时间'))
            ->addColumn(Column::datetime('actual_time')->setNullable()->setComment('实际发布时间'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['content_id'])
            // 等值列在前、范围列在后：cron 扫描是 status='pending' AND scheduled_time <= now，
            // 原 (scheduled_time, status) 的首列是范围列，status 无法用于索引定位
            ->addIndex(['status', 'scheduled_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_schedule')) {
            $this->table('wisecms_schedule')->drop()->save();
        }
    }
}
