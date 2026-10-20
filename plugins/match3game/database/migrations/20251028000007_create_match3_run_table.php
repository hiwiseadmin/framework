<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建消消乐肉鸽局表（06 §3.1）
 *
 * 每局一行。map_data 存整棵关卡树（生成后固定，避免重算不一致）。
 * 双种子设计：map_seed 决定地图长相，level_seed 决定关卡内容。
 */
class CreateMatch3RunTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_run', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐肉鸽局',
        ]);

        $table
            ->addColumn(Column::string('run_id', 32)->setNull(false)->setComment('run_xxx'))
            ->addColumn(Column::integer('user_id')->setNull(false)->setSigned(false)->setComment('用户 ID'))
            ->addColumn(Column::integer('map_seed')->setNull(false)->setSigned(false)->setComment('地图布局种子'))
            ->addColumn(Column::integer('level_seed')->setNull(false)->setSigned(false)->setComment('关卡内容种子'))
            ->addColumn(Column::string('mode', 16)->setNull(false)->setDefault('normal')->setComment('normal 普通局 / daily 每日挑战'))
            ->addColumn(Column::string('daily_day', 10)->setNull(true)->setDefault(null)->setComment('每日挑战日期 YYYY-MM-DD（mode=daily 时有值）'))
            ->addColumn(Column::string('start_option', 32)->setNull(false)->setDefault('default')->setComment('起点选项'))
            ->addColumn(Column::integer('daily_retry')->setNull(false)->setSigned(false)->setDefault(0)->setComment('每日挑战重挑战次数'))
            ->addColumn(Column::text('buffs')->setNull(true)->setDefault(null)->setComment('本局全局增益 {"moves":10}，由商店/事件获得，作用于后续每个节点'))
            ->addColumn(Column::integer('layer')->setNull(false)->setSigned(false)->setDefault(1)->setComment('当前层'))
            ->addColumn(Column::integer('node_index')->setNull(false)->setSigned(false)->setDefault(0)->setComment('层内节点序号'))
            ->addColumn(Column::integer('lives')->setNull(false)->setSigned(false)->setDefault(3)->setComment('剩余失败机会'))
            ->addColumn(Column::integer('coins')->setNull(false)->setSigned(false)->setDefault(0)->setComment('局内货币（灵玉）'))
            ->addColumn(Column::text('relics')->setNull(true)->setDefault(null)->setComment('[{"id":"r_xx","obtainedAt":"1-2"}] 按获得顺序'))
            ->addColumn(Column::text('map_data')->setNull(true)->setDefault(null)->setComment('关卡树 {mapSeed,layers,nodes[]}'))
            ->addColumn(Column::integer('status')->setNull(false)->setSigned(false)->setDefault(0)->setComment('0进行中 1已通关 2已失败 3已放弃'))
            ->addColumn(Column::integer('score')->setNull(false)->setSigned(false)->setDefault(0)->setComment('本局累计分'))
            ->addColumn(Column::integer('meta_reward')->setNull(false)->setSigned(false)->setDefault(0)->setComment('本局结算灵石'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setDefault(null))
            ->addColumn(Column::dateTime('finished_at')->setNull(true)->setDefault(null))
            ->addIndex(['run_id'], ['unique' => true, 'name' => 'uk_run_id'])
            ->addIndex(['user_id', 'status'], ['name' => 'idx_user_status'])
            ->addIndex(['mode', 'daily_day'], ['name' => 'idx_mode_day'])
            ->addIndex(['create_time'], ['name' => 'idx_created'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_run')) {
            $this->table('match3_run')->drop()->save();
        }
    }
}
