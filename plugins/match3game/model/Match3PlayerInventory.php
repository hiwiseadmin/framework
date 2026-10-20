<?php

declare(strict_types=1);

namespace plugin\match3game\model;

use plugin\BaseModel;
use wise\game\core\contract\InventoryModelInterface;
use wise\game\core\trait\InventoryTrait;

/**
 * 消消乐玩家背包模型（§5.4）
 *
 * 实现 InventoryModelInterface，使 wise-game-core 的 InventoryService
 * 能通过接口操作本表（金币/钻石/道具三种资源）。
 *
 * 关键约束：
 *   - 表具有 (user_id, item_id) 唯一索引（并发防重）
 *   - getCount/setCount 映射到 count 字段
 *   - getLockKey 返回主键，供 InventoryService 行锁使用
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $item_id
 * @property int    $count
 * @property int    $version
 */
class Match3PlayerInventory extends BaseModel implements InventoryModelInterface
{
    use InventoryTrait;

    protected $name = 'match3_player_inventory';

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
        'id'      => 'integer',
        'user_id' => 'integer',
        'count'   => 'integer',
        'version' => 'integer',
    ];

    /** 预定义物品标识 */
    public const ITEM_COINS   = 'coins';
    public const ITEM_DIAMOND = 'diamond';
    public const ITEM_ENERGY  = 'energy';

    // ================================================================
    //  InventoryModelInterface 实现
    // ================================================================

    public function getUserId(): int
    {
        return (int) $this->getData('user_id');
    }

    public function getItemId(): string
    {
        return (string) $this->getData('item_id');
    }

    public function getCount(): int
    {
        return (int) $this->getData('count');
    }

    public function setCount(int $count): void
    {
        $this->setAttr('count', $count);
    }

    public function getLockKey(): string
    {
        return (string) ($this->getData('id') ?? '');
    }
}
