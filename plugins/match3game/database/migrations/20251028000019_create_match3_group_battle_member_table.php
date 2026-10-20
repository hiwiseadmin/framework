<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建群擂台成员表（05 §A3.5）
 *
 * 个人成绩 = 本期累计（每关每日 3 次，取最高分；`play_count` 记当日次数）。
 * `uk_member` 保证同一群同一期同一玩家一行。
 */
class CreateMatch3GroupBattleMemberTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_group_battle_member', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐群擂台成员',
        ]);

        $table
            ->addColumn(Column::string('group_id', 64)->setNull(false))
            ->addColumn(Column::string('period_id', 16)->setNull(false))
            ->addColumn(Column::integer('user_id')->setNull(false)->setSigned(false))
            ->addColumn(Column::integer('score')->setNull(false)->setSigned(false)->setDefault(0)->setComment('本期个人最佳/累计'))
            ->addColumn(Column::integer('play_count')->setNull(false)->setSigned(false)->setDefault(0)->setComment('当日已打总次数'))
            ->addColumn(Column::string('play_day', 10)->setNull(true)->setDefault(null)->setComment('play_count 所属日期（跨天归零）'))
            ->addColumn(Column::text('stage_plays')->setNull(true)->setDefault(null)->setComment('每关每日次数 {"M3-0201":{"day":"2026-09-11","count":2}}'))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setDefault(null))
            ->addIndex(['group_id', 'period_id', 'user_id'], ['unique' => true, 'name' => 'uk_member'])
            ->addIndex(['period_id', 'group_id', 'score'], ['name' => 'idx_score'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_group_battle_member')) {
            $this->table('match3_group_battle_member')->drop()->save();
        }
    }
}
