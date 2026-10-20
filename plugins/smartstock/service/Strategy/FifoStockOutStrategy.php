<?php
/**
 * 先进先出（FIFO）出库策略
 *
 * 按入库时间最早的批次的商品优先出库。
 * Phase 2 简化实现：按库存记录逐个校验和扣减。
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\service\Strategy;

use plugin\smartstock\service\StockService;

class FifoStockOutStrategy implements StockOutStrategy
{
    /**
     * @var StockService
     */
    protected $stockService;

    public function __construct()
    {
        $this->stockService = new StockService();
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $items, int $warehouseId): bool
    {
        foreach ($items as $item) {
            $productId = $item['product_id'];
            $quantity  = $item['quantity'];

            if ($quantity <= 0) {
                throw new \Exception("商品ID {$productId} 出库数量必须大于0");
            }

            $available = $this->stockService->getAvailableStock($productId, $warehouseId);
            if ($available < $quantity) {
                throw new \Exception("商品ID {$productId} 库存不足，可用: {$available}，需要: {$quantity}");
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableStock(int $productId, int $warehouseId): int
    {
        return $this->stockService->getAvailableStock($productId, $warehouseId);
    }

    /**
     * {@inheritdoc}
     */
    public function deduct(int $stockOutId, array $items, int $warehouseId): void
    {
        foreach ($items as $item) {
            $productId = $item['product_id'];
            $quantity  = $item['quantity'];

            $this->stockService->subtractStock($productId, $warehouseId, $quantity, [
                'referenceType' => 'stock_out',
                'referenceNo'   => (string)$stockOutId,
                'remark'        => '出库扣减',
            ]);
        }
    }
}
