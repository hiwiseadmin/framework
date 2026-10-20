<?php
/**
 * 出库服务
 *
 * 职责：
 * - 出库单创建（含明细）
 * - 出库策略管理
 * - 库存校验和扣减
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\service;

use plugin\smartstock\model\StockOut;
use plugin\smartstock\model\StockOutItem;
use plugin\smartstock\service\Strategy\StockOutStrategy;
use plugin\smartstock\service\Strategy\FifoStockOutStrategy;
use think\facade\Db;

class StockOutService
{
    /**
     * 创建出库单 + 明细
     *
     * @param array $data  主单据数据
     * @param array $items 明细列表 [['product_id' => 1, 'quantity' => 10, 'price' => 99.00], ...]
     * @return array ['id' => int, 'order_no' => string]
     * @throws \Exception
     */
    public function create(array $data, array $items): array
    {
        Db::startTrans();
        try {
            $stockOut = new StockOut();
            $stockOut->save($data);

            $totalQuantity = 0;
            $totalAmount   = 0;

            foreach ($items as $item) {
                $itemData = [
                    'stock_out_id' => $stockOut->id,
                    'product_id'   => $item['product_id'],
                    'quantity'     => $item['quantity'],
                    'price'        => $item['price'] ?? 0,
                    'amount'       => ($item['quantity'] * ($item['price'] ?? 0)),
                    'remark'       => $item['remark'] ?? '',
                ];

                $stockOutItem = new StockOutItem();
                $stockOutItem->save($itemData);

                $totalQuantity += $item['quantity'];
                $totalAmount   += $itemData['amount'];
            }

            // 更新汇总
            $stockOut->total_quantity = $totalQuantity;
            $stockOut->total_amount   = $totalAmount;
            $stockOut->save();

            Db::commit();

            return [
                'id'       => $stockOut->id,
                'order_no' => $stockOut->order_no,
            ];
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 获取出库策略实例
     *
     * @return StockOutStrategy
     */
    public function getStrategy(): StockOutStrategy
    {
        $strategyName = config('smartstock.app.stock_out_strategy', 'fifo');

        return match ($strategyName) {
            'fifo' => new FifoStockOutStrategy(),
            default => new FifoStockOutStrategy(),
        };
    }

    /**
     * 校验库存充足性
     *
     * @param int $stockOutId 出库单ID
     * @return bool
     * @throws \Exception
     */
    public function validateStock(int $stockOutId): bool
    {
        $stockOut = StockOut::with(['items'])->find($stockOutId);
        if (!$stockOut) {
            throw new \Exception('出库单不存在');
        }

        $items = $stockOut->items->toArray();
        $strategy = $this->getStrategy();

        return $strategy->validate($items, $stockOut->warehouse_id);
    }

    /**
     * 执行库存扣减
     *
     * @param int $stockOutId 出库单ID
     * @return bool
     * @throws \Exception
     */
    public function deductStock(int $stockOutId): bool
    {
        $stockOut = StockOut::with(['items'])->find($stockOutId);
        if (!$stockOut) {
            throw new \Exception('出库单不存在');
        }

        $items = $stockOut->items->toArray();
        $strategy = $this->getStrategy();

        $strategy->deduct($stockOutId, $items, $stockOut->warehouse_id);

        // 更新出库单冗余状态
        $stockOut->status = 2;
        $stockOut->save();

        return true;
    }
}
