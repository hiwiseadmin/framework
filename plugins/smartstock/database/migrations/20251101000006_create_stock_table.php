<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateStockTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_stock', [
            'engine'    => 'InnoDB',
            'comment'   => '库存表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::bigInteger('product_id')->setSigned(false)->setComment('商品ID'))
            ->addColumn(Column::bigInteger('warehouse_id')->setSigned(false)->setComment('仓库ID'))
            ->addColumn(Column::integer('quantity')->setDefault(0)->setComment('当前库存数量'))
            ->addColumn(Column::integer('locked_quantity')->setDefault(0)->setComment('锁定库存'))
            ->addColumn(Column::integer('alert_min')->setDefault(0)->setComment('低库存预警阈值'))
            ->addColumn(Column::integer('alert_max')->setDefault(0)->setComment('超库存预警阈值'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addIndex(['product_id', 'warehouse_id'], ['unique' => true])
            ->addIndex(['product_id'])
            ->addIndex(['warehouse_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_stock')) {
            $this->table('smartstock_stock')->drop()->save();
        }
    }
}
