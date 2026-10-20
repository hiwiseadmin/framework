<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsGenerationTaskTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_generation_task', [
            'engine' => 'InnoDB', 
            'comment' => 'CMS内容生成任务表', 
            'collation' => 'utf8mb4_general_ci',
        ]);
        
        $table->addColumn(Column::string('name', 200)->setComment('任务名称'))
            ->addColumn(Column::string('mode', 20)->setComment('nl/template/batch/rewrite/continue'))
            ->addColumn(Column::longText('params')->setNullable()->setComment('生成参数(JSON)'))
            ->addColumn(Column::text('schedule')->setNullable()->setComment('排期配置(JSON)'))
            ->addColumn(Column::text('progress')->setNullable()->setComment('进度(JSON)'))
            ->addColumn(Column::integer('total_count')->setDefault(0))
            ->addColumn(Column::integer('completed_count')->setDefault(0))
            ->addColumn(Column::string('status', 20)->setDefault('pending')->setComment('pending/running/completed/failed'))
            ->addColumn(Column::text('error')->setNullable())
            ->addColumn(Column::datetime('start_time')->setNullable()->setComment('任务开始时间'))
            ->addColumn(Column::datetime('finish_time')->setNullable()->setComment('任务完成时间'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_generation_task')) {
            $this->table('wisecms_generation_task')->drop()->save();
        }
    }
}
