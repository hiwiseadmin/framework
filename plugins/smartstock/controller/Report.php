<?php
/**
 * 数据报表控制器
 *
 * 提供出入库统计和周转率分析两大报表。
 *
 * @author 齐活林 (Qi) · 交付总监
 * @date   2026-06-19
 */

namespace plugin\smartstock\controller;


use wise\support\context\ContextualLogger;
use plugin\smartstock\model\Product;
use plugin\smartstock\model\StockIn;
use plugin\smartstock\model\StockInItem;
use plugin\smartstock\model\StockOut;
use plugin\smartstock\model\StockOutItem;
use plugin\smartstock\model\Stock;
use plugin\smartstock\model\Warehouse;
use think\facade\Db;
class Report extends Base
{

    /**
     * 出入库统计页
     */
    public function inOut()
    {
        $warehouses = Warehouse::where('status', 1)->order('sort_order', 'asc')->select();
        return $this->assign([
            'warehouses' => $warehouses,
        ])->fetch();
    }

    /**
     * 出入库统计数据（按日汇总）
     *
     * @return \think\response\Json
     */
    public function getInOutData()
    {
        $page        = $this->request->param('pageNumber/d', 1);
        $pageSize    = $this->request->param('pageSize/d', 20);
        $dateStart   = $this->request->param('date_start', date('Y-m-01'));
        $dateEnd     = $this->request->param('date_end', date('Y-m-d'));
        $warehouseId = $this->request->param('warehouse_id/d', 0);

        try {
            // 入库统计（按日汇总）
            $inQuery = StockIn::where('status', 2)
                ->whereBetween('create_time', [$dateStart . ' 00:00:00', $dateEnd . ' 23:59:59']);
            if ($warehouseId > 0) {
                $inQuery->where('warehouse_id', $warehouseId);
            }
            $inStats = $inQuery->field([
                'DATE(create_time) as stat_date',
                'COUNT(*) as in_count',
                'SUM(total_quantity) as in_quantity',
                'SUM(total_amount) as in_amount',
            ])->group('stat_date')->order('stat_date', 'desc')->select()->toArray();

            // 出库统计（按日汇总）
            $outQuery = StockOut::where('status', 2)
                ->whereBetween('create_time', [$dateStart . ' 00:00:00', $dateEnd . ' 23:59:59']);
            if ($warehouseId > 0) {
                $outQuery->where('warehouse_id', $warehouseId);
            }
            $outStats = $outQuery->field([
                'DATE(create_time) as stat_date',
                'COUNT(*) as out_count',
                'SUM(total_quantity) as out_quantity',
                'SUM(total_amount) as out_amount',
            ])->group('stat_date')->order('stat_date', 'desc')->select()->toArray();

            // 以日期为 key 合并
            $merged = [];
            foreach ($inStats as $row) {
                $merged[$row['stat_date']] = [
                    'stat_date'   => $row['stat_date'],
                    'in_count'    => (int) $row['in_count'],
                    'in_quantity' => (int) $row['in_quantity'],
                    'in_amount'   => round((float) $row['in_amount'], 2),
                    'out_count'   => 0,
                    'out_quantity' => 0,
                    'out_amount'  => 0,
                ];
            }
            foreach ($outStats as $row) {
                $date = $row['stat_date'];
                if (isset($merged[$date])) {
                    $merged[$date]['out_count']     = (int) $row['out_count'];
                    $merged[$date]['out_quantity']  = (int) $row['out_quantity'];
                    $merged[$date]['out_amount']    = round((float) $row['out_amount'], 2);
                } else {
                    $merged[$date] = [
                        'stat_date'    => $date,
                        'in_count'     => 0,
                        'in_quantity'  => 0,
                        'in_amount'    => 0,
                        'out_count'    => (int) $row['out_count'],
                        'out_quantity' => (int) $row['out_quantity'],
                        'out_amount'   => round((float) $row['out_amount'], 2),
                    ];
                }
            }

            // 按日期倒序转数组
            krsort($merged);
            $rows = array_values($merged);

            // 汇总行
            $summary = [
                'stat_date'    => '合计',
                'in_count'     => array_sum(array_column($rows, 'in_count')),
                'in_quantity'  => array_sum(array_column($rows, 'in_quantity')),
                'in_amount'    => round(array_sum(array_column($rows, 'in_amount')), 2),
                'out_count'    => array_sum(array_column($rows, 'out_count')),
                'out_quantity' => array_sum(array_column($rows, 'out_quantity')),
                'out_amount'   => round(array_sum(array_column($rows, 'out_amount')), 2),
            ];

            return $this->success('ok', [
                'total'   => count($rows),
                'rows'    => $rows,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            ContextualLogger::error('Report getInOutData error: ' . $e->getMessage());
            return $this->error('获取数据失败，请稍后重试', [], 500);
        }
    }

    /**
     * 周转率分析页
     */
    public function turnover()
    {
        $warehouses = Warehouse::where('status', 1)->order('sort_order', 'asc')->select();
        return $this->assign([
            'warehouses' => $warehouses,
        ])->fetch();
    }

    /**
     * 周转率分析数据
     *
     * 周转率 = 期间出库数量 / 平均库存
     *
     * @return \think\response\Json
     */
    public function getTurnoverData()
    {
        $page        = $this->request->param('pageNumber/d', 1);
        $pageSize    = $this->request->param('pageSize/d', 20);
        $dateStart   = $this->request->param('date_start', date('Y-m-01'));
        $dateEnd     = $this->request->param('date_end', date('Y-m-d'));
        $warehouseId = $this->request->param('warehouse_id/d', 0);

        try {
            // 按商品+仓库维度统计出入库量
            $stockQuery = Stock::alias('s')
                ->join(Product::getTable() . ' p', 's.product_id = p.id', 'LEFT')
                ->join(Warehouse::getTable() . ' w', 's.warehouse_id = w.id', 'LEFT')
                ->field([
                    's.product_id',
                    's.warehouse_id',
                    'p.name as product_name',
                    'w.name as warehouse_name',
                    's.quantity as current_stock',
                ]);

            if ($warehouseId > 0) {
                $stockQuery->where('s.warehouse_id', $warehouseId);
            }

            // 仅展示有库存的商品
            $stockList = $stockQuery->where('s.quantity', '>', 0)
                ->order('s.quantity', 'desc')
                ->paginate(['list_rows' => min($pageSize, 100), 'page' => $page]);

            $rows = [];
            foreach ($stockList->items() as $stock) {
                // 期间出库总量
                $outQuantity = StockOut::alias('so')
                    ->join(StockOutItem::getTable() . ' soi', 'so.id = soi.stock_out_id')
                    ->where('so.status', 2)
                    ->where('soi.product_id', $stock->product_id)
                    ->whereBetween('so.create_time', [$dateStart . ' 00:00:00', $dateEnd . ' 23:59:59']);
                if ($warehouseId > 0) {
                    $outQuantity->where('so.warehouse_id', $warehouseId);
                }
                $totalOut = (int) $outQuantity->sum('soi.quantity');

                // 期间入库总量
                $inQuantity = StockIn::alias('si')
                    ->join(StockInItem::getTable() . ' sii', 'si.id = sii.stock_in_id')
                    ->where('si.status', 2)
                    ->where('sii.product_id', $stock->product_id)
                    ->whereBetween('si.create_time', [$dateStart . ' 00:00:00', $dateEnd . ' 23:59:59']);
                if ($warehouseId > 0) {
                    $inQuantity->where('si.warehouse_id', $warehouseId);
                }
                $totalIn = (int) $inQuantity->sum('sii.quantity');

                // 平均库存 ≈ (期初 + 期末) / 2，简化计算：取当前库存
                $avgStock = (int) $stock->current_stock;

                // 周转率 = 出库量 / 平均库存
                $turnoverRate = $avgStock > 0 ? round($totalOut / $avgStock, 2) : 0;

                $rows[] = [
                    'product_id'     => $stock->product_id,
                    'product_name'   => $stock->product_name,
                    'warehouse_name' => $stock->warehouse_name,
                    'current_stock'  => (int) $stock->current_stock,
                    'in_quantity'    => $totalIn,
                    'out_quantity'   => $totalOut,
                    'turnover_rate'  => $turnoverRate,
                    'turnover_text'  => $this->formatTurnoverRate($turnoverRate),
                ];
            }

            return $this->success('ok', [
                'total' => $stockList->total(),
                'rows'  => $rows,
            ]);
        } catch (\Exception $e) {
            ContextualLogger::error('Report getTurnoverData error: ' . $e->getMessage());
            return $this->error('获取数据失败，请稍后重试', [], 500);
        }
    }

    /**
     * 格式化周转率显示
     */
    protected function formatTurnoverRate(float $rate): string
    {
        if ($rate >= 2) {
            return '<span class="badge bg-success">高周转(' . $rate . ')</span>';
        } elseif ($rate >= 1) {
            return '<span class="badge bg-warning text-dark">适中(' . $rate . ')</span>';
        } elseif ($rate > 0) {
            return '<span class="badge bg-danger">低周转(' . $rate . ')</span>';
        }
        return '<span class="badge bg-secondary">无出库</span>';
    }
}
