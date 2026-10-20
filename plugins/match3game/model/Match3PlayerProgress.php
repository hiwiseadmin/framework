<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 消消乐玩家关卡聚合模型（§5.5）
 *
 * 逐关解锁/星数聚合/当前关的专用读模型。
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $level_code
 * @property int    $best_stars
 * @property int    $best_score
 * @property int    $play_count
 * @property string $first_pass_at
 * @property string $last_play_at
 */
class Match3PlayerProgress extends BaseModel
{
    protected $name = 'match3_player_progress';

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
    protected $deleteTime = false;

    protected $type = [
        'id'         => 'integer',
        'user_id'    => 'integer',
        'best_stars' => 'integer',
        'best_score' => 'integer',
        'play_count' => 'integer',
    ];
}
