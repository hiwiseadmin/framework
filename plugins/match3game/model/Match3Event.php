<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 肉鸽事件配置模型（Phase 4）
 *
 * 纯配置表，策划自助维护（05 §B6.4：事件节点 ≥ 20 个）。
 *
 * options 结构：
 *   [{
 *     "id": "accept",
 *     "label": "接受",
 *     "desc": "获得 60 灵玉，但失去 1 次机会",
 *     "risk": true,
 *     "effects": [{"kind":"coin","value":60},{"kind":"life","value":-1}]
 *   }]
 *
 * @property int    $id
 * @property string $event_id
 * @property string $title
 * @property string $desc
 * @property string $icon
 * @property array  $options
 * @property int    $weight
 * @property int    $min_layer
 * @property int    $enabled
 */
class Match3Event extends BaseModel
{
    protected $name = 'match3_event';

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
        'id'        => 'integer',
        'weight'    => 'integer',
        'min_layer' => 'integer',
        'enabled'   => 'integer',
    ];

    protected $json = ['options'];
    protected $jsonAssoc = true;
}
