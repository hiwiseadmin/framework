<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateShortlinkGroupTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('shortlink_group', [
            'engine'    => 'InnoDB',
            'comment'   => '短链接分组表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::string('name', 100)->setComment('分组名称'))
              ->addColumn(Column::string('ident', 100)->setNullable()->setComment('分组标识'))
              ->addColumn(Column::string('description', 255)->setNullable()->setComment('分组描述'))
              ->addColumn(Column::integer('sort_order')->setDefault(0)->setComment('排序'))
              ->addColumn(Column::integer('admin_id')->setDefault(0)->setComment('创建者ID'))
              ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态 0:禁用 1:启用'))
              ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
              ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
              ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间'))
              ->addIndex(['ident'], ['unique' => true])
              ->addIndex(['status'])
              ->create();

        // 初始化默认分组（新装即有默认分组，避免业务侧空分组）
        $timestamp = date('Y-m-d H:i:s');
        $table->insert([
            'name'        => '默认分组',
            'ident'       => 'default',
            'description' => '系统默认分组',
            'sort_order'  => 1,
            'admin_id'    => 0,
            'status'      => 1,
            'create_time' => $timestamp,
            'update_time' => $timestamp,
        ])->save();
    }

    public function down(): void
    {
        if ($this->hasTable('shortlink_group')) {
            $this->table('shortlink_group')->drop()->save();
        }
    }
}
