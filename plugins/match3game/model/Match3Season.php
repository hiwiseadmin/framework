<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 赛季模型（05 §A3.4）
 *
 * @property int    $id
 * @property string $season_id
 * @property string $name
 * @property string $start_at
 * @property string $end_at
 * @property int    $status     0未开始 1进行中 2已结算
 * @property array  $reward_conf
 */
class Match3Season extends BaseModel
{
    protected $name = 'match3_season';

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
        'id'     => 'integer',
        'status' => 'integer',
    ];

    protected $json = ['reward_conf'];
    protected $jsonAssoc = true;

    public const STATUS_PENDING  = 0;
    public const STATUS_ACTIVE   = 1;
    public const STATUS_SETTLED  = 2;

    public static function statusText(int $status): string
    {
        return match ($status) {
            self::STATUS_ACTIVE  => '进行中',
            self::STATUS_SETTLED => '已结算',
            default              => '未开始',
        };
    }
}
