<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateTransferTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_transfer', [
            'engine'    => 'InnoDB',
            'comment'   => '调拨单表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::string('order_no', 32)->setComment('调拨单号'))
            ->addColumn(Column::bigInteger('from_warehouse_id')->setSigned(false)->setComment('调出仓库ID'))
            ->addColumn(Column::bigInteger('to_warehouse_id')->setSigned(false)->setComment('调入仓库ID'))
            ->addColumn(Column::integer('total_quantity')->setDefault(0)->setComment('总数量'))
            ->addColumn(Column::tinyInteger('status')->setDefault(0)->setComment('状态：0草稿 1已审核 2调拨中 3已完成 4已取消（冗余字段）'))
            ->addColumn(Column::string('remark', 500)->setNullable()->setComment('备注'))
            ->addColumn(Column::bigInteger('operator_id')->setSigned(false)->setDefault(0)->setComment('操作人ID'))
            ->addColumn(Column::datetime('transfer_time')->setNullable()->setComment('调拨完成时间'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('软删除时间'))
            ->addIndex(['order_no'], ['unique' => true])
            ->addIndex(['from_warehouse_id'])
            ->addIndex(['to_warehouse_id'])
            ->addIndex(['status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_transfer')) {
            $this->table('smartstock_transfer')->drop()->save();
        }
    }
}
