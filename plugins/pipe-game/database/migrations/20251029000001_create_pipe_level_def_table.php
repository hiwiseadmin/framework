<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建水管工关卡定义表
 *
 * 这是水管工游戏的专属数据表，与 wise-game-stage 包无关。
 * 包含管道布局、入口出口坐标、步数/时间限制等管道工特有字段。
 */
class CreatePipeLevelDefTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('pipe_level_def', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '水管工关卡定义表',
        ]);

        $table
            ->addColumn(Column::string('code', 32)->setNull(false)->setComment('关卡编码，唯一标识'))
            ->addColumn(Column::string('name', 64)->setNull(false)->setComment('关卡名称'))
            ->addColumn(Column::string('chapter', 64)->setNull(false)->setComment('所属章节'))
            ->addColumn(Column::integer('difficulty')->setNull(false)->setSigned(false)->setDefault(1)->setComment('难度: 1=简单, 2=普通, 3=困难'))
            ->addColumn(Column::string('description', 255)->setNull(true)->setDefault(null)->setComment('关卡描述'))
            ->addColumn(Column::text('grid_size')->setNull(false)->setComment('棋盘尺寸 JSON: [行数, 列数]'))
            ->addColumn(Column::text('pipe_layout')->setNull(false)->setComment('初始管道布局 JSON: 0/1 矩阵, 1=管道'))
            ->addColumn(Column::text('entrance')->setNull(false)->setComment('入口坐标 JSON: [row, col]'))
            ->addColumn(Column::text('exit')->setNull(false)->setComment('出口坐标 JSON: [row, col]'))
            ->addColumn(Column::integer('max_steps')->setNull(false)->setSigned(false)->setDefault(99)->setComment('最大步数限制'))
            ->addColumn(Column::integer('time_limit')->setNull(false)->setSigned(false)->setDefault(300)->setComment('时间限制（秒）'))
            ->addColumn(Column::integer('sort_order')->setNull(false)->setSigned(false)->setDefault(0)->setComment('排序序号'))
            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(false)->setComment('更新时间'))
            ->addIndex(['code'], ['unique' => true, 'name' => 'uk_code'])
            ->addIndex(['chapter'], ['name' => 'idx_chapter'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pipe_level_def')) {
            $this->table('pipe_level_def')->drop()->save();
        }
    }
}
