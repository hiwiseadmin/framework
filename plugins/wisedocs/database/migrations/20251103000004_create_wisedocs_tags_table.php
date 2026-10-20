<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWiseDocsTagsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisedocs_tags', [
            'engine' => 'InnoDB',
            'comment' => '帮助文档标签表',
            'collation' => 'utf8mb4_general_ci',
        ]);
        
        // P1：不再 drop 已存在的表（原实现重复执行会删除全部数据）。
        // 表已存在时直接返回，结构变更交由增量迁移维护。
        if ($table->exists()) {
            return;
        }
        
        $table->addColumn(Column::integer('project_id')->setComment('项目ID'))
              ->addColumn(Column::string('name', 50)->setUnique()->setComment('标签名称'))
              ->addColumn(Column::string('slug', 50)->setUnique()->setComment('URL标识'))
              ->addColumn(Column::integer('count')->setDefault(0)->setComment('使用次数'))
              ->addColumn(Column::text('description')->setComment('描述'))
              ->addColumn(Column::integer('sort_order')->setComment('排序'))
              ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态 0:禁用，1:正常'))
              ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
              ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
              ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间'))
            //   ->addUnique('slug')
              ->addIndex('project_id')
              ->addIndex('name')
              ->addIndex('status')
              ->create();

    }

    public function down(): void
    {
        if ($this->hasTable('wisedocs_tags')) {
            $this->table('wisedocs_tags')->drop()->save();
        }
    }
}
