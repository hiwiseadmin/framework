<?php
/**
 * 调拨服务
 *
 * 职责：
 * - 调拨单创建
 * - 仓库校验
 * - 双仓原子操作（调出减 + 调入加）
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\service;

use plugin\smartstock\model\Transfer;
use plugin\smartstock\model\TransferItem;
use plugin\smartstock\model\Warehouse;
use think\facade\Db;

class TransferService
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
     * 创建调拨单 + 明细
     *
     * @param array $data  主单据数据
     * @param array $items 明细列表
     * @return array
     * @throws \Exception
     */
    public function create(array $data, array $items): array
    {
        // 校验仓库
        $this->validateWarehouses($data['from_warehouse_id'], $data['to_warehouse_id']);

        Db::startTrans();
        try {
            $transfer = new Transfer();
            $transfer->save($data);

            $totalQuantity = 0;

            foreach ($items as $item) {
                $itemData = [
                    'transfer_id'  => $transfer->id,
                    'product_id'   => $item['product_id'],
                    'quantity'     => $item['quantity'],
                    'remark'       => $item['remark'] ?? '',
                ];

                $transferItem = new TransferItem();
                $transferItem->save($itemData);

                $totalQuantity += $item['quantity'];
            }

            $transfer->total_quantity = $totalQuantity;
            $transfer->save();

            Db::commit();

            return [
                'id'       => $transfer->id,
                'order_no' => $transfer->order_no,
            ];
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 校验仓库有效性
     *
     * @param int $fromId 调出仓库ID
     * @param int $toId   调入仓库ID
     * @return bool
     * @throws \Exception
     */
    public function validateWarehouses(int $fromId, int $toId): bool
    {
        if ($fromId === $toId) {
            throw new \Exception('调出仓库和调入仓库不能相同');
        }

        $fromWarehouse = Warehouse::where('status', 1)->find($fromId);
        if (!$fromWarehouse) {
            throw new \Exception('调出仓库不存在或已禁用');
        }

        $toWarehouse = Warehouse::where('status', 1)->find($toId);
        if (!$toWarehouse) {
            throw new \Exception('调入仓库不存在或已禁用');
        }

        return true;
    }

    /**
     * 执行调拨：双仓原子操作（同一事务）
     *
     * @param int $transferId 调拨单ID
     * @return void
     * @throws \Exception
     */
    public function execute(int $transferId): void
    {
        $transfer = Transfer::with(['items'])->find($transferId);
        if (!$transfer) {
            throw new \Exception('调拨单不存在');
        }

        $fromId = $transfer->from_warehouse_id;
        $toId   = $transfer->to_warehouse_id;

        // 再次校验仓库有效性
        $this->validateWarehouses($fromId, $toId);

        Db::startTrans();
        try {
            foreach ($transfer->items as $item) {
                $productId = $item->product_id;
                $quantity  = $item->quantity;

                // 调出仓扣减
                $this->stockService->subtractStock($productId, $fromId, $quantity, [
                    'referenceType' => 'transfer',
                    'referenceNo'   => $transfer->order_no,
                    'remark'        => '调拨出库',
                ]);

                // 调入仓增加
                $this->stockService->addStock($productId, $toId, $quantity, [
                    'referenceType' => 'transfer',
                    'referenceNo'   => $transfer->order_no,
                    'remark'        => '调拨入库',
                ]);
            }

            // 更新调拨单冗余状态
            $transfer->status = 3;
            $transfer->save();

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }
}
