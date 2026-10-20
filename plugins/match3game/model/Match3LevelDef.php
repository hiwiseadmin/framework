<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;
use plugin\match3game\service\level\LevelSchemaFactory;

/**
 * 消消乐关卡定义模型（Level Schema v1）
 *
 * 布局主体 = regions（三层字符地图），玩法规则 = rules（mode/moves/goals/starScores），
 * P4 扩展 = behaviours / actors / props / generation。
 * 协议见 docs/match3/01-level-layout-design.md §4。
 *
 * @property int    $id
 * @property string $code
 * @property string $name
 * @property string $chapter
 * @property int    $difficulty
 * @property string $description
 * @property string $schema_ver
 * @property int    $version
 * @property int    $board_rows
 * @property int    $board_cols
 * @property int    $cell_size
 * @property array  $regions     区域数组（三层字符地图）
 * @property array  $portals     传送门
 * @property array  $rules       玩法规则 {mode,moves,goals,starScores}
 * @property array  $palette     调色板 {gems,theme}
 * @property array  $behaviours  P4 回合末行为
 * @property array  $actors      P4 Boss 演员
 * @property array  $props       P4 道具规则
 * @property array  $generation  随机关卡生成配置
 * @property string $seed_policy
 * @property int    $fixed_seed
 * @property array  $reward_conf 插件自有：关卡奖励 {coins}
 * @property string $pre_unlock
 * @property int    $unlock_stars
 * @property int    $sort_order
 * @property int    $status
 */
class Match3LevelDef extends BaseModel
{
    protected $name = 'match3_level_def';

    /**
     * 时间列的**写库类型**：DATETIME（迁移用 `Column::dateTime` 建的），不是 int
     *
     * 【为什么必须显式声明】全局 `auto_timestamp => true` 是"自动识别类型"，识别依据是模型自己的
     * `$schema`（think-orm `TimeStamp::checkTimeFieldType`）；本插件模型没声明 `$schema`，于是退化成
     * 写 int → `SQLSTATE[22007] Incorrect datetime value: … for column 'updated_at'`（2026-09-27 线上）。
     * 框架 BaseModel 走 `create_time` 且有自己的约定，所以没暴露这个问题。
     */
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /** 禁用软删除 */
    protected $deleteTime = false;

    protected $type = [
        'id'           => 'integer',
        'difficulty'   => 'integer',
        'version'      => 'integer',
        'board_rows'   => 'integer',
        'board_cols'   => 'integer',
        'cell_size'    => 'integer',
        'fixed_seed'   => 'integer',
        'unlock_stars' => 'integer',
        'sort_order'   => 'integer',
        'status'       => 'integer',
    ];

    /** JSON 字段（自动序列化/反序列化） */
    protected $json = [
        'regions',
        'portals',
        'rules',
        'palette',
        'behaviours',
        'actors',
        'props',
        'generation',
        'reward_conf',
    ];
    protected $jsonAssoc = true;

    /**
     * 输出完整 Level Schema v1 文档
     *
     * @return array<string,mixed>
     */
    public function toSchema(): array
    {
        return LevelSchemaFactory::fromRow($this->toArray());
    }
}
