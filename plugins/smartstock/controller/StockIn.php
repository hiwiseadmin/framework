<?php
/**
 * 入库单控制器
 *
 * 提供入库单的 CRUD + 工作流操作
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\controller;


use wise\support\context\ContextualLogger;
use plugin\smartstock\model\StockIn as StockInModel;
use plugin\smartstock\model\StockInItem;
use plugin\smartstock\model\Warehouse;
use plugin\smartstock\validate\StockInValidate;
use plugin\smartstock\workflow\SmartStockWorkflow;
use wise\workflow\facade\Workflow;
use think\facade\Db;
class StockIn extends Base
{
    const SUBJECT_PREFIX = 'smartstock_stock_in';

    /**
     * 入库单列表页
     */
    public function index()
    {
        $warehouses = Warehouse::where('status', 1)->order('sort_order', 'asc')->select();
        return $this->assign([
            'warehouses' => $warehouses,
            'types'      => config('smartstock.app.stock_in_types', []),
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

        $query = (new StockInModel())->search($params)->with(['warehouse']);

        $list = $query->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['warehouse_name'] = $item->warehouse ? $item->warehouse->name : '';
            $row['type_text']      = $item->type_text;
            $row['status_text']    = $item->status_text;
            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    /**
     * 新增/编辑入库单
     */
    public function save()
    {
        $data = $this->request->post();

        $id = $data['id'] ?? 0;

        // 编辑时检查状态
        if ($id > 0) {
            $record = StockInModel::find($id);
            if (!$record) {
                return $this->error('入库单不存在');
            }
            if ($record->status != 0) {
                return $this->error('仅草稿状态的单据可编辑');
            }
        }

        // 验证主数据
        $validate = new StockInValidate();
        if (!$validate->scene('save')->check($data)) {
            return $this->error($validate->getError());
        }

        // 验证明细
        $items = $data['items'] ?? [];
        if (empty($items)) {
            return $this->error('请至少添加一条入库明细');
        }

        Db::startTrans();
        try {
            if ($id > 0) {
                // 编辑：更新主表 + 重建明细
                $stockIn = StockInModel::find($id);

                // 更新主表
                $updateData = [
                    'warehouse_id' => $data['warehouse_id'],
                    'type'         => $data['type'],
                    'order_date'   => $data['order_date'] ?? date('Y-m-d'),
                    'remark'       => $data['remark'] ?? '',
                ];
                $stockIn->save($updateData);

                // 重建明细
                StockInItem::where('stock_in_id', $id)->delete();
            } else {
                // 新增
                $orderNo = smartstock_order_no('IN');
                $stockIn = new StockInModel();
                $stockIn->save([
                    'order_no'     => $orderNo,
                    'warehouse_id' => $data['warehouse_id'],
                    'type'         => $data['type'],
                    'order_date'   => $data['order_date'] ?? date('Y-m-d'),
                    'remark'       => $data['remark'] ?? '',
                    'status'       => 0,
                ]);

                // 初始化工作流
                Workflow::init(SmartStockWorkflow::stockIn(), $this->getSubject($stockIn->id));
            }

            // 批量创建明细
            $totalQuantity = 0;
            $totalAmount   = 0;
            foreach ($items as $item) {
                if (empty($item['product_id']) || empty($item['quantity'])) {
                    continue;
                }
                $amount = ($item['quantity'] * ($item['price'] ?? 0));
                $itemModel = new StockInItem();
                $itemModel->save([
                    'stock_in_id' => $stockIn->id,
                    'product_id'  => $item['product_id'],
                    'quantity'    => $item['quantity'],
                    'price'       => $item['price'] ?? 0,
                    'amount'      => $amount,
                    'remark'      => $item['remark'] ?? '',
                ]);
                $totalQuantity += $item['quantity'];
                $totalAmount   += $amount;
            }

            // 更新汇总
            $stockIn->total_quantity = $totalQuantity;
            $stockIn->total_amount   = $totalAmount;
            $stockIn->save();

            Db::commit();
            return $this->success('保存成功', ['id' => $stockIn->id]);
        } catch (\Exception $e) {
            Db::rollback();
            ContextualLogger::error('StockIn save error: ' . $e->getMessage());
            return $this->error('保存失败，请稍后重试');
        }
    }

    /**
     * 入库单详情页
     */
    public function detail()
    {
        $id     = $this->request->param('id/d', 0);
        $record = StockInModel::with(['items', 'warehouse'])->find($id);
        if (!$record) {
            return $this->error('入库单不存在');
        }
        return $this->fetch('stock_in/detail', ['record' => $record]);
    }

    /**
     * 获取可用转换列表
     */
    public function getTransitions()
    {
        $id = $this->request->param('id/d', 0);
        $subject = $this->getSubject($id);
        $transitions = Workflow::getEnabledTransitions(SmartStockWorkflow::stockIn(), $subject);

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
            Workflow::apply(SmartStockWorkflow::stockIn(), $subject, $transition);
            return $this->success('操作成功');
        } catch (\Exception $e) {
            ContextualLogger::error('StockIn applyTransition error: ' . $e->getMessage());
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
