<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建赛季表（05 §A3.4）
 *
 * 赛季周期 **2 周**（05 §A7.1：小游戏节奏快，4 周过长易流失）。
 * `reward_conf` 存段位奖励配置（05 §A7.1 六档），策划可改不需发版。
 */
class CreateMatch3SeasonTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_season', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐赛季',
        ]);

        $table
            ->addColumn(Column::string('season_id', 16)->setNull(false)->setComment('S2026-01'))
            ->addColumn(Column::string('name', 64)->setNull(true)->setDefault(null))
            ->addColumn(Column::dateTime('start_at')->setNull(true)->setDefault(null))
            ->addColumn(Column::dateTime('end_at')->setNull(true)->setDefault(null))
            ->addColumn(Column::integer('status')->setNull(false)->setSigned(false)->setDefault(0)->setComment('0未开始 1进行中 2已结算'))
            ->addColumn(Column::text('reward_conf')->setNull(true)->setDefault(null)->setComment('段位奖励配置 {bronze:{coin:500},...}'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setDefault(null))
            ->addIndex(['season_id'], ['unique' => true, 'name' => 'uk_season_id'])
            ->addIndex(['status', 'start_at'], ['name' => 'idx_status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_season')) {
            $this->table('match3_season')->drop()->save();
        }
    }
}
