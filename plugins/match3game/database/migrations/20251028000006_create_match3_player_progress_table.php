<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建消消乐玩家关卡聚合表（§5.5）
 *
 * 逐关解锁/星数聚合/当前关的专用读模型。
 */
class CreateMatch3PlayerProgressTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_player_progress', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐玩家关卡聚合(解锁判定读此表)',
        ]);

        $table
            ->addColumn(Column::integer('user_id')->setNull(false)->setSigned(false)->setComment('用户ID'))
            ->addColumn(Column::string('level_code', 32)->setNull(false)->setComment('关卡编码'))
            ->addColumn(Column::tinyInteger('best_stars')->setNull(false)->setSigned(false)->setDefault(0)->setComment('最佳星数(0未通关)'))
            ->addColumn(Column::integer('best_score')->setNull(false)->setDefault(0)->setComment('最佳得分'))
            ->addColumn(Column::integer('play_count')->setNull(false)->setSigned(false)->setDefault(0)->setComment('游玩次数'))
            ->addColumn(Column::dateTime('first_pass_at')->setNull(true)->setDefault(null)->setComment('首次通关时间'))
            ->addColumn(Column::dateTime('last_play_at')->setNull(true)->setDefault(null)->setComment('最近游玩时间'))
            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(false)->setComment('更新时间'))
            ->addIndex(['user_id', 'level_code'], ['unique' => true, 'name' => 'uk_user_level'])
            ->addIndex(['user_id'], ['name' => 'idx_user'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_player_progress')) {
            $this->table('match3_player_progress')->drop()->save();
        }
    }
}
