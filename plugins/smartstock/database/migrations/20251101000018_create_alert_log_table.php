<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateAlertLogTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_alert_log', [
            'engine'    => 'InnoDB',
            'comment'   => '预警日志表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::bigInteger('rule_id')->setSigned(false)->setDefault(0)->setComment('关联预警规则ID'))
            ->addColumn(Column::bigInteger('product_id')->setSigned(false)->setComment('商品ID'))
            ->addColumn(Column::bigInteger('warehouse_id')->setSigned(false)->setComment('仓库ID'))
            ->addColumn(Column::tinyInteger('alert_type')->setComment('预警类型'))
            ->addColumn(Column::integer('current_quantity')->setDefault(0)->setComment('当前库存量'))
            ->addColumn(Column::integer('threshold_value')->setDefault(0)->setComment('阈值'))
            ->addColumn(Column::string('message', 300)->setNullable()->setComment('预警消息'))
            ->addColumn(Column::tinyInteger('is_read')->setDefault(0)->setComment('是否已读'))
            ->addColumn(Column::datetime('create_time')->setComment('预警时间'))
            ->addIndex(['rule_id'])
            ->addIndex(['product_id'])
            ->addIndex(['warehouse_id'])
            ->addIndex(['is_read'])
            ->addIndex(['create_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_alert_log')) {
            $this->table('smartstock_alert_log')->drop()->save();
        }
    }
}
