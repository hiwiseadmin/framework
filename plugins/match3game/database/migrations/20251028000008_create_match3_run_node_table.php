<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建消消乐肉鸽节点关卡表（06 §3.2）
 *
 * 每个节点一行，final_schema 是**该玩家那一局**的最终关卡（base + 生成 + 遗物 patch 后），
 * 作为后续重放校验的初始条件，分毫不差。
 *
 * ⚠️ 写多读少：进节点写、提交读一次。建议 90 天清理已结束局的节点数据（Phase 6）。
 */
class CreateMatch3RunNodeTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_run_node', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐肉鸽节点关卡',
        ]);

        $table
            ->addColumn(Column::string('run_id', 32)->setNull(false))
            ->addColumn(Column::string('node_id', 16)->setNull(false)->setComment('形如 2-3'))
            ->addColumn(Column::string('node_type', 16)->setNull(false)->setComment('battle/elite/boss/shop/rest/event'))
            ->addColumn(Column::text('final_schema')->setNull(false)->setComment('最终关卡 schema（重放初始条件）'))
            ->addColumn(Column::string('schema_fp', 8)->setNull(false)->setComment('schema 指纹'))
            ->addColumn(Column::decimal('difficulty', 5, 2)->setNull(false)->setDefault(1.00)->setComment('难度系数 D'))
            ->addColumn(Column::string('play_token', 64)->setNull(false)->setComment('游玩凭证，一次性'))
            ->addColumn(Column::integer('status')->setNull(false)->setSigned(false)->setDefault(0)->setComment('0进行中 1已通关 2已失败'))
            ->addColumn(Column::integer('score')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::integer('moves_used')->setNull(false)->setSigned(false)->setDefault(0))
            ->addColumn(Column::text('relic_choices')->setNull(true)->setDefault(null)->setComment('本次下发的遗物三选一 [{id,rarity}]'))
            ->addColumn(Column::text('node_payload')->setNull(true)->setDefault(null)->setComment('非战斗节点的状态：商店库存/已购项、休息选项、事件与已选项'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setDefault(null))
            ->addColumn(Column::dateTime('finished_at')->setNull(true)->setDefault(null))
            ->addIndex(['run_id', 'node_id'], ['unique' => true, 'name' => 'uk_run_node'])
            ->addIndex(['play_token'], ['name' => 'idx_token'])
            ->addIndex(['schema_fp'], ['name' => 'idx_fp'])
            ->addIndex(['create_time'], ['name' => 'idx_created'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_run_node')) {
            $this->table('match3_run_node')->drop()->save();
        }
    }
}
