<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建好友助力记录表（05 §A3.3）
 *
 * help_type：
 *   energy  送体力（+5，赠送者 +20 金币）
 *   prop    送道具
 *   rescue  卡关求助（同一关失败 3 次触发，好友代打）
 *
 * ⚠️ **每日上限不用唯一索引硬约束**（05 §A3.3 明确警告）：
 * `create_time` 精确到秒，同一个"每天 5 次"在跨天/同秒并发下会失效或被误判，
 * 因此改为 **Redis 计数 `match3:gift:{userId}:{date}`**（见 FriendService），
 * 本表只做流水与领取状态。
 */
class CreateMatch3FriendHelpTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_friend_help', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐好友助力记录',
        ]);

        $table
            ->addColumn(Column::string('help_type', 16)->setNull(false)->setComment('energy/prop/rescue'))
            ->addColumn(Column::integer('user_id')->setNull(false)->setSigned(false)->setComment('赠送者'))
            ->addColumn(Column::integer('target_id')->setNull(false)->setSigned(false)->setComment('接收者'))
            ->addColumn(Column::text('payload')->setNull(true)->setDefault(null)->setComment('{count:5} / {prop:"prop.hammer"} / {fails:3}'))
            ->addColumn(Column::string('stage_code', 32)->setNull(true)->setDefault(null)->setComment('卡关求助的关卡编码（rescue 专用，便于索引查询；JSON 里的同名字段只做展示）'))
            ->addColumn(Column::integer('status')->setNull(false)->setSigned(false)->setDefault(0)->setComment('0待领取 1已领取 2已过期'))
            ->addColumn(Column::string('day', 10)->setNull(false)->setComment('赠送日 YYYY-MM-DD（便于按日统计/清理）'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setDefault(null))
            ->addColumn(Column::dateTime('expire_at')->setNull(true)->setDefault(null)->setComment('创建 + 24h'))
            ->addColumn(Column::dateTime('claimed_at')->setNull(true)->setDefault(null))
            ->addIndex(['target_id', 'status'], ['name' => 'idx_target'])
            ->addIndex(['user_id', 'day'], ['name' => 'idx_user_day'])
            ->addIndex(['status', 'expire_at'], ['name' => 'idx_expire'])
            ->addIndex(['help_type', 'status', 'stage_code'], ['name' => 'idx_rescue'])
            // 90 天清理按 day 扫描（P6-B02）：没有它清理任务会全表扫
            ->addIndex(['day'], ['name' => 'idx_day'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_friend_help')) {
            $this->table('match3_friend_help')->drop()->save();
        }
    }
}
