<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateProductTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_product', [
            'engine'    => 'InnoDB',
            'comment'   => '商品表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::string('name', 200)->setComment('商品名称'))
            ->addColumn(Column::string('sku_code', 64)->setComment('SKU编码'))
            ->addColumn(Column::bigInteger('category_id')->setSigned(false)->setDefault(0)->setComment('分类ID'))
            ->addColumn(Column::bigInteger('unit_id')->setSigned(false)->setDefault(0)->setComment('计量单位ID'))
            ->addColumn(Column::string('barcode', 64)->setNullable()->setComment('条码'))
            ->addColumn(Column::string('spec_summary', 500)->setNullable()->setComment('规格摘要'))
            ->addColumn(Column::decimal('cost_price', 10, 2)->setDefault(0.00)->setComment('成本价'))
            ->addColumn(Column::decimal('selling_price', 10, 2)->setDefault(0.00)->setComment('销售价'))
            ->addColumn(Column::string('image', 500)->setNullable()->setComment('商品图片路径'))
            ->addColumn(Column::text('description')->setNullable()->setComment('商品描述'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态：0禁用 1启用'))
            ->addColumn(Column::date('expiry_date')->setNullable()->setDefault(null)->setComment('保质期到期日')->setAfter('description'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('软删除时间'))
            ->addIndex(['sku_code'], ['unique' => true])
            ->addIndex(['category_id'])
            ->addIndex(['unit_id'])
            ->addIndex(['barcode'])
            ->addIndex(['status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_product')) {
            $this->table('smartstock_product')->drop()->save();
        }
    }
}
