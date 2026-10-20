<?php
namespace plugin\smartstock\controller;


use wise\support\context\ContextualLogger;
use plugin\smartstock\model\AlertRule;
use plugin\smartstock\model\AlertLog;
use plugin\smartstock\model\Product;
use plugin\smartstock\model\Warehouse;
use plugin\smartstock\validate\AlertValidate;
use plugin\smartstock\service\AlertService;
/**
 * 预警管理控制器
 *
 * 规则管理：ruleIndex / ruleForm / getRuleList / saveRule / deleteRule / toggleRule / getRuleDetail
 * 日志管理：logIndex / getLogList / markRead / batchRead / getUnreadCount
 */
class Alert extends Base
{
    /**
     * 预警规则列表页
     */
    public function ruleIndex()
    {
        return $this->fetch();
    }

    /**
     * 预警规则表单页
     */
    public function ruleForm()
    {
        $id  = $this->request->param('id/d', 0);
        $row = [];
        if ($id > 0) {
            $row = AlertRule::find($id);
            if ($row) {
                $row = $row->toArray();
            }
        }
        $products = Product::where('status', 1)
            ->order('name', 'asc')
            ->field('id, name, sku_code')
            ->select()
            ->toArray();
        $warehouses = Warehouse::where('status', 1)
            ->order('name', 'asc')
            ->field('id, name')
            ->select()
            ->toArray();

        return $this->assign([
            'row'        => $row,
            'id'         => $id,
            'products'   => $products,
            'warehouses' => $warehouses,
        ])->fetch();
    }

    /**
     * 规则列表分页数据（Bootstrap Table 格式）
     */
    public function getRuleList()
    {
        $params = $this->buildQueryParams();
        $page   = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 10);

        $service = new AlertService();
        $result  = $service->getRuleList([
            'keyword'    => $this->request->param('keyword', ''),
            'alert_type' => $this->request->param('alert_type', ''),
            'status'     => $this->request->param('status', ''),
            'page'       => $page,
            'pageSize'   => $pageSize,
        ]);

        return $this->success('', $result);
    }

    /**
     * 保存规则（新增/编辑）
     */
    public function saveRule()
    {
        $data = $this->request->post();

        // 验证
        $validate = new AlertValidate();
        if (!$validate->check($data)) {
            return $this->error($validate->getError());
        }

        try {
            $service = new AlertService();
            $service->saveRule($data);
            return $this->success('保存成功');
        } catch (\Exception $e) {
            ContextualLogger::error('AlertRule save error: ' . $e->getMessage());
            return $this->error('保存失败，请稍后重试');
        }
    }

    /**
     * 删除规则（软删除）
     */
    public function deleteRule()
    {
        $id = $this->request->post('id/d', 0);
        if (empty($id)) {
            return $this->error('参数错误');
        }

        try {
            $service = new AlertService();
            $service->deleteRule($id);
            return $this->success('删除成功');
        } catch (\Exception $e) {
            ContextualLogger::error('AlertRule delete error: ' . $e->getMessage());
            return $this->error('删除失败，请稍后重试');
        }
    }

    /**
     * 启用/禁用规则
     */
    public function toggleRule()
    {
        $id     = $this->request->post('id/d', 0);
        $status = $this->request->post('status/d', 1);

        if (empty($id)) {
            return $this->error('参数错误');
        }

        try {
            $service = new AlertService();
            $service->toggleRule($id, $status);
            return $this->success('操作成功');
        } catch (\Exception $e) {
            ContextualLogger::error('AlertRule toggle error: ' . $e->getMessage());
            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 获取规则详情
     */
    public function getRuleDetail()
    {
        $id = $this->request->param('id/d', 0);
        if (empty($id)) {
            return $this->error('参数错误');
        }

        $rule = AlertRule::find($id);
        if (!$rule) {
            return $this->error('规则不存在');
        }

        return $this->success('', $rule->toArray());
    }

    /**
     * 预警日志列表页
     */
    public function logIndex()
    {
        return $this->fetch();
    }

    /**
     * 日志列表分页数据（Bootstrap Table 格式）
     */
    public function getLogList()
    {
        $page     = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 10);

        $service = new AlertService();
        $result  = $service->getLogList([
            'alert_type'   => $this->request->param('alert_type', ''),
            'product_id'   => $this->request->param('product_id', ''),
            'warehouse_id' => $this->request->param('warehouse_id', ''),
            'is_read'      => $this->request->param('is_read', ''),
            'date_range'   => $this->request->param('date_range', ''),
            'page'       => $page,
            'pageSize'   => $pageSize,
        ]);

        return $this->success('', $result);
    }

    /**
     * 单条已读
     */
    public function markRead()
    {
        $id = $this->request->post('id/d', 0);
        if (empty($id)) {
            return $this->error('参数错误');
        }

        try {
            $service = new AlertService();
            $service->markRead($id);
            return $this->success('操作成功');
        } catch (\Exception $e) {
            ContextualLogger::error('AlertLog markRead error: ' . $e->getMessage());
            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 批量已读
     */
    public function batchRead()
    {
        $ids = $this->request->post('ids/a', []);
        if (empty($ids)) {
            return $this->error('请选择要操作的日志');
        }

        try {
            $service = new AlertService();
            $count   = $service->batchRead($ids);
            return $this->success("成功标记 {$count} 条为已读");
        } catch (\Exception $e) {
            ContextualLogger::error('AlertLog batchRead error: ' . $e->getMessage());
            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 获取未读数量
     */
    public function getUnreadCount()
    {
        $service = new AlertService();
        $count   = $service->getUnreadCount([
            'product_id'   => $this->request->param('product_id', ''),
            'warehouse_id' => $this->request->param('warehouse_id', ''),
        ]);

        return $this->success('ok', ['count' => $count]);
    }
}
