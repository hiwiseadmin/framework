<?php
namespace plugin\smartstock\controller\api;

use plugin\ApiController;
use plugin\smartstock\model\Product;
use plugin\smartstock\model\Warehouse;
use plugin\smartstock\model\Stock;

/**
 * SmartStock 仪表盘 API 控制器
 *
 * GET /api/smartstock/dashboard/stats — 仪表盘数据
 *
 * @author 齐活林 (Qi)
 * @date   2026-06-19
 */
class Dashboard extends ApiController
{
    /**
     * 中间件：JWT 认证
     *
     * @var array
     */
    protected $middleware = [
        \wise\member\middleware\JwtAuth::class,
    ];

    /**
     * 仪表盘核心指标（供 API 调用）
     *
     * GET /api/smartstock/dashboard/stats
     */
    public function stats()
    {
        $totalProducts   = Product::where('status', 1)->count();
        $totalWarehouses = Warehouse::where('status', 1)->count();
        $totalStock      = Stock::where('quantity', '>', 0)->sum('quantity') ?? 0;
        $lowStockCount   = Stock::where('quantity', '>', 0)
            ->where('quantity', '<=', 10)
            ->count();

        return $this->success('ok', [
            'total_products'   => $totalProducts,
            'total_warehouses' => $totalWarehouses,
            'total_stock'      => (int)$totalStock,
            'low_stock_count'  => $lowStockCount,
        ]);
    }
}
