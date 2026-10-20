<?php
/**
 * 出库策略接口
 *
 * 定义出库校验、可用库存查询和扣减的标准接口。
 * 不同出库算法（FIFO、FEFO等）实现此接口。
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\service\Strategy;

interface StockOutStrategy
{
    /**
     * 校验出库明细是否满足库存要求
     *
     * @param array $items       明细列表 [['product_id' => 1, 'quantity' => 10], ...]
     * @param int   $warehouseId 仓库ID
     * @return bool
     * @throws \Exception 库存不足时抛出异常
     */
    public function validate(array $items, int $warehouseId): bool;

    /**
     * 获取商品可用库存
     *
     * @param int $productId   商品ID
     * @param int $warehouseId 仓库ID
     * @return int
     */
    public function getAvailableStock(int $productId, int $warehouseId): int;

    /**
     * 执行扣减
     *
     * @param int   $stockOutId  出库单ID
     * @param array $items       明细列表
     * @param int   $warehouseId 仓库ID
     * @return void
     * @throws \Exception
     */
    public function deduct(int $stockOutId, array $items, int $warehouseId): void;
}
