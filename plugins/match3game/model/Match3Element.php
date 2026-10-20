<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 消消乐元素/障碍物字典模型（§5.3）
 *
 * @property int    $id
 * @property string $code
 * @property string $name
 * @property int    $kind        1普通色块 2收集目标 3障碍物 4特殊块
 * @property string $color
 * @property int    $weight
 * @property string $clear_rule
 * @property string $icon
 * @property int    $status
 * @property int    $sort_order
 */
class Match3Element extends BaseModel
{
    protected $name = 'match3_element';

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
        'kind'       => 'integer',
        'weight'     => 'integer',
        'status'     => 'integer',
        'sort_order' => 'integer',
    ];

    /** 元素种类常量 */
    public const KIND_NORMAL   = 1; // 普通色块
    public const KIND_COLLECT  = 2; // 收集目标色块
    public const KIND_OBSTACLE = 3; // 障碍物（清除型）
    public const KIND_SPECIAL  = 4; // 特殊块（需连锁触发）
}
