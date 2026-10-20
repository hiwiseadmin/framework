<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\View;
use wise\config\DictSDK;
use wise\config\model\DictType;
use wise\config\model\DictItem;
use wise\config\exception\DictException;

/**
 * WiseConfig 数据字典管理控制器
 *
 * 独立于配置管理的字典管理界面，使用左侧类型列表 + 右侧字典项的拆分面板。
 *
 * 路由: admin/wise.dict/*
 */
class WiseDict extends BaseController
{
    /**
     * 字典管理主页面
     */
    public function index()
    {
        $wsconfig = View::__get('wsconfig');
        $wsconfig['js'] = '../admin/wise_dict';

        return $this->assign([
            'wsconfig' => $wsconfig,
        ])->fetch('admin@wise_dict/index');
    }

    // ==================== 字典类型 ====================

    /**
     * 字典类型搜索
     */
    public function typeSearch()
    {
        $keyword = $this->request->param('keyword', '');
        if (mb_strlen($keyword) < 1) {
            return $this->success('ok', []);
        }

        $escaped = addcslashes((string) $keyword, '%_\\');
        $list = DictType::where(function ($query) use ($escaped) {
                $query->where('name', 'like', "%{$escaped}%")
                      ->whereOr('dict_code', 'like', "%{$escaped}%");
            })
            ->order('id', 'desc')
            ->limit(50)
            ->select()
            ->toArray();

        return $this->success('ok', $list);
    }

