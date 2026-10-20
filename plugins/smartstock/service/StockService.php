<?php
/**
 * 库存服务
 *
 * 职责：
 * - 库存增加/减少/锁定/解锁
 * - 行级锁防并发
 * - 触发 StockChangedEvent
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\service;

use plugin\smartstock\model\Stock;
use plugin\smartstock\event\StockChangedEvent;
use think\facade\Db;
use think\facade\Event;

class StockService
{
    /**
     * 增加库存
     *
     * @param int   $productId   商品ID
     * @param int   $warehouseId 仓库ID
     * @param int   $quantity    增加数量
     * @param array $context     上下文信息 [referenceType, referenceNo, operatorId, remark]
     * @throws \Exception
     */
    public function addStock(int $productId, int $warehouseId, int $quantity, array $context = []): void
    {
        if ($quantity <= 0) {
            throw new \Exception('增加库存数量必须大于0');
        }

        Db::startTrans();
        try {
            $stock = Stock::where([
                'product_id'   => $productId,
                'warehouse_id' => $warehouseId,
            ])->lock(true)->find();

            if (!$stock) {
                $stock = new Stock();
                $stock->product_id   = $productId;
                $stock->warehouse_id = $warehouseId;
                $stock->quantity     = 0;
            }

            $before = $stock->quantity;
            $stock->quantity = $before + $quantity;
            $stock->save();

            Event::trigger(new StockChangedEvent(
                $productId,
                $warehouseId,
                $before,
                $stock->quantity,
                1, // 入库
                $context['referenceType'] ?? '',
                $context['referenceNo'] ?? '',
                $context['operatorId'] ?? 0,
                $context['remark'] ?? ''
            ));

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 减少库存（扣减）
     *
     * @param int   $productId   商品ID
     * @param int   $warehouseId 仓库ID
     * @param int   $quantity    减少数量
     * @param array $context     上下文信息
     * @throws \Exception
     */
    public function subtractStock(int $productId, int $warehouseId, int $quantity, array $context = []): void
    {
        if ($quantity <= 0) {
            throw new \Exception('扣减库存数量必须大于0');
        }

        Db::startTrans();
        try {
            $stock = Stock::where([
                'product_id'   => $productId,
                'warehouse_id' => $warehouseId,
            ])->lock(true)->find();

            if (!$stock) {
                throw new \Exception('库存记录不存在，无法扣减');
            }

            $available = $stock->quantity - $stock->locked_quantity;
            if ($available < $quantity) {
                throw new \Exception("库存不足，可用库存: {$available}，需要: {$quantity}");
            }

            $before = $stock->quantity;
            $stock->quantity = $before - $quantity;
            $stock->save();

            Event::trigger(new StockChangedEvent(
                $productId,
                $warehouseId,
                $before,
                $stock->quantity,
                2, // 出库
                $context['referenceType'] ?? '',
                $context['referenceNo'] ?? '',
                $context['operatorId'] ?? 0,
                $context['remark'] ?? ''
            ));

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 锁定库存
     *
     * @param int $productId   商品ID
     * @param int $warehouseId 仓库ID
     * @param int $quantity    锁定数量
     * @throws \Exception
     */
    public function lockStock(int $productId, int $warehouseId, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new \Exception('锁定数量必须大于0');
        }

        Db::startTrans();
        try {
            $stock = Stock::where([
                'product_id'   => $productId,
                'warehouse_id' => $warehouseId,
            ])->lock(true)->find();

            if (!$stock) {
                throw new \Exception('库存记录不存在，无法锁定');
            }

            $available = $stock->quantity - $stock->locked_quantity;
            if ($available < $quantity) {
                throw new \Exception("可锁定库存不足，可用: {$available}");
            }

            $stock->locked_quantity += $quantity;
            $stock->save();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 解锁库存
     *
     * @param int $productId   商品ID
     * @param int $warehouseId 仓库ID
     * @param int $quantity    解锁数量
     * @throws \Exception
     */
    public function unlockStock(int $productId, int $warehouseId, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new \Exception('解锁数量必须大于0');
        }

        Db::startTrans();
        try {
            $stock = Stock::where([
                'product_id'   => $productId,
                'warehouse_id' => $warehouseId,
            ])->lock(true)->find();

            if (!$stock) {
                throw new \Exception('库存记录不存在，无法解锁');
            }

            if ($stock->locked_quantity < $quantity) {
                throw new \Exception("锁定数量不足，当前锁定: {$stock->locked_quantity}");
            }

            $stock->locked_quantity -= $quantity;
            $stock->save();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 查询商品在各仓库的库存
     *
     * @param int $productId 商品ID
     * @return array
     */
    public function getStockByProduct(int $productId): array
    {
        return Stock::where('product_id', $productId)
            ->with(['warehouse'])
            ->select()
            ->toArray();
    }

    /**
     * 批量查询库存
     *
     * @param array $productIds  商品ID数组
     * @param int   $warehouseId 仓库ID（0=全部）
     * @return array
     */
    public function batchGetStock(array $productIds, int $warehouseId = 0): array
    {
        $query = Stock::whereIn('product_id', $productIds);
        if ($warehouseId > 0) {
            $query->where('warehouse_id', $warehouseId);
        }
        return $query->with(['warehouse', 'product'])->select()->toArray();
    }

    /**
     * 获取可用库存
     *
     * @param int $productId   商品ID
     * @param int $warehouseId 仓库ID
     * @return int
     */
    public function getAvailableStock(int $productId, int $warehouseId): int
    {
        $stock = Stock::where([
            'product_id'   => $productId,
            'warehouse_id' => $warehouseId,
        ])->find();

        if (!$stock) {
            return 0;
        }

        return max(0, $stock->quantity - $stock->locked_quantity);
    }

    /**
     * 直接设置库存数量（盘点差异调整）
     *
     * @param int   $productId   商品ID
     * @param int   $warehouseId 仓库ID
     * @param int   $newQuantity 新数量
     * @param array $context     上下文信息
     * @throws \Exception
     */
    public function updateStock(int $productId, int $warehouseId, int $newQuantity, array $context = []): void
    {
        if ($newQuantity < 0) {
            throw new \Exception('库存数量不能小于0');
        }

        Db::startTrans();
        try {
            $stock = Stock::where([
                'product_id'   => $productId,
                'warehouse_id' => $warehouseId,
            ])->lock(true)->find();

            if (!$stock) {
                $stock = new Stock();
                $stock->product_id   = $productId;
                $stock->warehouse_id = $warehouseId;
                $stock->quantity     = 0;
            }

            $before = $stock->quantity;
            $stock->quantity = $newQuantity;
            $stock->save();

            Event::trigger(new StockChangedEvent(
                $productId,
                $warehouseId,
                $before,
                $stock->quantity,
                5, // 盘点调整
                $context['referenceType'] ?? 'check',
                $context['referenceNo'] ?? '',
                $context['operatorId'] ?? 0,
                $context['remark'] ?? ''
            ));

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }
}
