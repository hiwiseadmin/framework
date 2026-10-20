<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsModelTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_model', [
            'engine'    => 'InnoDB',
            'comment'   => 'CMS内容模型表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::string('name', 30)->setComment('模型标识'))
            ->addColumn(Column::string('title', 50)->setComment('模型名称'))
            ->addColumn(Column::string('table_name', 50)->setComment('数据表名'))
            ->addColumn(Column::string('description', 200)->setDefault('')->setComment('描述'))
            ->addColumn(Column::text('setting')->setNullable()->setComment('模型设置(JSON)'))
            ->addColumn(Column::tinyInteger('is_system')->setDefault(0)->setComment('是否系统模型'))
            ->addColumn(Column::integer('sort')->setDefault(0)->setComment('排序'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['name'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_model')) {
            $this->table('wisecms_model')->drop()->save();
        }
    }
}
