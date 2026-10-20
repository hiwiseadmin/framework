<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 每日挑战模型（05 §B3 / §B7.5）
 *
 * 一行 = 一天。当日全服共用同一 `map_seed` / `level_seed` / `relic_pool`。
 *
 * @property int    $id
 * @property string $day         YYYY-MM-DD
 * @property int    $map_seed
 * @property int    $level_seed
 * @property int    $layers
 * @property array  $relic_pool  当日固定遗物序列
 * @property string $stage_code
 * @property int    $stone_reward
 * @property int    $status
 */
class Match3DailyChallenge extends BaseModel
{
    protected $name = 'match3_daily_challenge';

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
    protected $updateTime = false;

    protected $deleteTime = false;

    protected $type = [
        'id'           => 'integer',
        'map_seed'     => 'integer',
        'level_seed'   => 'integer',
        'layers'       => 'integer',
        'stone_reward' => 'integer',
        'status'       => 'integer',
    ];

    protected $json = ['relic_pool'];
    protected $jsonAssoc = true;
}
