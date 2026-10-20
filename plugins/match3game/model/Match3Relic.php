<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 遗物配置模型（06 §3.4）
 *
 * 遗物 = 对 Level Schema 的一次 JSON Patch（05 §B2）。
 * 纯配置表，策划自助维护，不需要发版。
 *
 * @property int    $id
 * @property string $relic_id
 * @property string $name
 * @property string $rarity    common/rare/epic/cursed
 * @property string $category  board/rule/resource/behavior/risk/meta
 * @property string $desc
 * @property string $icon
 * @property array  $patch
 * @property int    $weight
 * @property int    $unlock_cost  0=初始进池；>0=需灵石解锁
 * @property int    $enabled
 */
class Match3Relic extends BaseModel
{
    protected $name = 'match3_relic';

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
        'id'          => 'integer',
        'weight'      => 'integer',
        'unlock_cost' => 'integer',
        'enabled'     => 'integer',
    ];

    protected $json = ['patch'];
    protected $jsonAssoc = true;

    /** 六类遗物（05 §B5） */
    public const CATEGORIES = ['board', 'rule', 'resource', 'behavior', 'risk', 'meta'];

    /** 四种稀有度（05 §B7.2） */
    public const RARITIES = ['common', 'rare', 'epic', 'cursed'];
}
