<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建消消乐对局会话表（§5.5）
 *
 * battle_token 为结算幂等键，防重放。
 */
class CreateMatch3BattleTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_battle', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐对局会话',
        ]);

        $table
            ->addColumn(Column::integer('user_id')->setNull(false)->setSigned(false)->setComment('用户ID'))
            ->addColumn(Column::string('level_code', 32)->setNull(false)->setComment('关卡编码'))
            ->addColumn(Column::string('battle_token', 32)->setNull(false)->setComment('服务端签发的对局令牌，结算幂等键'))
            ->addColumn(Column::integer('seed')->setNull(false)->setSigned(false)->setComment('棋盘生成种子'))
            ->addColumn(Column::tinyInteger('status')->setNull(false)->setDefault(0)->setComment('状态: 0playing 1done 2abandoned 3expired'))
            ->addColumn(Column::string('outcome', 16)->setNull(true)->setDefault(null)->setComment('结果: win/lose/quit'))
            ->addColumn(Column::smallInteger('moves_used')->setNull(true)->setDefault(null)->setComment('实际步数'))
            ->addColumn(Column::integer('score')->setNull(true)->setDefault(null)->setComment('结算得分'))
            ->addColumn(Column::text('collect_result')->setNull(true)->setDefault(null)->setComment('收集结果 JSON'))
            ->addColumn(Column::text('reward_given')->setNull(true)->setDefault(null)->setComment('已发奖励 JSON'))
            ->addColumn(Column::text('settle_result')->setNull(true)->setDefault(null)->setComment('首次结算响应快照（JSON）；重复提交时原样返回，B7 幂等'))
            ->addColumn(Column::dateTime('started_at')->setNull(false)->setComment('开始时间'))
            ->addColumn(Column::dateTime('finished_at')->setNull(true)->setDefault(null)->setComment('结束时间'))
            ->addColumn(Column::string('client_ip', 45)->setNull(true)->setDefault(null)->setComment('客户端IP'))
            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('创建时间'))
            ->addIndex(['battle_token'], ['unique' => true, 'name' => 'uk_token'])
            ->addIndex(['user_id', 'started_at'], ['name' => 'idx_user_time'])
            ->addIndex(['user_id', 'level_code'], ['name' => 'idx_user_level'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_battle')) {
            $this->table('match3_battle')->drop()->save();
        }
    }
}
