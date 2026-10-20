<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 消消乐对局会话模型（§5.5）
 *
 * battle_token 为结算幂等键。
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $level_code
 * @property string $battle_token
 * @property int    $seed
 * @property int    $status       0playing 1done 2abandoned 3expired
 * @property string $outcome      win/lose/quit
 * @property int    $moves_used
 * @property int    $score
 * @property array  $collect_result
 * @property array  $reward_given
 * @property array|null $settle_result 首次结算响应快照（B7 幂等；老数据为 null）
 * @property string $started_at
 * @property string $finished_at
 * @property string $client_ip
 */
class Match3Battle extends BaseModel
{
    protected $name = 'match3_battle';

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
    protected $updateTime = false; // 本表无 update_time
    protected $deleteTime = false;

    protected $type = [
        'id'         => 'integer',
        'user_id'    => 'integer',
        'seed'       => 'integer',
        'status'     => 'integer',
        'moves_used' => 'integer',
        'score'      => 'integer',
    ];

    protected $json = ['collect_result', 'reward_given', 'settle_result'];
    protected $jsonAssoc = true;

    /** 对局状态常量 */
    public const STATUS_PLAYING   = 0;
    public const STATUS_DONE      = 1;
    public const STATUS_ABANDONED = 2;
    public const STATUS_EXPIRED   = 3;

    /** 对局结果常量 */
    public const OUTCOME_WIN  = 'win';
    public const OUTCOME_LOSE = 'lose';
    public const OUTCOME_QUIT = 'quit';
}
