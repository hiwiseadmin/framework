<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 群擂台成员模型（05 §A3.5）
 *
 * @property int    $id
 * @property string $group_id
 * @property string $period_id
 * @property int    $user_id
 * @property int    $score
 * @property int    $play_count
 * @property string $play_day
 * @property array  $stage_plays 每关每日次数 {"M3-0201":{"day":"2026-09-11","count":2}}
 */
class Match3GroupBattleMember extends BaseModel
{
    protected $name = 'match3_group_battle_member';

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
        'id'         => 'integer',
        'user_id'    => 'integer',
        'score'      => 'integer',
        'play_count' => 'integer',
    ];

    protected $json = ['stage_plays'];
    protected $jsonAssoc = true;
}
