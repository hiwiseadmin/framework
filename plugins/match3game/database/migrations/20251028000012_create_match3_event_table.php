<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建肉鸽事件配置表（Phase 4 · 05 §B4 事件节点）
 *
 * 事件 = 一段文案 + 2~4 个选项，每个选项带一组 `effects`（见 RunEffects 效果字典）。
 * 纯配置表，策划自助维护（05 §B6.4：事件节点 ≥ 20 个）。
 *
 * effects 示例：
 *   [{"kind":"coin","value":60}]                       加 60 灵玉
 *   [{"kind":"relic","rarity":"rare"}]                  随机获得 1 个稀有遗物
 *   [{"kind":"buff_moves","value":5}]                   本局每关步数 +5
 *   [{"kind":"life","value":-1}]                        失败机会 -1
 *   [{"kind":"curse"}]                                  随机获得 1 个诅咒遗物
 */
class CreateMatch3EventTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_event', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐肉鸽事件配置',
        ]);

        $table
            ->addColumn(Column::string('event_id', 32)->setNull(false)->setComment('ev_xxx'))
            ->addColumn(Column::string('title', 64)->setNull(false))
            ->addColumn(Column::string('desc', 255)->setNull(true)->setDefault(null)->setComment('情境描述'))
            ->addColumn(Column::string('icon', 128)->setNull(true)->setDefault(null))
            ->addColumn(Column::text('options')->setNull(false)->setComment('[{id,label,desc,effects[],risk}]'))
            ->addColumn(Column::integer('weight')->setNull(false)->setSigned(false)->setDefault(100)->setComment('抽取权重'))
            ->addColumn(Column::integer('min_layer')->setNull(false)->setSigned(false)->setDefault(1)->setComment('最早可出现层'))
            ->addColumn(Column::integer('enabled')->setNull(false)->setSigned(false)->setDefault(1))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setDefault(null))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setDefault(null))
            ->addIndex(['event_id'], ['unique' => true, 'name' => 'uk_event_id'])
            ->addIndex(['enabled', 'min_layer'], ['name' => 'idx_enabled'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_event')) {
            $this->table('match3_event')->drop()->save();
        }
    }
}
