<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 好友助力记录模型（05 §A3.3）
 *
 * @property int    $id
 * @property string $help_type    energy/prop/rescue
 * @property int    $user_id      赠送者
 * @property int    $target_id    接收者
 * @property array  $payload
 * @property string $stage_code  卡关求助的关卡编码（rescue 专用）
 * @property int    $status       0待领取 1已领取 2已过期
 * @property string $day
 * @property string $expire_at
 */
class Match3FriendHelp extends BaseModel
{
    protected $name = 'match3_friend_help';

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
        'id'        => 'integer',
        'user_id'   => 'integer',
        'target_id' => 'integer',
        'status'    => 'integer',
    ];

    protected $json = ['payload'];
    protected $jsonAssoc = true;

    public const TYPE_ENERGY = 'energy';
    public const TYPE_PROP   = 'prop';
    public const TYPE_RESCUE = 'rescue';

    public const STATUS_PENDING = 0;
    public const STATUS_CLAIMED = 1;
    public const STATUS_EXPIRED = 2;

    /** 礼物有效期 24h（05 §A7.2） */
    public const TTL_HOURS = 24;

    public static function typeText(string $type): string
    {
        return match ($type) {
            self::TYPE_ENERGY => '送体力',
            self::TYPE_PROP   => '送道具',
            self::TYPE_RESCUE => '卡关求助',
            default           => $type,
        };
    }

    public static function statusText(int $status): string
    {
        return match ($status) {
            self::STATUS_CLAIMED => '已领取',
            self::STATUS_EXPIRED => '已过期',
            default              => '待领取',
        };
    }
}
