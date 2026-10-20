<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWiseDocsProjectsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisedocs_projects', [
            'engine' => 'InnoDB',
            'comment' => '文档项目表',
            'collation' => 'utf8mb4_general_ci',
        ]);
        
        // P1：不再 drop 已存在的表（原实现重复执行会删除全部数据）。
        // 表已存在时直接返回，结构变更交由增量迁移维护。
        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::string('name', 100)->setUnique()->setComment('项目名称'))
              ->addColumn(Column::string('code', 50)->setUnique()->setComment('项目代码'))
              ->addColumn(Column::text('description')->setComment('项目描述'))
              ->addColumn(Column::string('logo', 255)->setNullable()->setComment('项目Logo'))
              ->addColumn(Column::string('theme', 50)->setDefault('default')->setComment('主题样式'))
              ->addColumn(Column::string('domain', 255)->setNullable()->setComment('独立域名'))
              ->addColumn(Column::text('settings')->setComment('项目配置(JSON)'))
              ->addColumn(Column::integer('sort_order')->setComment('排序'))
              ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态 0:禁用，1:正常'))
              ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
              ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
              ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间'))
              // P1：code 列已通过 setUnique() 建立唯一索引；
              // 原 addUnique() 在 Phinx 中并不存在，会抛「Call to undefined method」导致迁移失败。
              ->addIndex('status')
              ->create();

    }

    public function down(): void
    {
        if ($this->hasTable('wisedocs_projects')) {
            $this->table('wisedocs_projects')->drop()->save();
        }
    }
}
