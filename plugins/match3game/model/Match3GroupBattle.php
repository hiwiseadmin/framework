<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 群擂台周期模型（05 §A3.5）
 *
 * 【合规】`group_id` 只能是加密后的群标识（openGId），禁止明文群名。
 *
 * @property int    $id
 * @property string $group_id
 * @property string $period_id
 * @property array  $stage_pool
 * @property int    $seed
 * @property int    $total_score
 * @property int    $member_cnt
 * @property int    $rank
 */
class Match3GroupBattle extends BaseModel
{
    protected $name = 'match3_group_battle';

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
        'id'          => 'integer',
        'seed'        => 'integer',
        'total_score' => 'integer',
        'member_cnt'  => 'integer',
        'rank'        => 'integer',
    ];

    protected $json = ['stage_pool'];
    protected $jsonAssoc = true;

    /** 群内 Top5 分数累加（05 §A7.3） */
    public const TOP_N = 5;

    /** 个人每关每日可打次数 */
    public const DAILY_PLAYS = 3;
}
