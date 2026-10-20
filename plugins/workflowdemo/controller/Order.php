<?php
namespace plugin\workflowdemo\controller;

use wise\workflow\facade\Workflow;
use wise\workflow\WorkflowRegistry;
use plugin\workflowdemo\model\WorkflowOrder;
class Order extends Base
{
    protected $noNeedLogin      = [];
    protected $noNeedAuth       = [];
    protected $noNeedSelfAuthId = [];

    /**
     * 订单列表
     */
    public function index()
    {
        $page  = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 15);
        $status = $this->request->param('status', '');

        $query = WorkflowOrder::order('id', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        $list  = $query->paginate(['list_rows' => $limit, 'page' => $page]);
        $places = $this->getWorkflowPlaces();

        return $this->fetch('order/index', [
            'list'   => $list,
            'places' => $places,
            'status' => $status,
        ]);
    }

    /**
     * 创建订单页面
     */
    public function create()
    {
        return $this->fetch('order/form', [
            'order' => null,
        ]);
    }

    /**
     * 保存订单（API）
     */
    public function save()
    {
        $data = $this->request->post();

        if (empty($data['title'])) {
            return $this->error('订单标题不能为空');
        }

        $adminId = session('admin.id') ?? 0;

        $order = WorkflowOrder::create([
            'title'       => $data['title'],
            'description' => $data['description'] ?? '',
            'amount'      => floatval($data['amount'] ?? 0),
            'status'      => 'draft',
            'admin_id'    => $adminId,
        ]);

        // 初始化工作流状态（将订单设为初始状态 draft）
        Workflow::init($this->getWorkflow(), $this->getSubject($order));

        return $this->success('订单创建成功', ['id' => $order->id]);
    }

    /**
     * 订单详情
     */
    public function detail()
    {
        $id = $this->request->param('id/d', 0);

        $order = WorkflowOrder::find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        $workflow = $this->getWorkflow();
        $subject = $this->getSubject($order);

        $currentPlaces = Workflow::getMarking($subject);
        $enabledTransitions = Workflow::getEnabledTransitions($workflow, $subject);
        $history = Workflow::getHistory($subject);

        return $this->fetch('order/detail', [
            'order'               => $order,
            'currentPlaces'       => $currentPlaces,
            'enabledTransitions'  => $enabledTransitions,
            'history'             => $history,
            'placeLabels'         => $this->getWorkflowPlaces(),
            'transitionLabels'    => $this->getTransitionLabels(),
        ]);
    }

    /**
     * 执行工作流转换（API）
     */
    public function transition()
    {
        $id = $this->request->post('id/d', 0);
        $transitionName = $this->request->post('transition', '');

        if (empty($transitionName)) {
            return $this->error('请指定要执行的转换');
        }

        $order = WorkflowOrder::find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        $workflow = $this->getWorkflow();
        $subject  = $this->getSubject($order);

        // 检查是否可执行
        if (!Workflow::can($workflow, $subject, $transitionName)) {
            return $this->error('当前状态不允许执行此操作');
        }

        try {
            // 执行工作流转换
            Workflow::apply($workflow, $subject, $transitionName);

            // 更新订单状态字段（冗余字段，方便查询）
            $marking = Workflow::getMarking($subject);
            // 取第一个状态作为订单的主状态
            $order->status = array_key_first($marking) ?? '';
            $order->save();

            return $this->success('操作成功', [
                'status' => $order->status,
            ]);
        } catch (\Exception $e) {
            return $this->error('操作失败：' . $e->getMessage());
        }
    }

    /**
     * 获取工作流状态定义
     */
    protected function getWorkflowPlaces()
    {
        return [
            'draft'      => '草稿',
            'submitted'  => '已提交',
            'reviewing'  => '审核中',
            'approved'   => '已通过',
            'rejected'   => '已拒绝',
            'cancelled'  => '已取消',
        ];
    }

    /**
     * 获取转换操作标签
     * 
     * 将转换名称映射为人类可读的中文标签
     */
    protected function getTransitionLabels()
    {
        return [
            'submit'    => '提交审核',
            'assign'    => '分配审核员',
            'approve'   => '审核通过',
            'reject'    => '审核拒绝',
            'resubmit'  => '重新提交',
            'cancel'    => '取消订单',
        ];
    }

    /**
     * 获取工作流定义对象
     * 
     * 从 WorkflowRegistry 中获取 'order_approval' 工作流
     * 
     * @return \wise\workflow\Workflow
     */
    protected function getWorkflow()
    {
        return WorkflowRegistry::get('order_approval');
    }

    /**
     * 生成工作流主体标识
     * 
     * 格式：'order_approval:{order_id}'
     * 
     * @param WorkflowOrder $order 订单模型对象
     * @return string 主体标识
     */
    protected function getSubject(WorkflowOrder $order): string
    {
        return "order_approval:{$order->id}";
    }
}
