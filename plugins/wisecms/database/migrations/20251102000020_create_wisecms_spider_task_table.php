<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsSpiderTaskTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_spider_task', [
            'engine'=>'InnoDB',
            'comment'=>'CMS采集任务表',
            'collation'=>'utf8mb4_general_ci'
        ]);
        
        $table->addColumn(Column::string('name',100)->setComment('任务名称'))
            ->addColumn(Column::string('source_type',20)->setDefault('default'))
            ->addColumn(Column::longText('config')->setNullable()->setComment('采集规则(JSON)'))
            ->addColumn(Column::integer('target_catid')->setDefault(0))
            ->addColumn(Column::string('cron',50)->setDefault('')->setComment('定时表达式'))
            ->addColumn(Column::integer('last_count')->setDefault(0))
            ->addColumn(Column::integer('total_count')->setDefault(0))
            ->addColumn(Column::tinyInteger('status')->setDefault(0))
            ->addColumn(Column::datetime('last_run_time')->setNullable()->setComment('上次采集时间'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_spider_task')) {
            $this->table('wisecms_spider_task')->drop()->save();
        }
    }
}
