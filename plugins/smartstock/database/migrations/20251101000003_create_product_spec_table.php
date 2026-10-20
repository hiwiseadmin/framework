<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateProductSpecTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_product_spec', [
            'engine'    => 'InnoDB',
            'comment'   => '规格模板表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::string('name', 50)->setComment('规格名称'))
            ->addColumn(Column::text('options')->setNullable()->setComment('可选值JSON'))
            ->addColumn(Column::integer('sort_order')->setDefault(0)->setComment('排序'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态：0禁用 1启用'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('软删除时间'))
            ->addIndex(['name'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_product_spec')) {
            $this->table('smartstock_product_spec')->drop()->save();
        }
    }
}
