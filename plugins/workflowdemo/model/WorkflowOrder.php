<?php
namespace plugin\workflowdemo\model;

use plugin\BaseModel;

class WorkflowOrder extends BaseModel
{
    /**
     * 模型名称（对应数据库表名，不含前缀）
     */
    protected $name = 'workflow_orders';

    /**
     * 自动写入时间戳
     */
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 获取工作流主体标识（用于工作流引擎）
     * 工作流引擎需要以此作为主体标识来存储状态
     */
    public function getWorkflowSubjectId()
    {
        return $this->id;
    }

    /**
     * 获取工作流主体类型
     */
    public function getWorkflowSubjectType()
    {
        return 'workflow_order';
    }

    /**
     * 状态获取器（将英文状态转为中文）
     */
    public function getStatusTextAttr($value, $data)
    {
        $statusMap = [
            'draft'      => '草稿',
            'submitted'  => '已提交',
            'reviewing'  => '审核中',
            'approved'   => '已通过',
            'rejected'   => '已拒绝',
            'cancelled'  => '已取消',
        ];

        return $statusMap[$data['status']] ?? $data['status'];
    }

    /**
     * 金额获取器（格式化显示）
     */
    public function getAmountTextAttr($value, $data)
    {
        return number_format($data['amount'], 2);
    }
}
