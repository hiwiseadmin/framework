<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建群擂台周期表（05 §A3.5）
 *
 * 【合规红线】`group_id` **只能存加密后的群标识**
 * （由 `wx.getShareInfo` 的 encryptedData 解密得到的 `openGId`），
 * 严禁存明文群名/群号（05 §C2-R4 + §A8.1 第 8 条，合规风险极高）。
 *
 * 周期：每周一 00:00 刷新；seed 全群统一；计分 = 群内 Top5 累加。
 */
class CreateMatch3GroupBattleTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_group_battle', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐群擂台周期',
        ]);

        $table
            ->addColumn(Column::string('group_id', 64)->setNull(false)->setComment('加密群标识（openGId，禁止明文群名）'))
            ->addColumn(Column::string('period_id', 16)->setNull(false)->setComment('周期 2026-W37'))
            ->addColumn(Column::text('stage_pool')->setNull(false)->setComment('本期关卡池 [3 关：简单/中等/困难]'))
            ->addColumn(Column::integer('seed')->setNull(false)->setSigned(false)->setComment('本期统一 seed'))
            ->addColumn(Column::integer('total_score')->setNull(false)->setSigned(false)->setDefault(0)->setComment('群总分（Top5 累加）'))
            ->addColumn(Column::integer('member_cnt')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::integer('rank')->setNull(false)->setSigned(false)->setDefault(0)->setComment('群间排名（每日 02:00 重算）'))
            ->addColumn(Column::dateTime('start_at')->setNull(true)->setDefault(null))
            ->addColumn(Column::dateTime('end_at')->setNull(true)->setDefault(null))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setDefault(null))
            ->addIndex(['group_id', 'period_id'], ['unique' => true, 'name' => 'uk_group_period'])
            ->addIndex(['period_id', 'rank'], ['name' => 'idx_period_rank'])
            ->addIndex(['period_id', 'total_score'], ['name' => 'idx_period_score'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_group_battle')) {
            $this->table('match3_group_battle')->drop()->save();
        }
    }
}
