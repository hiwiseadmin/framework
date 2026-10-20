<?php
namespace plugin\smartstock\event;

/**
 * 库存变更事件
 *
 * 当库存数量发生变更时触发，携带变更前后的库存信息。
 * 触发时机：
 * - 入库完成（库存增加）
 * - 出库完成（库存减少）
 * - 调拨完成（调出减少 + 调入增加）
 * - 盘点差异处理（盘盈/盘亏）
 */
class StockChangedEvent
{
    /**
     * @var int 商品ID
     */
    public $productId;

    /**
     * @var int 仓库ID
     */
    public $warehouseId;

    /**
     * @var int 变更前数量
     */
    public $before;

    /**
     * @var int 变更后数量
     */
    public $after;

    /**
     * @var int 变更类型：1入库 2出库 3调拨入 4调拨出 5盘点调整 6初始化
     */
    public $changeType;

    /**
     * @var string 关联单据类型
     */
    public $referenceType;

    /**
     * @var string 关联单据号
     */
    public $referenceNo;

    /**
     * @var int 操作人ID
     */
    public $operatorId;

    /**
     * @var string 备注
     */
    public $remark;

    public function __construct(
        int $productId,
        int $warehouseId,
        int $before,
        int $after,
        int $changeType = 1,
        string $referenceType = '',
        string $referenceNo = '',
        int $operatorId = 0,
        string $remark = ''
    ) {
        $this->productId     = $productId;
        $this->warehouseId   = $warehouseId;
        $this->before        = $before;
        $this->after         = $after;
        $this->changeType    = $changeType;
        $this->referenceType = $referenceType;
        $this->referenceNo   = $referenceNo;
        $this->operatorId    = $operatorId;
        $this->remark        = $remark;
    }
}
