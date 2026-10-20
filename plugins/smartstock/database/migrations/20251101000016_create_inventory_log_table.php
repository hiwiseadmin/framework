<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateInventoryLogTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_inventory_log', [
            'engine'    => 'InnoDB',
            'comment'   => '库存流水表（只追加，不修改不删除）',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::bigInteger('product_id')->setSigned(false)->setComment('商品ID'))
            ->addColumn(Column::bigInteger('warehouse_id')->setSigned(false)->setComment('仓库ID'))
            ->addColumn(Column::tinyInteger('change_type')->setComment('变更类型：1入库 2出库 3调拨入 4调拨出 5盘点调整 6初始化'))
            ->addColumn(Column::integer('change_quantity')->setDefault(0)->setComment('变更数量（正增负减）'))
            ->addColumn(Column::integer('before_quantity')->setDefault(0)->setComment('变更前数量'))
            ->addColumn(Column::integer('after_quantity')->setDefault(0)->setComment('变更后数量'))
            ->addColumn(Column::string('reference_type', 30)->setNullable()->setComment('关联单据类型：stock_in/stock_out/transfer/check/init'))
            ->addColumn(Column::string('reference_no', 64)->setNullable()->setComment('关联单据号'))
            ->addColumn(Column::string('remark', 300)->setNullable()->setComment('备注'))
            ->addColumn(Column::bigInteger('operator_id')->setSigned(false)->setDefault(0)->setComment('操作人ID'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间（不可修改/不可删除）'))
            ->addIndex(['product_id'])
            ->addIndex(['warehouse_id'])
            ->addIndex(['change_type'])
            ->addIndex(['reference_type'])
            ->addIndex(['reference_no'])
            ->addIndex(['create_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_inventory_log')) {
            $this->table('smartstock_inventory_log')->drop()->save();
        }
    }
}
