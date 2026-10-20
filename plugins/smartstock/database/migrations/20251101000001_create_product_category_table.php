<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateProductCategoryTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_product_category', [
            'engine'    => 'InnoDB',
            'comment'   => '商品分类表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::string('name', 100)->setComment('分类名称'))
            ->addColumn(Column::bigInteger('parent_id')->setSigned(false)->setDefault(0)->setComment('父级分类ID，0为顶级'))
            ->addColumn(Column::integer('sort_order')->setDefault(0)->setComment('排序'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态：0禁用 1启用'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('软删除时间'))
            ->addIndex(['parent_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_product_category')) {
            $this->table('smartstock_product_category')->drop()->save();
        }
    }
}
