<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 肉鸽节点关卡模型（06 §3.2）
 *
 * final_schema = 该玩家那一局的最终关卡（base + 生成 + 遗物 patch），
 * 是重放校验的初始条件，必须与首次下发分毫不差。
 *
 * @property int    $id
 * @property string $run_id
 * @property string $node_id
 * @property string $node_type
 * @property array  $final_schema
 * @property string $schema_fp
 * @property float  $difficulty
 * @property string $play_token
 * @property int    $status      0进行中 1已通关 2已失败
 * @property int    $score
 * @property int    $moves_used
 * @property array  $relic_choices
 * @property array  $node_payload   非战斗节点状态（商店库存 / 休息选项 / 事件与已选项）
 */
class Match3RunNode extends BaseModel
{
    protected $name = 'match3_run_node';

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
        'id'         => 'integer',
        'difficulty' => 'float',
        'status'     => 'integer',
        'score'      => 'integer',
        'moves_used' => 'integer',
    ];

    protected $json = ['final_schema', 'relic_choices', 'node_payload'];
    protected $jsonAssoc = true;

    public const STATUS_PLAYING = 0;
    public const STATUS_PASSED  = 1;
    public const STATUS_FAILED  = 2;

    /* ---------------- 非战斗节点类型 ---------------- */

    public const TYPE_SHOP  = 'shop';
    public const TYPE_REST  = 'rest';
    public const TYPE_EVENT = 'event';
}
