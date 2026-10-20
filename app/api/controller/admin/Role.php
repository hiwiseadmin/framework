<?php
declare(strict_types=1);

namespace app\api\controller\admin;

use think\Request;
use think\response\Json;
use wise\api\response\ErrorCode;
use wise\auth\facade\Rbac;

/**
 * 角色（后台 App API，只读）
 *
 * GET /api/admin/roles           角色列表（page/limit/keyword）
 * GET /api/admin/roles/tree      角色树（含父子关系，供选择器）
 * GET /api/admin/roles/{id}      角色详情（直接权限 + 含继承的全部权限 + 成员数）
 *
 * 权限：统一使用 admin.role.getlist（列表级节点）。
 *   角色详情在 Web 侧走 `admin.role.preview`，但 App 只做「只读查看」，
 *   与列表同一授权面即可；写操作（add/save/update/delete/togglePermission）一律不开放。
 */
class Role extends Base
{
    protected const MAX_LIMIT = 100;

    /**
     * 角色列表
     */
    public function index(Request $request): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $page    = max(1, (int) $request->param('page', 1));
        $limit   = (int) $request->param('limit', 20);
        $limit   = $limit < 1 ? 20 : min(self::MAX_LIMIT, $limit);
        $keyword = trim(strip_tags((string) $request->param('keyword', '')));

        try {
            $result = Rbac::getRolePage($page, $limit, $keyword);
        } catch (\Throwable $e) {
            return $this->error('角色列表获取失败：' . $e->getMessage());
        }

        return $this->success('ok', [
            'total' => (int) ($result['total'] ?? 0),
            'list'  => $result['list'] ?? [],
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 角色树（App 侧选择器用）
     */
    public function tree(Request $request): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        return $this->success('ok', ['tree' => Rbac::getRoleTree()]);
    }

    /**
     * 角色详情
     */
    public function detail(Request $request, int $id = 0): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $id = $id > 0 ? $id : (int) $request->param('role_id', 0);
        if ($id <= 0) {
            return $this->error('缺少角色 ID');
        }

        $role = Rbac::getRoleById($id);
        if ($role === null) {
            return $this->error('角色不存在', null, ErrorCode::NOT_FOUND);
        }

        // getAllPermissions() 返回权限 ID（含继承），direct 为本角色直接授予
        $directIds = $role->permissions()->column('id');
        $allIds    = $role->getAllPermissions();

        return $this->success('ok', [
            'id'                    => (int) $role->id,
            'parent_id'             => (int) $role->parent_id,
            'name'                  => (string) $role->name,
            'slug'                  => (string) $role->slug,
            'description'           => (string) $role->description,
            'sort'                  => (int) $role->sort,
            'status'                => (int) $role->status,
            'is_inherit'            => (int) $role->is_inherit,
            'user_count'            => (int) Rbac::getRoleUserCount($id),
            'direct_permission_ids' => array_map('intval', $directIds),
            'all_permission_ids'    => array_values(array_map('intval', $allIds)),
        ]);
    }
}
