<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateStockInItemTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_stock_in_item', [
            'engine'    => 'InnoDB',
            'comment'   => '入库明细表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::bigInteger('stock_in_id')->setSigned(false)->setComment('入库单ID'))
            ->addColumn(Column::bigInteger('product_id')->setSigned(false)->setComment('商品ID'))
            ->addColumn(Column::integer('quantity')->setDefault(0)->setComment('入库数量'))
            ->addColumn(Column::decimal('unit_price', 10, 2)->setDefault(0.00)->setComment('单价'))
            ->addColumn(Column::decimal('subtotal', 12, 2)->setDefault(0.00)->setComment('小计'))
            ->addColumn(Column::string('remark', 300)->setNullable()->setComment('备注'))
            ->addIndex(['stock_in_id'])
            ->addIndex(['product_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_stock_in_item')) {
            $this->table('smartstock_stock_in_item')->drop()->save();
        }
    }
}
