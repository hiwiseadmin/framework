<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateCheckItemTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_check_item', [
            'engine'    => 'InnoDB',
            'comment'   => '盘点明细表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::bigInteger('check_id')->setSigned(false)->setComment('盘点单ID'))
            ->addColumn(Column::bigInteger('product_id')->setSigned(false)->setComment('商品ID'))
            ->addColumn(Column::integer('system_quantity')->setDefault(0)->setComment('系统库存数量'))
            ->addColumn(Column::integer('actual_quantity')->setDefault(0)->setComment('实际盘点数量'))
            ->addColumn(Column::integer('diff_quantity')->setDefault(0)->setComment('差异数量'))
            ->addColumn(Column::string('diff_reason', 300)->setNullable()->setComment('差异原因'))
            ->addIndex(['check_id'])
            ->addIndex(['product_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_check_item')) {
            $this->table('smartstock_check_item')->drop()->save();
        }
    }
}
