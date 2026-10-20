<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateTransferItemTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_transfer_item', [
            'engine'    => 'InnoDB',
            'comment'   => '调拨明细表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::bigInteger('transfer_id')->setSigned(false)->setComment('调拨单ID'))
            ->addColumn(Column::bigInteger('product_id')->setSigned(false)->setComment('商品ID'))
            ->addColumn(Column::integer('quantity')->setDefault(0)->setComment('调拨数量'))
            ->addColumn(Column::string('remark', 300)->setNullable()->setComment('备注'))
            ->addIndex(['transfer_id'])
            ->addIndex(['product_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_transfer_item')) {
            $this->table('smartstock_transfer_item')->drop()->save();
        }
    }
}
