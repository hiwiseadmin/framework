<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsInternalLinkTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_internal_link', [
            'engine' => 'InnoDB', 'comment' => 'CMS内链关系表', 'collation' => 'utf8mb4_general_ci',
        ]);
        $table->addColumn(Column::string('keyword', 100)->setComment('关键词'))
            ->addColumn(Column::string('target_url', 500)->setComment('目标URL'))
            ->addColumn(Column::string('target_title', 200)->setDefault('')->setComment('目标标题'))
            ->addColumn(Column::integer('site_id')->setDefault(0)->setComment('站点ID'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['keyword', 'site_id'], ['unique' => true])
            // 前台取内链词表按 (site_id, status) 过滤；unique 首列是 keyword，site_id 用不上
            ->addIndex(['site_id', 'status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_internal_link')) {
            $this->table('wisecms_internal_link')->drop()->save();
        }
    }
}
