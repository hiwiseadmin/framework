<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsRedirectTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_redirect', [
            'engine'=>'InnoDB',
            'comment'=>'CMS重定向表',
            'collation'=>'utf8mb4_general_ci'
        ]);

        $table->addColumn(Column::integer('site_id')->setSigned(false)->setDefault(1)->setComment('站点ID'))
            ->addColumn(Column::string('source_url', 500)->setDefault('')->setComment('来源路径'))
            ->addColumn(Column::string('target_url', 500)->setDefault('')->setComment('目标URL'))
            ->addColumn(Column::integer('type')->setDefault(301)->setComment('301/302'))
            ->addColumn(Column::integer('hit_count')->setDefault(0)->setComment('命中次数'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            // 路径解析按 (site_id, source_url) 精确匹配（站点隔离 + 同站不重复）
            ->addIndex(['site_id', 'source_url'], ['unique' => true])
            ->addIndex(['status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_redirect')) {
            $this->table('wisecms_redirect')->drop()->save();
        }
    }
}
