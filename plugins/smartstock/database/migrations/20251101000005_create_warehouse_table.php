<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWarehouseTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_warehouse', [
            'engine'    => 'InnoDB',
            'comment'   => '仓库表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::string('name', 100)->setComment('仓库名称'))
            ->addColumn(Column::string('code', 32)->setComment('仓库编码'))
            ->addColumn(Column::string('address', 500)->setNullable()->setComment('仓库地址'))
            ->addColumn(Column::string('contact_person', 50)->setNullable()->setComment('联系人'))
            ->addColumn(Column::string('contact_phone', 20)->setNullable()->setComment('联系电话'))
            ->addColumn(Column::integer('sort_order')->setDefault(0)->setComment('排序'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态：0禁用 1启用'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('软删除时间'))
            ->addIndex(['code'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_warehouse')) {
            $this->table('smartstock_warehouse')->drop()->save();
        }
    }
}
