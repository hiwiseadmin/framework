<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;

/**
 * 肉鸽局外进度模型（06 §3.3）
 *
 * user_id 即主键（无自增 id），只有 update_time。
 *
 * @property int   $user_id
 * @property int   $spirit_stone
 * @property array $unlocked_relics
 * @property array $unlocked_starts
 * @property int   $start_moves_bonus
 * @property int   $total_runs
 * @property int   $best_score
 * @property int   $season_points
 */
class Match3MetaProgress extends BaseModel
{
    protected $name = 'match3_meta_progress';

    /** 主键为 user_id，非自增 */
    protected $pk = 'user_id';
    protected $autoInc = false;

    /**
     * 时间列的**写库类型**：DATETIME（迁移用 `Column::dateTime` 建的），不是 int
     *
     * 【为什么必须显式声明】全局 `auto_timestamp => true` 是"自动识别类型"，识别依据是模型自己的
     * `$schema`（think-orm `TimeStamp::checkTimeFieldType`）；本插件模型没声明 `$schema`，于是退化成
     * 写 int → `SQLSTATE[22007] Incorrect datetime value: … for column 'updated_at'`（2026-09-27 线上）。
     * 框架 BaseModel 走 `create_time` 且有自己的约定，所以没暴露这个问题。
     */
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = false;
    protected $updateTime = 'update_time';

    protected $deleteTime = false;

    protected $type = [
        'user_id'           => 'integer',
        'spirit_stone'      => 'integer',
        'start_moves_bonus' => 'integer',
        'total_runs'        => 'integer',
        'best_score'        => 'integer',
        'season_points'     => 'integer',
    ];

    protected $json = ['unlocked_relics', 'unlocked_starts'];
    protected $jsonAssoc = true;

    /**
     * 取或初始化某用户的局外进度
     */
    public static function of(int $userId): self
    {
        $row = self::find($userId);
        if ($row) {
            return $row;
        }

        $row = new self();
        $row->user_id           = $userId;
        $row->spirit_stone      = 0;
        $row->unlocked_relics   = [];
        $row->unlocked_starts   = [];
        $row->start_moves_bonus = 0;
        $row->total_runs        = 0;
        $row->best_score        = 0;
        $row->season_points     = 0;
        $row->save();

        return $row;
    }
}
