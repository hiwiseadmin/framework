<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsSearchLogTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_search_log', [
            'engine'=>'InnoDB',
            'comment'=>'CMS搜索日志表',
            'collation'=>'utf8mb4_general_ci'
        ]);

        $table->addColumn(Column::integer('site_id')->setSigned(false)->setDefault(1)->setComment('站点ID'))
            ->addColumn(Column::string('keyword', 200)->setDefault('')->setComment('搜索关键词'))
            ->addColumn(Column::integer('result_count')->setDefault(0)->setComment('结果数'))
            ->addColumn(Column::string('ip', 45)->setDefault('')->setComment('来源IP'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            // P1-D：热搜词按站点统计（(site_id, keyword) 的 GROUP BY 走索引）
            ->addIndex(['site_id', 'keyword'])
            ->addIndex(['create_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_search_log')) {
            $this->table('wisecms_search_log')->drop()->save();
        }
    }
}
