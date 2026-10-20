<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 对战参与记录模型（05 §A3.2）
 *
 * @property int    $id
 * @property string $battle_id
 * @property int    $user_id
 * @property int    $score
 * @property int    $moves
 * @property int    $stars
 * @property int    $max_combo
 * @property int    $status       0未开始 1进行中 2已完成 3超时
 * @property string $play_token
 * @property string $sub_hash
 * @property int    $is_cheat
 * @property string $cheat_reason
 * @property int    $replayed
 * @property int    $points_delta
 */
class Match3PvpPlayer extends BaseModel
{
    protected $name = 'match3_pvp_player';

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
    protected $updateTime = false;
    protected $deleteTime = false;

    protected $type = [
        'id'           => 'integer',
        'user_id'      => 'integer',
        'score'        => 'integer',
        'moves'        => 'integer',
        'stars'        => 'integer',
        'max_combo'    => 'integer',
        'status'       => 'integer',
        'is_cheat'     => 'integer',
        'replayed'     => 'integer',
        'points_delta' => 'integer',
    ];

    public const STATUS_IDLE     = 0; // 未开始
    public const STATUS_PLAYING  = 1; // 进行中
    public const STATUS_DONE     = 2; // 已完成
    public const STATUS_TIMEOUT  = 3; // 超时

    public const CHEAT_NONE     = 0;
    public const CHEAT_SUSPECT  = 1;
    public const CHEAT_CONFIRMED = 2;

    public static function statusText(int $status): string
    {
        return match ($status) {
            self::STATUS_IDLE    => '未开始',
            self::STATUS_PLAYING => '进行中',
            self::STATUS_DONE    => '已完成',
            self::STATUS_TIMEOUT => '超时',
            default              => '未知',
        };
    }

    public static function cheatText(int $isCheat): string
    {
        return match ($isCheat) {
            self::CHEAT_SUSPECT   => '可疑',
            self::CHEAT_CONFIRMED => '确认作弊',
            default               => '正常',
        };
    }
}
