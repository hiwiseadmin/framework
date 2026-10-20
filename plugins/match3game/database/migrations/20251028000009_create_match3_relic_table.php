<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建消消乐遗物配置表（06 §3.4）
 *
 * 遗物 = 对 Level Schema 的一次 patch（05 §B2）。
 * 纯配置表，策划自助维护，**新增遗物不需要发版**。
 */
class CreateMatch3RelicTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_relic', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐遗物配置',
        ]);

        $table
            ->addColumn(Column::string('relic_id', 32)->setNull(false)->setComment('r_xxx'))
            ->addColumn(Column::string('name', 64)->setNull(false))
            ->addColumn(Column::string('rarity', 16)->setNull(false)->setComment('common/rare/epic/cursed'))
            ->addColumn(Column::string('category', 16)->setNull(true)->setDefault(null)->setComment('board/rule/resource/behavior/risk/meta'))
            ->addColumn(Column::string('desc', 255)->setNull(true)->setDefault(null))
            ->addColumn(Column::string('icon', 128)->setNull(true)->setDefault(null))
            ->addColumn(Column::text('patch')->setNull(false)->setComment('Level Schema patch 列表'))
            ->addColumn(Column::integer('weight')->setNull(false)->setSigned(false)->setDefault(100)->setComment('奖池权重'))
            ->addColumn(Column::integer('unlock_cost')->setNull(false)->setSigned(false)->setDefault(0)->setComment('0=初始进池；>0=需灵石解锁后进池（05 §B7.4）'))
            ->addColumn(Column::integer('enabled')->setNull(false)->setSigned(false)->setDefault(1)->setComment('1启用 0停用'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setDefault(null))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setDefault(null))
            ->addIndex(['relic_id'], ['unique' => true, 'name' => 'uk_relic_id'])
            ->addIndex(['rarity', 'enabled'], ['name' => 'idx_rarity'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_relic')) {
            $this->table('match3_relic')->drop()->save();
        }
    }
}
