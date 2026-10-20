<?php
/**
 * 盘点服务
 *
 * 职责：
 * - 创建盘点计划（快照系统库存）
 * - 提交实盘数据
 * - 差异处理
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\service;

use plugin\smartstock\model\Check;
use plugin\smartstock\model\CheckItem;
use plugin\smartstock\model\Stock;
use think\facade\Db;

class CheckService
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
     * 创建盘点计划：快照指定仓库的系统库存
     *
     * @param array $data 盘点主数据（含 warehouse_id）
     * @return array
     * @throws \Exception
     */
    public function createPlan(array $data): array
    {
        $warehouseId = $data['warehouse_id'];

        Db::startTrans();
        try {
            // 创建盘点单
            $check = new Check();
            $check->save($data);

            // 快照系统库存
            $stocks = Stock::where('warehouse_id', $warehouseId)
                ->where('quantity', '>', 0)
                ->select();

            foreach ($stocks as $stock) {
                $checkItem = new CheckItem();
                $checkItem->save([
                    'check_id'        => $check->id,
                    'product_id'      => $stock->product_id,
                    'system_quantity' => $stock->quantity,
                    'actual_quantity' => 0,
                ]);
            }

            Db::commit();

            return [
                'id'       => $check->id,
                'order_no' => $check->order_no,
            ];
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 提交盘点明细（实盘数量）
     *
     * @param int   $checkId 盘点单ID
     * @param array $items   明细 [['product_id' => 1, 'actual_quantity' => 99], ...]
     * @return void
     * @throws \Exception
     */
    public function submitResult(int $checkId, array $items): void
    {
        Db::startTrans();
        try {
            foreach ($items as $item) {
                $productId      = $item['product_id'];
                $actualQuantity = $item['actual_quantity'];

                $checkItem = CheckItem::where([
                    'check_id'   => $checkId,
                    'product_id' => $productId,
                ])->find();

                if ($checkItem) {
                    $checkItem->actual_quantity = $actualQuantity;
                    $checkItem->diff_quantity   = $actualQuantity - $checkItem->system_quantity;
                    $checkItem->save();
                } else {
                    // 新增盘点明细（系统库存为0，但有实盘）
                    $newItem = new CheckItem();
                    $newItem->save([
                        'check_id'        => $checkId,
                        'product_id'      => $productId,
                        'system_quantity' => 0,
                        'actual_quantity' => $actualQuantity,
                        'diff_quantity'   => $actualQuantity,
                    ]);
                }
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 处理盘点差异：修正库存
     *
     * @param int $checkId 盘点单ID
     * @return void
     * @throws \Exception
     */
    public function handleDiff(int $checkId): void
    {
        $check = Check::find($checkId);
        if (!$check) {
            throw new \Exception('盘点单不存在');
        }

        $items = CheckItem::where('check_id', $checkId)->select();

        Db::startTrans();
        try {
            foreach ($items as $item) {
                if ($item->diff_quantity != 0) {
                    $this->stockService->updateStock(
                        $item->product_id,
                        $check->warehouse_id,
                        $item->actual_quantity,
                        [
                            'referenceType' => 'check',
                            'referenceNo'   => $check->order_no,
                            'remark'        => "盘点调整，差异: {$item->diff_quantity}",
                        ]
                    );
                }
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }
}
