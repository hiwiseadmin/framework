<?php
namespace plugin\smartstock\service;

use plugin\smartstock\model\Warehouse;
use plugin\smartstock\model\Stock;

/**
 * 仓库服务
 *
 * 职责：
 * - 仓库 CRUD 辅助
 * - 仓库库存统计
 */
class WarehouseService
{
    /**
     * 获取仓库库存汇总
     *
     * @param int $warehouseId 仓库ID
     * @return array
     */
    public function getStockSummary(int $warehouseId): array
    {
        $stocks = Stock::where('warehouse_id', $warehouseId)
            ->with(['product'])
            ->select();

        $totalQuantity = 0;
        $totalProducts = 0;

        foreach ($stocks as $stock) {
            $totalQuantity += $stock->quantity;
            if ($stock->quantity > 0) {
                $totalProducts++;
            }
        }

        return [
            'total_products' => $totalProducts,
            'total_quantity' => $totalQuantity,
            'stock_items'    => $stocks->count(),
        ];
    }

    /**
     * 检查仓库编码唯一性
     *
     * @param string $code 仓库编码
     * @param int $excludeId 排除的仓库ID（编辑时用）
     * @return bool
     */
    public function isCodeUnique(string $code, int $excludeId = 0): bool
    {
        $query = Warehouse::where('code', $code);
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }
        return $query->find() ? false : true;
    }

    /**
     * 获取启用的仓库列表（用于下拉选择器）
     *
     * @return array
     */
    public function getEnabledList(): array
    {
        return Warehouse::where('status', 1)
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->field('id, name, code')
            ->select()
            ->toArray();
    }
}
