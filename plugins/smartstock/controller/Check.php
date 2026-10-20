<?php
/**
 * 盘点单控制器
 *
 * 提供盘点单的 CRUD + 工作流操作 + 提交实盘数据
 *
 * @author 寇豆码 (engineer)
 * @date   2026-06-19
 */

namespace plugin\smartstock\controller;


use wise\support\context\ContextualLogger;
use plugin\smartstock\model\Check as CheckModel;
use plugin\smartstock\model\CheckItem;
use plugin\smartstock\model\Warehouse;
use plugin\smartstock\model\Stock;
use plugin\smartstock\validate\CheckValidate;
use plugin\smartstock\service\CheckService;
use plugin\smartstock\workflow\SmartStockWorkflow;
use wise\workflow\facade\Workflow;

class Check extends Base
{
    const SUBJECT_PREFIX = 'smartstock_check';

    /**
     * 盘点单列表页
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

        $query = (new CheckModel())->search($params)->with(['warehouse']);

        $list = $query->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['warehouse_name'] = $item->warehouse ? $item->warehouse->name : '';
            $row['status_text']    = $item->status_text;
            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    /**
     * 创建盘点计划（快照系统库存）
     */
    public function save()
    {
        $data = $this->request->post();

        $validate = new CheckValidate();
        if (!$validate->scene('save')->check($data)) {
            return $this->error($validate->getError());
        }

        try {
            // 生成盘点单号
            $orderNo = smartstock_order_no('CK');
            $data['order_no'] = $orderNo;

            $checkService = new CheckService();
            $result = $checkService->createPlan($data);

            // 初始化工作流（wise-workflow 引擎）
            Workflow::init(SmartStockWorkflow::check(), $this->getSubject($result['id']));

            return $this->success('盘点计划创建成功', $result);
        } catch (\Exception $e) {
            ContextualLogger::error('Check save error: ' . $e->getMessage());
            return $this->error('创建失败，请稍后重试');
        }
    }

    /**
     * 盘点单详情页
     */
    public function detail()
    {
        $id     = $this->request->param('id/d', 0);
        $record = CheckModel::with(['items.product', 'warehouse'])->find($id);
        if (!$record) {
            return $this->error('盘点单不存在');
        }
        $items = $record->items ?? [];
        return $this->assign([
            'order' => $record,
            'items' => $items,
        ])->fetch();
    }

    /**
     * 执行盘点（录入实盘数量）
     */
    public function execute()
    {
        $id     = $this->request->param('id/d', 0);
        $record = CheckModel::with(['items.product', 'warehouse'])->find($id);
        if (!$record) {
            return $this->error('盘点单不存在');
        }
        $items = $record->items ?? [];
        return $this->assign([
            'order' => $record,
            'items' => $items,
        ])->fetch();
    }

    /**
     * 提交盘点实盘数据
     */
    public function submitResult()
    {
        $id    = $this->request->param('id/d', 0);
        $items = $this->request->post('items/a', []);

        if (empty($items)) {
            return $this->error('请提交实盘数据');
        }

        try {
            $checkService = new CheckService();
            $checkService->submitResult($id, $items);

            return $this->success('实盘数据提交成功');
        } catch (\Exception $e) {
            ContextualLogger::error('Check submitResult error: ' . $e->getMessage());
            return $this->error('提交失败，请稍后重试');
        }
    }

    /**
     * 获取可用转换列表
     */
    public function getTransitions()
    {
        $id      = $this->request->param('id/d', 0);
        $subject = $this->getSubject($id);
        $transitions = Workflow::getEnabledTransitions(SmartStockWorkflow::check(), $subject);

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
            Workflow::apply(SmartStockWorkflow::check(), $subject, $transition);
            return $this->success('操作成功');
        } catch (\Exception $e) {
            ContextualLogger::error('Check applyTransition error: ' . $e->getMessage());
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
