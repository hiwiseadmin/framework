<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 对战场次模型（05 §A3.1）
 *
 * @property int    $id
 * @property string $battle_id
 * @property string $mode          friend/ladder/group/daily
 * @property string $stage_code
 * @property int    $seed          锁定的随机种子（双方一致）
 * @property array  $relics
 * @property string $group_id      加密群标识
 * @property string $period_id
 * @property string $season_id
 * @property int    $challenger_id
 * @property int    $opponent_id
 * @property int    $status
 * @property int    $winner_id
 * @property string $share_title
 */
class Match3PvpBattle extends BaseModel
{
    protected $name = 'match3_pvp_battle';

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
        'id'            => 'integer',
        'seed'          => 'integer',
        'challenger_id' => 'integer',
        'opponent_id'   => 'integer',
        'status'        => 'integer',
        'winner_id'     => 'integer',
    ];

    protected $json = ['relics'];
    protected $jsonAssoc = true;

    /* ---------------- 模式 ---------------- */

    public const MODE_FRIEND = 'friend';
    public const MODE_LADDER = 'ladder';
    public const MODE_GROUP  = 'group';
    public const MODE_DAILY  = 'daily';

    /* ---------------- 状态 ---------------- */

    public const STATUS_PENDING  = 0; // 待接受
    public const STATUS_PLAYING  = 1; // 进行中
    public const STATUS_FINISHED = 2; // 已完成
    public const STATUS_EXPIRED  = 3; // 已过期
    public const STATUS_REFUSED  = 4; // 已拒绝

    /** 挑战卡有效期 24h（05 §A7.4） */
    public const TTL_HOURS = 24;

    /**
     * 状态文案
     */
    public static function statusText(int $status): string
    {
        return match ($status) {
            self::STATUS_PENDING  => '待接受',
            self::STATUS_PLAYING  => '进行中',
            self::STATUS_FINISHED => '已完成',
            self::STATUS_EXPIRED  => '已过期',
            self::STATUS_REFUSED  => '已拒绝',
            default               => '未知',
        };
    }
}
