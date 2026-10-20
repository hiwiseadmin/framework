<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建消消乐道具定义表（§5.3）
 */
class CreateMatch3PropTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_prop', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐道具定义',
        ]);

        $table
            ->addColumn(Column::string('code', 32)->setNull(false)->setComment('道具码 prop.hammer 等'))
            ->addColumn(Column::string('name', 64)->setNull(false)->setComment('道具名称'))
            ->addColumn(Column::tinyInteger('kind')->setNull(false)->setDefault(1)->setComment('类型: 1开局道具 2局内道具 3购买资源包'))
            ->addColumn(Column::text('effect')->setNull(true)->setDefault(null)->setComment('效果参数 JSON: {type:add_moves,count:5}/{type:hammer}'))
            ->addColumn(Column::text('price')->setNull(true)->setDefault(null)->setComment('价格 JSON: {coin:500}/{diamond:10}/{ad:1}'))
            ->addColumn(Column::string('ad_unit_id', 64)->setNull(true)->setDefault(null)->setComment('激励视频广告位(price.ad=1 时)'))
            ->addColumn(Column::tinyInteger('per_battle_limit')->setNull(false)->setDefault(0)->setComment('单局使用上限 0=不限'))
            ->addColumn(Column::string('icon', 255)->setNull(true)->setDefault(null)->setComment('图标路径'))
            ->addColumn(Column::tinyInteger('status')->setNull(false)->setDefault(1)->setComment('状态: 1启用 0停用'))
            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(false)->setComment('更新时间'))
            ->addIndex(['code'], ['unique' => true, 'name' => 'uk_code'])
            ->addIndex(['kind'], ['name' => 'idx_kind'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_prop')) {
            $this->table('match3_prop')->drop()->save();
        }
    }
}
