<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建赛季玩家积分表（05 §A3.4）
 *
 * 段位由积分区间映射（05 §A7.1）：
 *   bronze 0-199 / silver 200-499 / gold 500-999 /
 *   platinum 1000-1799 / diamond 1800-2999 / king 3000+
 *
 * 负分不降段：`-15` 不低于**当前段位下限**（05 §A7.1）。
 */
class CreateMatch3SeasonPlayerTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_season_player', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐赛季玩家积分',
        ]);

        $table
            ->addColumn(Column::string('season_id', 16)->setNull(false))
            ->addColumn(Column::integer('user_id')->setNull(false)->setSigned(false))
            ->addColumn(Column::integer('points')->setNull(false)->setSigned(true)->setDefault(0)->setComment('赛季积分（可为负？不，最低=段位下限）'))
            ->addColumn(Column::string('tier', 16)->setNull(false)->setDefault('bronze')->setComment('bronze/silver/gold/platinum/diamond/king'))
            ->addColumn(Column::integer('wins')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::integer('losses')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::integer('best_score')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::integer('reward_claimed')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::string('last_first_win_day', 10)->setNull(true)->setDefault(null)->setComment('每日首胜奖励的日期（防重复领）'))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setDefault(null))
            ->addIndex(['season_id', 'user_id'], ['unique' => true, 'name' => 'uk_season_user'])
            ->addIndex(['season_id', 'points'], ['name' => 'idx_rank'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_season_player')) {
            $this->table('match3_season_player')->drop()->save();
        }
    }
}
