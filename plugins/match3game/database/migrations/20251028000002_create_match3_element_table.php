<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建消消乐元素/障碍物字典表（§5.3）
 */
class CreateMatch3ElementTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_element', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐元素/障碍物字典',
        ]);

        $table
            ->addColumn(Column::string('code', 32)->setNull(false)->setComment('元素码 red_gem/ice/bubble'))
            ->addColumn(Column::string('name', 64)->setNull(false)->setComment('元素名称'))
            ->addColumn(Column::tinyInteger('kind')->setNull(false)->setDefault(1)->setComment('类型: 1普通色块 2收集目标色块 3障碍物(清除型) 4特殊块'))
            ->addColumn(Column::string('color', 16)->setNull(true)->setDefault(null)->setComment('客户端配色标识'))
            ->addColumn(Column::smallInteger('weight')->setNull(false)->setSigned(false)->setDefault(0)->setComment('全局出现权重(可被关卡覆盖)'))
            ->addColumn(Column::string('clear_rule', 255)->setNull(true)->setDefault(null)->setComment('障碍清除规则描述/客户端行为key'))
            ->addColumn(Column::string('icon', 255)->setNull(true)->setDefault(null)->setComment('图标路径'))
            ->addColumn(Column::tinyInteger('status')->setNull(false)->setDefault(1)->setComment('状态: 1启用 0停用'))
            ->addColumn(Column::smallInteger('sort_order')->setNull(false)->setSigned(false)->setDefault(0)->setComment('排序序号'))
            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(false)->setComment('更新时间'))
            ->addIndex(['code'], ['unique' => true, 'name' => 'uk_code'])
            ->addIndex(['kind'], ['name' => 'idx_kind'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_element')) {
            $this->table('match3_element')->drop()->save();
        }
    }
}
