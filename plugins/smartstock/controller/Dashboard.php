<?php
/**
 * 库存概览仪表盘控制器
 *
 * 展示库存核心指标：商品总数、仓库数、库存总量、预警数、今日出入库、近7天趋势。
 *
 * @author 齐活林 (Qi) · 交付总监
 * @date   2026-06-19
 */

namespace plugin\smartstock\controller;


use wise\support\context\ContextualLogger;
use plugin\smartstock\model\Product;
use plugin\smartstock\model\Warehouse;
use plugin\smartstock\model\Stock;
use plugin\smartstock\model\StockIn;
use plugin\smartstock\model\StockOut;
use plugin\smartstock\model\AlertLog;
use think\facade\Db;

class Dashboard extends Base
{

    /**
     * 库存概览页
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 获取仪表盘统计数据
     *
     * @return \think\response\Json
     */
    public function getStats()
    {
        try {
            // 商品总数
            $totalProducts = Product::count();

            // 仓库数量
            $totalWarehouses = Warehouse::where('status', 1)->count();

            // 库存总量
            $totalStock = (int) Stock::sum('quantity');

            // 预警数量（未读预警日志）
            $alertCount = AlertLog::where('is_read', 0)->count();

            // 今日入库笔数
            $todayInCount = StockIn::where('status', 2)
                ->where('create_time', '>=', date('Y-m-d 00:00:00'))
                ->count();

            // 今日出库笔数
            $todayOutCount = StockOut::where('status', 2)
                ->where('create_time', '>=', date('Y-m-d 00:00:00'))
                ->count();

            // 今日入库数量汇总
            $todayInQuantity = (int) StockIn::where('status', 2)
                ->where('create_time', '>=', date('Y-m-d 00:00:00'))
                ->sum('total_quantity');

            // 今日出库数量汇总
            $todayOutQuantity = (int) StockOut::where('status', 2)
                ->where('create_time', '>=', date('Y-m-d 00:00:00'))
                ->sum('total_quantity');

            // 低库存预警（使用插件配置阈值）
            $alertMinQuantity = $this->getPluginConfig('alert_min_quantity', 10);
            $lowStockCount = Stock::where('quantity', '<', $alertMinQuantity)->count();

            // 近7天趋势（按天汇总出入库数量）
            $trend = $this->getRecentTrend();

            // 仓库库存分布
            $warehouseDistribution = $this->getWarehouseDistribution();

            return $this->success('success', [
                'total_products'    => $totalProducts,
                'total_warehouses'  => $totalWarehouses,
                'total_stock'       => $totalStock,
                'alert_count'       => $alertCount,
                'today_in_count'    => $todayInCount,
                'today_out_count'   => $todayOutCount,
                'today_in_quantity'  => $todayInQuantity,
                'today_out_quantity' => $todayOutQuantity,
                'low_stock_count'   => $lowStockCount,
                'trend'             => $trend,
                'warehouse_distribution' => $warehouseDistribution,
            ]);
        } catch (\Exception $e) {
            ContextualLogger::error('Dashboard getStats error: ' . $e->getMessage());
            return $this->error('获取数据失败，请稍后重试', [], 500);
        }
    }

    /**
     * 近7天出入库趋势
     *
     * @return array
     */
    protected function getRecentTrend(): array
    {
        $dateStart = date('Y-m-d', strtotime('-6 days')) . ' 00:00:00';
        $dateEnd   = date('Y-m-d') . ' 23:59:59';

        // 一次性查询7天入库数据（按天分组）
        $inStats = StockIn::where('status', 2)
            ->whereBetween('create_time', [$dateStart, $dateEnd])
            ->field([
                'DATE(create_time) as stat_date',
                'COUNT(*) as in_count',
                'SUM(total_quantity) as in_quantity',
            ])
            ->group('stat_date')
            ->select()
            ->toArray();

        // 一次性查询7天出库数据（按天分组）
        $outStats = StockOut::where('status', 2)
            ->whereBetween('create_time', [$dateStart, $dateEnd])
            ->field([
                'DATE(create_time) as stat_date',
                'COUNT(*) as out_count',
                'SUM(total_quantity) as out_quantity',
            ])
            ->group('stat_date')
            ->select()
            ->toArray();

        // 按日期建立索引
        $inMap  = array_column($inStats, null, 'stat_date');
        $outMap = array_column($outStats, null, 'stat_date');

        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));

            $trend[] = [
                'date'         => date('m-d', strtotime($date)),
                'in_count'     => (int) ($inMap[$date]['in_count'] ?? 0),
                'in_quantity'  => (int) ($inMap[$date]['in_quantity'] ?? 0),
                'out_count'    => (int) ($outMap[$date]['out_count'] ?? 0),
                'out_quantity' => (int) ($outMap[$date]['out_quantity'] ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * 仓库库存分布（饼图数据）
     *
     * @return array
     */
    protected function getWarehouseDistribution(): array
    {
        // 聚合查询直接用 JOIN 取仓库名，避免 with() 预载入依赖主键 id 而失效
        $rows = Stock::alias('s')
            ->join(Warehouse::getTable() . ' w', 's.warehouse_id = w.id')
            ->field('s.warehouse_id, w.name AS warehouse_name, SUM(s.quantity) AS quantity')
            ->group('s.warehouse_id')
            ->select()
            ->toArray();

        return array_map(function ($item) {
            return [
                'name'     => $item['warehouse_name'] ?? "仓库#{$item['warehouse_id']}",
                'quantity' => (int) $item['quantity'],
            ];
        }, $rows);
    }
}
