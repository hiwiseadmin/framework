<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateAlertRuleTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_alert_rule', [
            'engine'    => 'InnoDB',
            'comment'   => '预警规则表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::string('name', 100)->setComment('规则名称'))
            ->addColumn(Column::tinyInteger('alert_type')->setComment('类型：1低库存 2超库存 3保质期'))
            ->addColumn(Column::bigInteger('product_id')->setSigned(false)->setDefault(0)->setComment('商品ID（0=全局）'))
            ->addColumn(Column::bigInteger('warehouse_id')->setSigned(false)->setDefault(0)->setComment('仓库ID（0=全局）'))
            ->addColumn(Column::integer('min_quantity')->setDefault(0)->setComment('最低库存阈值'))
            ->addColumn(Column::integer('max_quantity')->setDefault(0)->setComment('最高库存阈值'))
            ->addColumn(Column::integer('expiry_days')->setDefault(0)->setComment('保质期预警天数'))
            ->addColumn(Column::string('notify_type', 50)->setNullable()->setComment('通知方式：system,email'))
            ->addColumn(Column::string('notify_users', 500)->setNullable()->setComment('通知用户ID列表JSON'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态：0禁用 1启用'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addIndex(['alert_type'])
            ->addIndex(['product_id'])
            ->addIndex(['warehouse_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_alert_rule')) {
            $this->table('smartstock_alert_rule')->drop()->save();
        }
    }
}
