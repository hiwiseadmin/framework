<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateCheckTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_check', [
            'engine'    => 'InnoDB',
            'comment'   => '盘点单表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::string('order_no', 32)->setComment('盘点单号'))
            ->addColumn(Column::bigInteger('warehouse_id')->setSigned(false)->setComment('盘点仓库ID'))
            ->addColumn(Column::tinyInteger('status')->setDefault(0)->setComment('状态：0计划中 1盘点中 2已完成 3已处理差异（冗余字段）'))
            ->addColumn(Column::integer('total_items')->setDefault(0)->setComment('盘点商品数'))
            ->addColumn(Column::integer('diff_items')->setDefault(0)->setComment('差异商品数'))
            ->addColumn(Column::string('remark', 500)->setNullable()->setComment('备注'))
            ->addColumn(Column::bigInteger('operator_id')->setSigned(false)->setDefault(0)->setComment('操作人ID'))
            ->addColumn(Column::datetime('check_time')->setNullable()->setComment('盘点时间'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('软删除时间'))
            ->addIndex(['order_no'], ['unique' => true])
            ->addIndex(['warehouse_id'])
            ->addIndex(['status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_check')) {
            $this->table('smartstock_check')->drop()->save();
        }
    }
}
