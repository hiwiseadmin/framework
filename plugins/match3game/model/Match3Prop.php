<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 消消乐道具定义模型（§5.3）
 *
 * @property int    $id
 * @property string $code
 * @property string $name
 * @property int    $kind        1开局道具 2局内道具 3购买资源包
 * @property array  $effect      效果参数 {type,...}
 * @property array  $price       价格 {coin}/{diamond}/{ad}
 * @property string $ad_unit_id  激励视频广告位
 * @property int    $per_battle_limit 单局使用上限
 * @property string $icon
 * @property int    $status
 */
class Match3Prop extends BaseModel
{
    protected $name = 'match3_prop';

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
        'id'               => 'integer',
        'kind'             => 'integer',
        'per_battle_limit' => 'integer',
        'status'           => 'integer',
    ];

    /** JSON 字段 */
    protected $json = ['effect', 'price'];
    protected $jsonAssoc = true;

    /** 道具种类常量 */
    public const KIND_START  = 1; // 开局道具
    public const KIND_BATTLE = 2; // 局内道具
    public const KIND_PACK   = 3; // 购买资源包
}
