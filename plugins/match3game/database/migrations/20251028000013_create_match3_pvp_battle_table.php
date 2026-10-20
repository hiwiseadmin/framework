<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建对战（PvP）场次表（05 §A3.1）
 *
 * ⚠️ **表名带 `pvp_` 前缀**：`match3_battle` 已被现有插件的历史表占用
 * （`2026081006_create_match3_battle_table.php`，单人局会话），
 * 两者语义完全不同，不能再共用（07 §5.1 冲突处理）。
 *
 * 【最关键字段 `seed`】创建时锁定 → 双方 start 都读它，
 * 保证"同一 battleId 双方棋盘 100% 一致"（05 §A8.1 第 1 条）。
 *
 * mode：
 *   friend  好友异步对战（opponent_id 指定）
 *   ladder  公开挑战（opponent_id = 0，可被陌生人接受）
 *   group   群擂台（group_id 必填，指向当期 group_battle）
 *   daily   每日挑战对阵
 */
class CreateMatch3PvpBattleTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_pvp_battle', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐对战场次',
        ]);

        $table
            ->addColumn(Column::string('battle_id', 32)->setNull(false)->setComment('bt_xxx'))
            ->addColumn(Column::string('mode', 16)->setNull(false)->setDefault('friend')->setComment('friend/ladder/group/daily'))
            ->addColumn(Column::string('stage_code', 32)->setNull(false)->setComment('关卡编码'))
            ->addColumn(Column::integer('seed')->setNull(false)->setSigned(false)->setComment('锁定的随机种子（双方一致）'))
            ->addColumn(Column::text('relics')->setNull(true)->setDefault(null)->setComment('肉鸽对战遗物（可空）'))
            ->addColumn(Column::string('group_id', 64)->setNull(true)->setDefault(null)->setComment('群标识（加密后的群 ID，禁止明文群名）'))
            ->addColumn(Column::string('period_id', 16)->setNull(true)->setDefault(null)->setComment('群擂台周期 2026-W37'))
            ->addColumn(Column::string('season_id', 16)->setNull(true)->setDefault(null)->setComment('赛季 ID'))
            ->addColumn(Column::integer('challenger_id')->setNull(false)->setSigned(false)->setComment('发起者'))
            ->addColumn(Column::integer('opponent_id')->setNull(false)->setSigned(false)->setDefault(0)->setComment('对手（0=公开挑战）'))
            ->addColumn(Column::integer('status')->setNull(false)->setSigned(false)->setDefault(0)->setComment('0待接受 1进行中 2已完成 3已过期 4已拒绝'))
            ->addColumn(Column::integer('winner_id')->setNull(false)->setSigned(false)->setDefault(0)->setComment('0=平局/未决'))
            ->addColumn(Column::string('share_title', 128)->setNull(true)->setDefault(null)->setComment('分享卡片标题（前端渲染用）'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setDefault(null))
            ->addColumn(Column::dateTime('expires_at')->setNull(true)->setDefault(null)->setComment('创建 + 24h'))
            ->addColumn(Column::dateTime('finished_at')->setNull(true)->setDefault(null))
            ->addIndex(['battle_id'], ['unique' => true, 'name' => 'uk_battle_id'])
            ->addIndex(['mode', 'status'], ['name' => 'idx_mode_status'])
            ->addIndex(['group_id', 'period_id'], ['name' => 'idx_group'])
            ->addIndex(['challenger_id', 'create_time'], ['name' => 'idx_challenger'])
            ->addIndex(['status', 'expires_at'], ['name' => 'idx_expires'])
            ->addIndex(['season_id', 'status'], ['name' => 'idx_season'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_pvp_battle')) {
            $this->table('match3_pvp_battle')->drop()->save();
        }
    }
}
