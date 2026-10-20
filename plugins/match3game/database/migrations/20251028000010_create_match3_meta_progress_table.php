<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建消消乐局外进度表（06 §3.3）
 *
 * 灵石（meta 货币）+ 永久解锁项。user_id 即主键，与用户数一致。
 */
class CreateMatch3MetaProgressTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_meta_progress', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐肉鸽局外进度',
        ]);

        $table
            ->addColumn(Column::integer('user_id')->setNull(false)->setSigned(false)->setComment('用户 ID（主键）'))
            ->addColumn(Column::integer('spirit_stone')->setNull(false)->setSigned(false)->setDefault(0)->setComment('灵石（meta 货币）'))
            ->addColumn(Column::text('unlocked_relics')->setNull(true)->setDefault(null)->setComment('已解锁遗物/遗物位'))
            ->addColumn(Column::text('unlocked_starts')->setNull(true)->setDefault(null)->setComment('已解锁起点角色'))
            ->addColumn(Column::integer('start_moves_bonus')->setNull(false)->setSigned(false)->setDefault(0)->setComment('起始步数 +N（三级）'))
            ->addColumn(Column::integer('total_runs')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::integer('best_score')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::integer('season_points')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setDefault(null))
            ->addIndex(['user_id'], ['unique' => true, 'name' => 'uk_user'])
            ->addIndex(['best_score'], ['name' => 'idx_best_score'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_meta_progress')) {
            $this->table('match3_meta_progress')->drop()->save();
        }
    }
}
