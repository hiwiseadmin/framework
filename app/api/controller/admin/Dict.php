<?php
declare(strict_types=1);

namespace app\api\controller\admin;

use think\Request;
use think\response\Json;
use wise\api\response\ErrorCode;
use wise\config\DictSDK;
use wise\config\model\DictType;

/**
 * 字典（后台 App API，只读）
 *
 * GET /api/admin/dicts                 字典类型列表（page/limit/keyword…）
 * GET /api/admin/dicts/{code}          字典类型详情
 * GET /api/admin/dicts/{code}/items    字典项（树形字典同时返回 tree）
 *
 * 权限：
 *   - 列表   → admin.wise_dict.list
 *   - 详情   → admin.wise_dict.detail
 *   - 字典项 → admin.wise_dict.items
 *
 * 用途：App 表单的下拉 / 单选 / 级联选项。写操作（保存字典、增删项）一律不做。
 */
class Dict extends Base
{
    /**
     * 字典项默认一次取满（App 端通常是「全量选项」语义）
     */
    protected const DEFAULT_ITEMS_LIMIT = 200;

    protected const MAX_ITEMS_LIMIT = 500;

    /**
     * 字典类型列表
     */
    public function index(Request $request): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $page  = max(1, (int) $request->param('page', 1));
        $limit = (int) $request->param('limit', 100);
        $limit = $limit < 1 ? 100 : min(self::MAX_ITEMS_LIMIT, $limit);

        try {
            $paginator = DictSDK::getTypeService()->getList(array_merge($request->param(), [
                'page'  => $page,
                'limit' => $limit,
            ]));
        } catch (\Throwable $e) {
            return $this->error('字典列表获取失败：' . $e->getMessage());
        }

        return $this->success('ok', [
            'list'  => $paginator->items(),
            'total' => $paginator->total(),
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 字典类型详情
     */
    public function show(Request $request, string $code = ''): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $code = $code !== '' ? $code : trim((string) $request->param('dict_code', ''));
        if ($code === '') {
            return $this->error('缺少字典编码');
        }

        $type = DictType::where('dict_code', $code)->find();
        if ($type === null) {
            return $this->error('字典类型不存在', null, ErrorCode::NOT_FOUND);
        }

        return $this->success('ok', $type->toArray());
    }

    /**
     * 字典项
     *
     * 树形字典（is_tree=1）额外返回 tree，App 可直接渲染级联选择。
     */
    public function items(Request $request, string $code = ''): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $code = $code !== '' ? $code : trim((string) $request->param('dict_code', ''));
        if ($code === '') {
            return $this->error('缺少字典编码');
        }

        $limit = (int) $request->param('limit', self::DEFAULT_ITEMS_LIMIT);
        $limit = $limit < 1 ? self::DEFAULT_ITEMS_LIMIT : min(self::MAX_ITEMS_LIMIT, $limit);

        $params = array_merge($request->param(), [
            'page'  => max(1, (int) $request->param('page', 1)),
            'limit' => $limit,
        ]);

        try {
            $paginator = DictSDK::getItemService()->getList($code, $params);
        } catch (\Throwable $e) {
            return $this->error('字典项获取失败：' . $e->getMessage());
        }

        $data = [
            'list'  => $paginator->items(),
            'total' => $paginator->total(),
            'page'  => (int) ($params['page']),
            'limit' => $limit,
        ];

        $type = DictType::where('dict_code', $code)->find();
        if ($type !== null && (int) $type->is_tree === 1) {
            $tree = DictSDK::getItemService()->getTree($code);
            $data['tree'] = $tree;
            $data['is_tree'] = true;
        } else {
            $data['is_tree'] = false;
        }

        return $this->success('ok', $data);
    }
}
