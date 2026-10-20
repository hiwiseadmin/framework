<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建消消乐关卡定义表（Level Schema v1）
 *
 * 关卡数据模型以 docs/match3/01-level-layout-design.md §4 的 Level Schema v1 为准：
 *   - 布局主体 = regions（三层字符地图 terrain / entity / overlay）
 *   - 玩法规则 = rules（mode / moves / goals / starScores）
 *   - P4 扩展  = behaviours / actors / props / generation（02 §9.1）
 *
 * 冗余列 board_cols / board_rows / cell_size 用于后台列表检索与前端预计算画布尺寸，
 * 避免每次解析 regions JSON（01 §10.1）。
 *
 * JSON 列统一用 text 存储（与项目其余迁移保持一致）。
 */
class CreateMatch3LevelDefTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('match3_level_def', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '消消乐关卡定义表（Level Schema v1）',
        ]);

        $table
            // —— 基础信息 ——
            ->addColumn(Column::string('code', 32)->setNull(false)->setComment('关卡编码 M3-0101，唯一标识'))
            ->addColumn(Column::string('name', 64)->setNull(false)->setComment('关卡名称'))
            ->addColumn(Column::string('chapter', 64)->setNull(false)->setComment('所属章节'))
            ->addColumn(Column::integer('difficulty')->setNull(false)->setSigned(false)->setDefault(1)->setComment('难度 1-5'))
            ->addColumn(Column::string('description', 255)->setNull(true)->setDefault(null)->setComment('关卡描述'))

            // —— 协议元信息 ——
            ->addColumn(Column::string('schema_ver', 16)->setNull(false)->setDefault('match3/level@1')->setComment('Schema 版本'))
            ->addColumn(Column::integer('version')->setNull(false)->setSigned(false)->setDefault(1)->setComment('内容版本，变更则前端缓存失效'))

            // —— 棋盘几何（冗余，便于检索与预计算）——
            ->addColumn(Column::integer('board_cols')->setNull(false)->setSigned(false)->setDefault(9)->setComment('棋盘列数'))
            ->addColumn(Column::integer('board_rows')->setNull(false)->setSigned(false)->setDefault(9)->setComment('棋盘行数'))
            ->addColumn(Column::integer('cell_size')->setNull(false)->setSigned(false)->setDefault(76)->setComment('单元格逻辑像素'))

            // —— 布局主体（Schema v1）——
            ->addColumn(Column::text('regions')->setNull(false)->setComment('区域数组，含 terrain/entity/overlay 三层字符地图'))
            ->addColumn(Column::text('portals')->setNull(true)->setDefault(null)->setComment('传送门配置'))
            ->addColumn(Column::text('rules')->setNull(false)->setComment('rules: mode/moves/goals/starScores/loseConditions'))
            ->addColumn(Column::text('palette')->setNull(true)->setDefault(null)->setComment('主题与调色板 {gems,theme}'))

            // —— P4 扩展（02 §9.1，协议一次到位，内容分批开放）——
            ->addColumn(Column::text('behaviours')->setNull(true)->setDefault(null)->setComment('回合末行为列表（传送带/蔓延/生长/生成器）'))
            ->addColumn(Column::text('actors')->setNull(true)->setDefault(null)->setComment('棋盘外演员（Boss）'))
            ->addColumn(Column::text('props')->setNull(true)->setDefault(null)->setComment('道具白名单与消耗规则'))
            ->addColumn(Column::text('generation')->setNull(true)->setDefault(null)->setComment('随机关卡生成配置 fixed/template/generator'))

            // —— 随机策略 ——
            ->addColumn(Column::string('seed_policy', 16)->setNull(false)->setDefault('server')->setComment('server=后端下发 seed；fixed=固定种子'))
            ->addColumn(Column::integer('fixed_seed')->setNull(true)->setSigned(true)->setDefault(null)->setComment('seedPolicy=fixed 时的固定种子'))

            // —— 插件自有（非 Schema v1 字段）——
            ->addColumn(Column::text('reward_conf')->setNull(true)->setDefault(null)->setComment('关卡奖励 {coins:[首通,二星,三星]}'))

            // —— 解锁与状态 ——
            ->addColumn(Column::string('pre_unlock', 32)->setNull(true)->setDefault(null)->setComment('前置关卡 code（逐关解锁）'))
            ->addColumn(Column::integer('unlock_stars')->setNull(false)->setSigned(false)->setDefault(0)->setComment('解锁所需前置关星数 0=前置关通关即解锁（默认），>0 则需手动配置'))
            ->addColumn(Column::integer('sort_order')->setNull(false)->setSigned(false)->setDefault(0)->setComment('排序序号'))
            ->addColumn(Column::integer('status')->setNull(false)->setSigned(false)->setDefault(1)->setComment('状态 0草稿 1已发布 2下架'))

            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(false)->setComment('更新时间'))

            ->addIndex(['code'], ['unique' => true, 'name' => 'uk_code'])
            ->addIndex(['chapter'], ['name' => 'idx_chapter'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['pre_unlock'], ['name' => 'idx_pre'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('match3_level_def')) {
            $this->table('match3_level_def')->drop()->save();
        }
    }
}
