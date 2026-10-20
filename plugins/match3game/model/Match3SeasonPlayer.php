<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 赛季玩家积分模型（05 §A3.4 / §A7.1）
 *
 * @property int    $id
 * @property string $season_id
 * @property int    $user_id
 * @property int    $points
 * @property string $tier
 * @property int    $wins
 * @property int    $losses
 * @property int    $best_score
 * @property int    $reward_claimed
 * @property string $last_first_win_day
 */
class Match3SeasonPlayer extends BaseModel
{
    protected $name = 'match3_season_player';

    /**
     * 时间列的**写库类型**：DATETIME（迁移用 `Column::dateTime` 建的），不是 int
     *
     * 【为什么必须显式声明】全局 `auto_timestamp => true` 是"自动识别类型"，识别依据是模型自己的
     * `$schema`（think-orm `TimeStamp::checkTimeFieldType`）；本插件模型没声明 `$schema`，于是退化成
     * 写 int → `SQLSTATE[22007] Incorrect datetime value: … for column 'updated_at'`（2026-09-27 线上）。
     * 框架 BaseModel 走 `create_time` 且有自己的约定，所以没暴露这个问题。
     */
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = false;
    protected $updateTime = 'update_time';
    protected $deleteTime = false;

    protected $type = [
        'id'             => 'integer',
        'user_id'        => 'integer',
        'points'         => 'integer',
        'wins'           => 'integer',
        'losses'         => 'integer',
        'best_score'     => 'integer',
        'reward_claimed' => 'integer',
    ];
}
