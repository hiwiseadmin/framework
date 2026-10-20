<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建消消乐玩家背包表（§5.4，InventoryModelInterface 实现）
 *
 * 同时承载金币/钻石/道具三种资源。
 * (user_id, item_id) 唯一索引为 InventoryService 并发防重所必需。
 */
class CreateMatch3PlayerInventoryTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_player_inventory', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐玩家背包(金币/钻石/道具)',
        ]);

        $table
            ->addColumn(Column::integer('user_id')->setNull(false)->setSigned(false)->setComment('用户ID'))
            ->addColumn(Column::string('item_id', 64)->setNull(false)->setComment('物品ID: coins/diamond/energy/prop.hammer'))
            ->addColumn(Column::integer('count')->setNull(false)->setDefault(0)->setComment('数量'))
            ->addColumn(Column::integer('version')->setNull(false)->setSigned(false)->setDefault(0)->setComment('CAS 乐观锁版本'))
            ->addColumn(Column::dateTime('update_time')->setNull(false)->setComment('更新时间'))
            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('创建时间'))
            ->addIndex(['user_id', 'item_id'], ['unique' => true, 'name' => 'uk_user_item'])
            ->addIndex(['user_id'], ['name' => 'idx_user'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_player_inventory')) {
            $this->table('match3_player_inventory')->drop()->save();
        }
    }
}
