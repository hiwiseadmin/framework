<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateProductSpecValueTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('smartstock_product_spec_value', [
            'engine'    => 'InnoDB',
            'comment'   => '商品规格值表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::bigInteger('product_id')->setSigned(false)->setComment('商品ID'))
            ->addColumn(Column::bigInteger('spec_id')->setSigned(false)->setComment('规格ID'))
            ->addColumn(Column::string('value', 100)->setComment('规格值'))
            ->addIndex(['product_id'])
            ->addIndex(['spec_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_product_spec_value')) {
            $this->table('smartstock_product_spec_value')->drop()->save();
        }
    }
}
