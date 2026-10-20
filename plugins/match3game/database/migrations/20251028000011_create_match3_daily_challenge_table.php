<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建每日挑战表（05 §B3 / §B7.5）
 *
 * 每日 00:00 生成一行：`level_seed = hash(日期)`、`relic_pool = 当日固定遗物序列`。
 * **当日全服一致** —— 所有玩家拿到同一张图、同一批遗物，只比操作。
 */
class CreateMatch3DailyChallengeTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_daily_challenge', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐每日挑战',
        ]);

        $table
            ->addColumn(Column::string('day', 10)->setNull(false)->setComment('YYYY-MM-DD'))
            ->addColumn(Column::integer('map_seed')->setNull(false)->setSigned(false)->setComment('地图种子 = hash(日期)'))
            ->addColumn(Column::integer('level_seed')->setNull(false)->setSigned(false)->setComment('关卡内容种子（当日全服一致）'))
            ->addColumn(Column::integer('layers')->setNull(false)->setSigned(false)->setDefault(1)->setComment('层数（每日挑战 1 层 ≈ 5 节点）'))
            ->addColumn(Column::text('relic_pool')->setNull(true)->setDefault(null)->setComment('当日固定遗物序列 [relicId, ...]'))
            ->addColumn(Column::string('stage_code', 32)->setNull(true)->setDefault(null)->setComment('预留：固定关卡模板'))
            ->addColumn(Column::integer('stone_reward')->setNull(false)->setSigned(false)->setDefault(0)->setComment('通关灵石奖励'))
            ->addColumn(Column::integer('status')->setNull(false)->setSigned(false)->setDefault(1)->setComment('1启用 0停用'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setDefault(null))
            ->addIndex(['day'], ['unique' => true, 'name' => 'uk_day'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_daily_challenge')) {
            $this->table('match3_daily_challenge')->drop()->save();
        }
    }
}
