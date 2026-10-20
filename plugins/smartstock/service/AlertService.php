<?php
namespace plugin\smartstock\service;

use plugin\smartstock\model\AlertRule;
use plugin\smartstock\model\AlertLog;

/**
 * 预警服务
 *
 * 职责：
 * - 预警规则 CRUD
 * - 预警日志管理
 * - 冲突检测
 */
class AlertService
{
    /**
     * 规则列表分页查询
     */
    public function getRuleList(array $params): array
    {
        $page     = $params['page'] ?? 1;
        $pageSize = $params['pageSize'] ?? 10;

        $query = AlertRule::with(['product', 'warehouse']);

        // keyword 筛选：规则名称模糊搜索
        if (!empty($params['keyword'])) {
            $query->whereLike('name', "%{$params['keyword']}%");
        }

        // alert_type 筛选
        if (isset($params['alert_type']) && $params['alert_type'] !== '') {
            $query->where('alert_type', $params['alert_type']);
        }

        // status 筛选
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }

        $list = $query->order('id', 'desc')->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['product_name']   = $item->product ? $item->product->name : '';
            $row['warehouse_name'] = $item->warehouse ? $item->warehouse->name : '';
            $row['status_text']    = $item->status_text;
            $row['alert_type_text'] = $item->alert_type_text;
            $rows[] = $row;
        }

        return [
            'total' => $list->total(),
            'rows'  => $rows,
        ];
    }

    /**
     * 新增/更新规则
     *
     * @param array $data
     * @return AlertRule|null
     * @throws \Exception
     */
    public function saveRule(array $data): ?AlertRule
    {
        $productId   = (int) ($data['product_id'] ?? 0);
        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        $alertType   = (int) ($data['alert_type'] ?? 0);
        if ($productId <= 0 || $warehouseId <= 0 || $alertType <= 0) {
            throw new \Exception('商品、仓库与预警类型不能为空');
        }

        // 字段白名单，防止批量赋值注入 status/id 等字段
        $allowed = ['name', 'product_id', 'warehouse_id', 'alert_type', 'min_quantity', 'max_quantity', 'expiry_days', 'notify_users', 'status'];
        $data = array_intersect_key($data, array_flip($allowed));

        // 冲突检测：同一 product_id+warehouse_id+alert_type 组合仅允许一条启用规则
        $conflictQuery = AlertRule::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('alert_type', $alertType)
            ->where('status', 1);
        if (!empty($data['id'])) {
            $conflictQuery->where('id', '<>', $data['id']);
        }
        $conflict = $conflictQuery->find();
        if ($conflict) {
            throw new \Exception('该商品+仓库+预警类型组合已存在启用规则，请先禁用或修改已有规则');
        }

        if (!empty($data['id'])) {
            $rule = AlertRule::find($data['id']);
            if (!$rule) {
                throw new \Exception('规则不存在');
            }
            $rule->save($data);
        } else {
            $rule = new AlertRule();
            $rule->save($data);
        }

        return $rule;
    }

    /**
     * 删除规则（软删除，设 status=0）
     */
    public function deleteRule(int $id): bool
    {
        $rule = AlertRule::find($id);
        if (!$rule) {
            throw new \Exception('规则不存在');
        }
        $rule->status = 0;
        return $rule->save();
    }

    /**
     * 启用/禁用规则
     */
    public function toggleRule(int $id, int $status): bool
    {
        $rule = AlertRule::find($id);
        if (!$rule) {
            throw new \Exception('规则不存在');
        }

        // 启用时进行冲突检测
        if ($status == 1) {
            $conflict = AlertRule::where('product_id', $rule->product_id)
                ->where('warehouse_id', $rule->warehouse_id)
                ->where('alert_type', $rule->alert_type)
                ->where('status', 1)
                ->where('id', '<>', $id)
                ->find();
            if ($conflict) {
                throw new \Exception('该商品+仓库+预警类型组合已存在启用规则');
            }
        }

        $rule->status = $status;
        return $rule->save();
    }

    /**
     * 日志列表分页查询
     */
    public function getLogList(array $params): array
    {
        $page     = $params['page'] ?? 1;
        $pageSize = $params['pageSize'] ?? 10;

        $query = AlertLog::with(['product', 'warehouse', 'rule']);

        // alert_type 筛选
        if (isset($params['alert_type']) && $params['alert_type'] !== '') {
            $query->where('alert_type', $params['alert_type']);
        }

        // product_id 筛选
        if (!empty($params['product_id'])) {
            $query->where('product_id', $params['product_id']);
        }

        // warehouse_id 筛选
        if (!empty($params['warehouse_id'])) {
            $query->where('warehouse_id', $params['warehouse_id']);
        }

        // is_read 筛选
        if (isset($params['is_read']) && $params['is_read'] !== '') {
            $query->where('is_read', $params['is_read']);
        }

        // date_range 筛选：支持 [start, end]
        if (!empty($params['date_range'])) {
            $range = is_array($params['date_range']) ? $params['date_range'] : explode(',', $params['date_range']);
            if (!empty($range[0])) {
                $query->where('create_time', '>=', $range[0]);
            }
            if (!empty($range[1])) {
                $query->where('create_time', '<=', $range[1]);
            }
        }

        $list = $query->order('id', 'desc')->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['product_name']    = $item->product ? $item->product->name : '';
            $row['warehouse_name']  = $item->warehouse ? $item->warehouse->name : '';
            $row['rule_name']       = $item->rule ? $item->rule->name : '';
            $row['is_read_text']    = $item->is_read_text;
            $row['alert_type_text'] = $item->alert_type_text;
            $rows[] = $row;
        }

        return [
            'total' => $list->total(),
            'rows'  => $rows,
        ];
    }

    /**
     * 单条已读
     */
    public function markRead(int $id): bool
    {
        $log = AlertLog::find($id);
        if (!$log) {
            throw new \Exception('日志不存在');
        }
        return $log->markAsRead();
    }

    /**
     * 批量已读
     */
    public function batchRead(array $ids): int
    {
        return AlertLog::whereIn('id', $ids)->where('is_read', 0)->update(['is_read' => 1]);
    }

    /**
     * 获取未读数量
     */
    public function getUnreadCount(array $filters = []): int
    {
        $query = AlertLog::where('is_read', 0);

        if (!empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }
        if (!empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        return $query->count();
    }
}