    /**
     * 字典类型列表
     */
    public function list()
    {
        $params = $this->request->param();
        $page   = (int)($params['page'] ?? 1);
        $limit  = (int)($params['limit'] ?? 100);

        $typeService = DictSDK::getTypeService();
        $paginator   = $typeService->getList(array_merge($params, [
            'page'  => $page,
            'limit' => $limit,
        ]));

        return $this->success('ok', [
            'list'  => $paginator->items(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * 获取单个字典类型详情
     */
    public function detail()
    {
        $dictCode = $this->request->param('dict_code', '');
        if (empty($dictCode)) {
            return $this->error('字典编码不能为空');
        }
        $type = DictType::where('dict_code', $dictCode)->find();
        if (!$type) {
            return $this->error('字典类型不存在');
        }
        return $this->success('ok', $type->toArray());
    }

    /**
     * 创建/更新字典类型
     */
    public function save()
    {
        $data  = $this->postData();
        $isNew = empty($data['dict_code_orig']);

        if (empty($data['dict_code']) || empty($data['name'])) {
            return $this->error('字典编码和名称不能为空');
        }

        $typeService = DictSDK::getTypeService();

        try {
            if ($isNew) {
                $typeService->createType([
                    'dict_code'   => $data['dict_code'],
                    'name'        => $data['name'],
                    'description' => $data['description'] ?? '',
                    'is_tree'     => (int)($data['is_tree'] ?? 0),
                    'status'      => (int)($data['status'] ?? 1),
                ]);
                return $this->success('新增成功');
            } else {
                $typeService->updateType($data['dict_code_orig'], [
                    'name'        => $data['name'],
                    'description' => $data['description'] ?? '',
                    'is_tree'     => (int)($data['is_tree'] ?? 0),
                    'status'      => (int)($data['status'] ?? 1),
                ]);
                return $this->success('更新成功');
            }
        } catch (DictException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除字典类型
     */
    public function delete()
    {
        $dictCode = $this->request->post('dict_code', '');
        if (empty($dictCode)) {
            return $this->error('字典编码不能为空');
        }
        try {
            DictSDK::getTypeService()->deleteType($dictCode);
            return $this->success('删除成功');
        } catch (DictException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 切换字典类型状态
     */
    public function toggleStatus()
    {
        $dictCode = $this->request->post('dict_code', '');
        try {
            DictSDK::getTypeService()->toggleStatus($dictCode);
            return $this->success('状态已切换');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== 字典项 ====================

    /**
     * 获取单条字典项详情（用于编辑弹窗）
     */
    public function itemDetail()
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            return $this->error('参数错误');
        }
        $item = DictItem::find($id);
        if (!$item) {
            return $this->error('字典项不存在');
        }
        return $this->success('ok', $item->toArray());
    }

    /**
     * 获取所有字典类型的项数量统计
     */
    public function typeCounts()
    {
        $allTypes = DictType::order('id', 'desc')->select();
        $counts = [];
        foreach ($allTypes as $type) {
            $counts[$type->dict_code] = DictItem::where('dict_code', $type->dict_code)->count();
        }
        return $this->success('ok', $counts);
    }

    /**
     * 字典项列表（分页）
     */
    public function items()
    {
        $dictCode = $this->request->param('dict_code', '');
        if (empty($dictCode)) {
            return $this->error('字典编码不能为空');
        }

        $params = $this->request->param();
        $page   = (int)($params['page'] ?? 1);
        $limit  = (int)($params['limit'] ?? 15);

        try {
            $itemService = DictSDK::getItemService();
            $paginator   = $itemService->getList($dictCode, $params);

            // 检查是否为树形字典
            $dictType = DictType::where('dict_code', $dictCode)->find();
            $tree = [];
            if ($dictType && $dictType->is_tree == 1) {
                try { $tree = $itemService->getTree($dictCode); } catch (\Exception $e) {}
            }

            return $this->success('ok', [
                'list'    => $paginator->items(),
                'tree'    => $tree,
                'total'   => $paginator->total(),
                'page'    => $page,
                'is_tree' => $dictType ? ($dictType->is_tree == 1) : false,
            ]);
        } catch (DictException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 字典项创建/更新
     */
    public function itemSave()
    {
        $data  = $this->postData();
        $isNew = empty($data['id']);

        if (empty($data['dict_code']) || !isset($data['item_value']) || empty($data['item_label'])) {
            return $this->error('编码、项值、标签均不能为空');
        }

        $itemService = DictSDK::getItemService();

        try {
            if ($isNew) {
                $itemService->createItem([
                    'dict_code'  => $data['dict_code'],
                    'item_value' => $data['item_value'],
                    'item_label' => $data['item_label'],
                    'parent_id'  => (int)($data['parent_id'] ?? 0),
                    'sort'       => (int)($data['sort'] ?? 0),
                    'status'     => (int)($data['status'] ?? 1),
                    'remark'     => $data['remark'] ?? '',
                ]);
                return $this->success('新增成功');
            } else {
                $update = [];
                if (isset($data['item_value'])) $update['item_value'] = $data['item_value'];
                if (isset($data['item_label'])) $update['item_label'] = $data['item_label'];
                if (isset($data['parent_id']))  $update['parent_id']  = (int)$data['parent_id'];
                if (isset($data['sort']))       $update['sort']       = (int)$data['sort'];
                if (isset($data['status']))     $update['status']     = (int)$data['status'];
                if (isset($data['remark']))     $update['remark']     = $data['remark'];
                $itemService->updateItem((int)$data['id'], $update);
                return $this->success('更新成功');
            }
        } catch (DictException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除字典项
     */
    public function itemDelete()
    {
        $id = $this->request->post('id/d', 0);
        try {
            DictSDK::getItemService()->deleteItem($id);
            return $this->success('删除成功');
        } catch (DictException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 批量删除字典项
     */
    public function itemDeleteBatch()
    {
        $ids = $this->postData()['ids'] ?? [];
        if (empty($ids)) {
            return $this->error('请选择要删除的项');
        }
        try {
            DictSDK::getItemService()->batchDelete($ids);
            return $this->success('批量删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量更新字典项状态
     */
    public function itemBatchStatus()
    {
        $post   = $this->postData();
        $ids    = $post['ids'] ?? [];
        $status = (int)($post['status'] ?? 1);
        if (empty($ids)) {
            return $this->error('请选择字典项');
        }
        try {
            DictSDK::getItemService()->batchUpdateStatus($ids, $status);
            return $this->success('操作成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 兼容读取 POST 数据（JSON / 表单）
     *
     * 优先使用框架 post()（已自动解析 Content-Type=application/json 的请求体）。
     * 部分部署环境下（旧版前端、或被反向代理/网关剥离 Content-Type 等），
     * ThinkPHP 无法识别 JSON 请求体，post() 返回空数组，进而触发
     * "编码、项值、标签均不能为空"等"缺少字段"的提示。
     * 这里增加从原始请求体（php://input）兜底解析 JSON 的逻辑。
     */
    protected function postData(): array
    {
        $data = $this->request->post();
        if (!empty($data)) {
            return $data;
        }
        $raw = $this->request->getInput();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * 获取树形数据（用于父级选择器）
     */
    public function itemTree()
    {
        $dictCode = $this->request->param('dict_code', '');
        if (empty($dictCode)) {
            return $this->error('字典编码不能为空');
        }
        try {
            $tree = DictSDK::getItemService()->getTree($dictCode);
            return $this->success('ok', $tree);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
