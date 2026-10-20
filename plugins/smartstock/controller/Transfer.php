<?php
/**
 * 调拨单控制器
 *
 * 提供调拨单的 CRUD + 工作流操作
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\controller;


use wise\support\context\ContextualLogger;
use plugin\smartstock\model\Transfer as TransferModel;
use plugin\smartstock\model\TransferItem;
use plugin\smartstock\model\Warehouse;
use plugin\smartstock\validate\TransferValidate;
use plugin\smartstock\workflow\SmartStockWorkflow;
use wise\workflow\facade\Workflow;
use think\facade\Db;
class Transfer extends Base
{
    const SUBJECT_PREFIX = 'smartstock_transfer';

    /**
     * 调拨单列表页
     */
    public function index()
    {
        $warehouses = Warehouse::where('status', 1)->order('sort_order', 'asc')->select();
        return $this->assign([
            'warehouses' => $warehouses,
        ])->fetch();
    }

    /**
     * Bootstrap Table 分页数据
     */
    public function getList()
    {
        $params   = $this->buildQueryParams();
        $page     = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 20);

        $query = (new TransferModel())->search($params)->with(['fromWarehouse', 'toWarehouse']);

        $list = $query->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['from_warehouse_name'] = $item->fromWarehouse ? $item->fromWarehouse->name : '';
            $row['to_warehouse_name']   = $item->toWarehouse ? $item->toWarehouse->name : '';
            $row['status_text']         = $item->status_text;
            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    /**
     * 新增/编辑调拨单
     */
    public function save()
    {
        $data = $this->request->post();

        $id = $data['id'] ?? 0;

        if ($id > 0) {
            $record = TransferModel::find($id);
            if (!$record) {
                return $this->error('调拨单不存在');
            }
            if ($record->status != 0) {
                return $this->error('仅草稿状态的单据可编辑');
            }
        }

        $validate = new TransferValidate();
        if (!$validate->scene('save')->check($data)) {
            return $this->error($validate->getError());
        }

        // 校验仓库
        if ($data['from_warehouse_id'] == $data['to_warehouse_id']) {
            return $this->error('调出仓库和调入仓库不能相同');
        }

        $fromWarehouse = Warehouse::where('status', 1)->find($data['from_warehouse_id']);
        if (!$fromWarehouse) {
            return $this->error('调出仓库不存在或已禁用');
        }

        $toWarehouse = Warehouse::where('status', 1)->find($data['to_warehouse_id']);
        if (!$toWarehouse) {
            return $this->error('调入仓库不存在或已禁用');
        }

        $items = $data['items'] ?? [];
        if (empty($items)) {
            return $this->error('请至少添加一条调拨明细');
        }

        Db::startTrans();
        try {
            if ($id > 0) {
                $transfer = TransferModel::find($id);
                $transfer->save([
                    'from_warehouse_id' => $data['from_warehouse_id'],
                    'to_warehouse_id'   => $data['to_warehouse_id'],
                    'order_date'        => $data['order_date'] ?? date('Y-m-d'),
                    'remark'            => $data['remark'] ?? '',
                ]);
                TransferItem::where('transfer_id', $id)->delete();
            } else {
                $orderNo  = smartstock_order_no('TF');
                $transfer = new TransferModel();
                $transfer->save([
                    'order_no'          => $orderNo,
                    'from_warehouse_id' => $data['from_warehouse_id'],
                    'to_warehouse_id'   => $data['to_warehouse_id'],
                    'order_date'        => $data['order_date'] ?? date('Y-m-d'),
                    'remark'            => $data['remark'] ?? '',
                    'status'            => 0,
                ]);

                Workflow::init(SmartStockWorkflow::transfer(), $this->getSubject($transfer->id));
            }

            $totalQuantity = 0;
            foreach ($items as $item) {
                if (empty($item['product_id']) || empty($item['quantity'])) {
                    continue;
                }
                $itemModel = new TransferItem();
                $itemModel->save([
                    'transfer_id' => $transfer->id,
                    'product_id'  => $item['product_id'],
                    'quantity'    => $item['quantity'],
                    'remark'      => $item['remark'] ?? '',
                ]);
                $totalQuantity += $item['quantity'];
            }

            $transfer->total_quantity = $totalQuantity;
            $transfer->save();

            Db::commit();
            return $this->success('保存成功', ['id' => $transfer->id]);
        } catch (\Exception $e) {
            Db::rollback();
            ContextualLogger::error('Transfer save error: ' . $e->getMessage());
            return $this->error('保存失败，请稍后重试');
        }
    }

    /**
     * 调拨单详情页
     */
    public function detail()
    {
        $id     = $this->request->param('id/d', 0);
        $record = TransferModel::with(['items', 'fromWarehouse', 'toWarehouse'])->find($id);
        if (!$record) {
            return $this->error('调拨单不存在');
        }
        return $this->assign([
            'record' => $record
        ])->fetch();
    }

    /**
     * 获取可用转换列表
     */
    public function getTransitions()
    {
        $id      = $this->request->param('id/d', 0);
        $subject = $this->getSubject($id);
        $transitions = Workflow::getEnabledTransitions(SmartStockWorkflow::transfer(), $subject);

        $result = [];
        foreach ($transitions as $transition) {
            $result[] = [
                'name'  => $transition->getName(),
                'label' => $transition->getLabel() ?: $transition->getName(),
            ];
        }

        return $this->success('', $result);
    }

    /**
     * 获取审批历史
     */
    public function getHistory()
    {
        $id      = $this->request->param('id/d', 0);
        $subject = $this->getSubject($id);
        $history = Workflow::getHistory($subject);

        return $this->success('', $history);
    }

    /**
     * 执行状态转换
     */
    public function applyTransition()
    {
        $id         = $this->request->param('id/d', 0);
        $transition = $this->request->param('transition', '');
        $subject    = $this->getSubject($id);

        if (empty($transition)) {
            return $this->error('请指定操作');
        }

        try {
            Workflow::apply(SmartStockWorkflow::transfer(), $subject, $transition);
            return $this->success('操作成功');
        } catch (\Exception $e) {
            ContextualLogger::error('Transfer applyTransition error: ' . $e->getMessage());
            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 生成工作流主体标识
     */
    protected function getSubject($id): string
    {
        return self::SUBJECT_PREFIX . ":{$id}";
    }
}
