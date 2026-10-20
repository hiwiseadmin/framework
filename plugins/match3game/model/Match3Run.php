<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 肉鸽局模型（06 §3.1）
 *
 * @property int    $id
 * @property string $run_id
 * @property int    $user_id
 * @property int    $map_seed
 * @property int    $level_seed
 * @property string $mode          normal / daily
 * @property string $daily_day     每日挑战日期 YYYY-MM-DD
 * @property int    $daily_retry   每日挑战重挑战次数
 * @property array  $buffs         本局全局增益 {"moves":10}
 * @property string $start_option
 * @property int    $layer
 * @property int    $node_index
 * @property int    $lives
 * @property int    $coins
 * @property array  $relics    [{"id":"r_xx","obtainedAt":"1-2"}] 按获得顺序
 * @property array  $map_data  关卡树
 * @property int    $status    0进行中 1已通关 2已失败 3已放弃
 * @property int    $score
 * @property int    $meta_reward
 */
class Match3Run extends BaseModel
{
    protected $name = 'match3_run';

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
        'id'          => 'integer',
        'user_id'     => 'integer',
        'map_seed'    => 'integer',
        'level_seed'  => 'integer',
        'daily_retry' => 'integer',
        'layer'       => 'integer',
        'node_index'  => 'integer',
        'lives'       => 'integer',
        'coins'       => 'integer',
        'status'      => 'integer',
        'score'       => 'integer',
        'meta_reward' => 'integer',
    ];

    protected $json = ['relics', 'map_data', 'buffs'];
    protected $jsonAssoc = true;

    /* ---------------- 状态常量（与 Match3RunService 保持一致） ---------------- */

    public const STATUS_PLAYING = 0;
    public const STATUS_CLEARED = 1;
    public const STATUS_FAILED  = 2;
    public const STATUS_ABANDON = 3;

    /* ---------------- 模式 ---------------- */

    public const MODE_NORMAL = 'normal';
    public const MODE_DAILY  = 'daily';

    /**
     * 本局全局增益（默认全 0）
     *
     * @return array<string,int>
     */
    public static function defaultBuffs(): array
    {
        return ['moves' => 0, 'scorePercent' => 0, 'coinPercent' => 0];
    }
}
