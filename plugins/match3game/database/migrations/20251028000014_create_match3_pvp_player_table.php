<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建对战参与记录表（05 §A3.2）
 *
 * 一行 = 一个玩家在某场对战中的成绩与校验状态。
 * `uk_battle_user` 保证同一场对战同一玩家只有一行（并发 start 幂等）。
 *
 * 【防作弊字段】
 *   play_token  一次性（提交后置空 → 二次提交必被拒，05 §A8.1 第 4 条）
 *   sub_hash    submission 摘要，防重复提交
 *   is_cheat    0正常 1可疑 2确认（篡改 claim.score → 标 1，进作弊复核页）
 *   replayed    本局是否走过多方重放校验（false = 弱校验，风控统计用）
 */
class CreateMatch3PvpPlayerTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_pvp_player', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐对战参与记录',
        ]);

        $table
            ->addColumn(Column::string('battle_id', 32)->setNull(false))
            ->addColumn(Column::integer('user_id')->setNull(false)->setSigned(false))
            ->addColumn(Column::integer('score')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::integer('moves')->setNull(false)->setSigned(false)->setDefault(0)->setComment('消耗步数（平局时少者胜）'))
            ->addColumn(Column::integer('stars')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::integer('max_combo')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::integer('status')->setNull(false)->setSigned(false)->setDefault(0)->setComment('0未开始 1进行中 2已完成 3超时'))
            ->addColumn(Column::string('play_token', 64)->setNull(true)->setDefault(null)->setComment('一次性；提交后置空'))
            ->addColumn(Column::string('sub_hash', 64)->setNull(true)->setDefault(null)->setComment('submission 摘要'))
            ->addColumn(Column::integer('is_cheat')->setNull(false)->setSigned(false)->setDefault(0)->setComment('0正常 1可疑 2确认作弊'))
            ->addColumn(Column::string('cheat_reason', 64)->setNull(true)->setDefault(null)->setComment('board_mismatch / result_mismatch / …'))
            ->addColumn(Column::integer('replayed')->setNull(false)->setSigned(false)->setDefault(0)->setComment('1=走过重放校验'))
            ->addColumn(Column::integer('points_delta')->setNull(false)->setSigned(true)->setDefault(0)->setComment('本场赛季积分变化'))
            ->addColumn(Column::dateTime('started_at')->setNull(true)->setDefault(null))
            ->addColumn(Column::dateTime('finished_at')->setNull(true)->setDefault(null))
            ->addIndex(['battle_id', 'user_id'], ['unique' => true, 'name' => 'uk_battle_user'])
            ->addIndex(['user_id', 'finished_at'], ['name' => 'idx_user'])
            ->addIndex(['battle_id', 'score'], ['name' => 'idx_score'])
            ->addIndex(['is_cheat', 'finished_at'], ['name' => 'idx_cheat'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_pvp_player')) {
            $this->table('match3_pvp_player')->drop()->save();
        }
    }
}
