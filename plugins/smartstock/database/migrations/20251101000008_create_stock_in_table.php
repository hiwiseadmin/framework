<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateStockInTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_stock_in', [
            'engine'    => 'InnoDB',
            'comment'   => '入库单表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::string('order_no', 32)->setComment('入库单号'))
            ->addColumn(Column::tinyInteger('type')->setDefault(1)->setComment('类型：1采购 2退货 3调拨 4盘盈 5其他'))
            ->addColumn(Column::bigInteger('warehouse_id')->setSigned(false)->setComment('入库仓库ID'))
            ->addColumn(Column::string('reference_no', 64)->setNullable()->setComment('关联单号'))
            ->addColumn(Column::integer('total_quantity')->setDefault(0)->setComment('总数量'))
            ->addColumn(Column::decimal('total_amount', 12, 2)->setDefault(0.00)->setComment('总金额'))
            ->addColumn(Column::tinyInteger('status')->setDefault(0)->setComment('状态：0草稿 1已确认 2已完成 3已取消（冗余字段）'))
            ->addColumn(Column::string('remark', 500)->setNullable()->setComment('备注'))
            ->addColumn(Column::bigInteger('operator_id')->setSigned(false)->setDefault(0)->setComment('操作人ID'))
            ->addColumn(Column::datetime('stock_in_time')->setNullable()->setComment('入库时间'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('软删除时间'))
            ->addIndex(['order_no'], ['unique' => true])
            ->addIndex(['type'])
            ->addIndex(['warehouse_id'])
            ->addIndex(['status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_stock_in')) {
            $this->table('smartstock_stock_in')->drop()->save();
        }
    }
}
