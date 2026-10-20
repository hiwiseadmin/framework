<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateShortlinkLinkTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('shortlink_link', [
            'engine'    => 'InnoDB',
            'comment'   => '短链接表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::string('code', 32)->setComment('短码'))
              ->addColumn(Column::text('long_url')->setComment('原始长链接'))
              ->addColumn(Column::string('title', 255)->setNullable()->setComment('链接标题'))
              ->addColumn(Column::integer('group_id')->setDefault(0)->setComment('分组ID'))
              ->addColumn(Column::string('password', 255)->setNullable()->setComment('访问密码(bcrypt哈希)'))
              ->addColumn(Column::datetime('expire_time')->setNullable()->setComment('过期时间'))
              ->addColumn(Column::integer('max_visits')->setDefault(0)->setComment('最大访问次数 0:不限制'))
              ->addColumn(Column::integer('total_visits')->setDefault(0)->setComment('总访问次数'))
              ->addColumn(Column::integer('unique_visits')->setDefault(0)->setComment('独立访客数'))
              ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态 0:禁用 1:启用 2:过期'))
              ->addColumn(Column::integer('admin_id')->setDefault(0)->setComment('创建者ID'))
              ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
              ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
              ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间'))
              ->addIndex(['code'], ['unique' => true])
              ->addIndex(['group_id'])
              ->addIndex(['status'])
              ->addIndex(['expire_time'])
              ->addIndex(['admin_id'])
              ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('shortlink_link')) {
            $this->table('shortlink_link')->drop()->save();
        }
    }
}
