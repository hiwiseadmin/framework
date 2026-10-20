<?php
declare(strict_types=1);

namespace app\api\controller\admin;

use app\admin\model\Admin as AdminModel;
use think\exception\ValidateException;
use think\facade\Db;
use think\Request;
use think\response\Json;
use wise\api\response\ErrorCode;
use wise\auth\model\Role;
use wise\auth\service\RbacManager;

/**
 * 管理员列表（后台 App API）
 *
 * GET /api/admin/admins?keyword=&status=&page=1&limit=20
 *
 * 权限：ident `admin.admin.index` —— 与后台「用户管理」菜单派生节点同源
 * （/admin/admin/index → path_to_dot），后台角色授权页勾选一次，Web 与 App 同时生效。
 *
 * 与 Web 侧 app\admin\controller\Admin::getList() 的差异（有意为之）：
 *   - 只读：不提供新增 / 编辑 / 删除（App 端写操作尚未开放）；
 *   - 输出字段白名单：不含 password / ext_fields / last_login_ip 等敏感列；
 *   - 非超管不可见超管账号（与 Web 侧口径一致）。
 */
class Admin extends Base
{
    /**
     * 输出字段白名单
     *
     * 不直接把模型 toArray 丢给 App：$hidden 只屏蔽 password，
     * ext_fields（可能含二次验证密文等）与 last_login_ip 不应出现在列表接口。
     */
    protected const FIELDS = [
        'id',
        'username',
        'nickname',
        'email',
        'mobile',
        'avatar',
        'status',
        'last_login_time',
        'create_time',
    ];

    /**
     * 单页上限：防止 App 拉全表
     */
    protected const MAX_LIMIT = 100;

    /**
     * GET /api/admin/admins
     */
    public function index(Request $request): Json
    {
        $admin = $request->admin ?? null;
        if (!$admin instanceof AdminModel) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $page    = max(1, (int) $request->param('page', 1));
        $limit   = (int) $request->param('limit', 20);
        $limit   = $limit < 1 ? 20 : min(self::MAX_LIMIT, $limit);
        $keyword = trim((string) $request->param('keyword', ''));
        $status  = $request->param('status', '');
        $isSuper = app(RbacManager::class)->isSuperAdmin((int) $admin->id, 'admin');

        // 条件闭包：count 与 select 各建一次查询，避免 clone 后聚合语义不一致
        $build = function () use ($keyword, $status, $isSuper) {
            $query = AdminModel::field(self::FIELDS);

            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword) {
                    $q->whereLike('username', "%{$keyword}%")
                        ->whereOr('nickname', 'like', "%{$keyword}%")
                        ->whereOr('email', 'like', "%{$keyword}%");
                });
            }

            // status 约定：'' / null / -1 表示不过滤
            if ($status !== '' && $status !== null && (int) $status !== -1) {
                $query->where('status', (int) $status);
            }

            // 与 Web 侧一致：非超管不可见超管账号
            if (!$isSuper) {
                $query->where('id', '<>', 1);
            }

            return $query;
        };

        $total = (int) $build()->count();

        $list = $total > 0
            ? $build()->with(['roles'])->order('id', 'desc')->page($page, $limit)->select()->toArray()
            : [];

        foreach ($list as &$row) {
            $row['role_names'] = implode(',', array_column((array) ($row['roles'] ?? []), 'name'));
            unset($row['roles']);
        }
        unset($row);

        return $this->success('ok', [
            'total' => $total,
            'list'  => $list,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /* =====================================================================
     * 写操作（批次 4）
     *
     * 统一前置条件：
     *   1. 路由挂 AdminApiWriteGuard —— 必须带有效 X-Write-Token（二次验证产物）；
     *   2. 业务规则与 Web 侧 `app\admin\controller\Admin` 对齐：
     *      - 非超管只能操作自己，且不能操作 id=1；
     *      - 不能重置超管密码、不能禁用自己；
     *      - 非超管不得分配角色（含给自己，避免自我提权）。
     * ===================================================================== */

    /**
     * 新增管理员
     *
     * POST /api/admin/admins
     */
    public function save(Request $request): Json
    {
        $current = $this->admin($request);
        if ($current === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $data = $this->pick($request, [
            'username', 'password', 'password_confirm', 'nickname', 'email', 'mobile',
            'status', 'avatar', 'description',
        ]);

        try {
            // 先校验再过滤：password_confirm 不是表字段，过滤后 confirm 规则会失效
            (new AdminModel())->validateOrFail($data, [], 'add');
        } catch (ValidateException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('参数校验失败：' . $e->getMessage());
        }

        $roleIds = $this->roleIds($request);

        if (($denied = $this->guardRoleIds($current, $roleIds)) !== null) {
            return $denied;
        }

        unset($data['password_confirm']);
        $data['last_login_time'] = null;
        $data['last_login_ip']   = '';

        Db::startTrans();
        try {
            $admin = AdminModel::create($data);
            $admin->syncRoles($roleIds);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            return $this->error('创建失败：' . $e->getMessage());
        }

        return $this->success('管理员创建成功', ['id' => (int) $admin->id]);
    }

    /**
     * 更新管理员
     *
     * PUT /api/admin/admins/{id}
     *
     * 不接受 username / password（改密码走 reset-password），
     * role_ids 仅在显式传入时才同步（避免「只改昵称却把角色清空」）。
     */
    public function update(Request $request, int $id = 0): Json
    {
        $current = $this->admin($request);
        if ($current === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $id     = $id > 0 ? $id : (int) $request->param('id', 0);
        $target = $id > 0 ? AdminModel::find($id) : null;

        if ($target === null) {
            return $this->error('管理员不存在', null, ErrorCode::NOT_FOUND);
        }

        if (($denied = $this->guardTarget($current, $target)) !== null) {
            return $denied;
        }

        $data = $this->pick($request, ['nickname', 'email', 'mobile', 'status', 'avatar', 'description']);

        try {
            (new AdminModel())->validateOrFail($data, [], 'edit');
        } catch (ValidateException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('参数校验失败：' . $e->getMessage());
        }

        $syncRoles = $request->has('role_ids');
        $roleIds   = $syncRoles ? $this->roleIds($request) : [];

        if ($syncRoles && ($denied = $this->guardRoleIds($current, $roleIds)) !== null) {
            return $denied;
        }

        Db::startTrans();
        try {
            $target->save($data);

            if ($syncRoles) {
                $target->syncRoles($roleIds);
                app(RbacManager::class)->incrementPermissionVersion((int) $target->id, 'admin');
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            return $this->error('更新失败：' . $e->getMessage());
        }

        return $this->success('管理员更新成功');
    }

    /**
     * 重置密码
     *
     * POST /api/admin/admins/{id}/reset-password
     */
    public function resetPassword(Request $request, int $id = 0): Json
    {
        $current = $this->admin($request);
        if ($current === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $id = $id > 0 ? $id : (int) $request->param('id', 0);

        if ($id === 1) {
            return $this->error('不能重置超级管理员密码', null, ErrorCode::FORBIDDEN);
        }

        $target = $id > 0 ? AdminModel::find($id) : null;
        if ($target === null) {
            return $this->error('管理员不存在', null, ErrorCode::NOT_FOUND);
        }

        if (($denied = $this->guardTarget($current, $target)) !== null) {
            return $denied;
        }

        $password = (string) $request->param('password', '');
        $confirm  = (string) $request->param('password_confirm', '');

        if ($password === '') {
            return $this->error('密码不能为空');
        }

        if ($password !== $confirm) {
            return $this->error('两次输入密码不一致');
        }

        try {
            (new AdminModel())->validateOrFail(
                ['password' => $password, 'password_confirm' => $confirm],
                [],
                'password'
            );

            $target->password = $password;
            $target->save();
        } catch (ValidateException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('重置失败：' . $e->getMessage());
        }

        // 旧 token 立即失效（与角色变更同一语义）
        app(RbacManager::class)->incrementPermissionVersion((int) $target->id, 'admin');

        return $this->success('密码重置成功');
    }

    /**
     * 分配角色
     *
     * POST /api/admin/admins/{id}/roles
     *
     * 仅超级管理员可调用（含「给自己加角色」——那是自我提权）。
     */
    public function assignRole(Request $request, int $id = 0): Json
    {
        $current = $this->admin($request);
        if ($current === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        if (!app(RbacManager::class)->isSuperAdmin((int) $current->id, 'admin')) {
            return $this->error('仅超级管理员可通过 App 分配角色', null, ErrorCode::FORBIDDEN);
        }

        $id     = $id > 0 ? $id : (int) $request->param('id', 0);
        $target = $id > 0 ? AdminModel::find($id) : null;

        if ($target === null) {
            return $this->error('管理员不存在', null, ErrorCode::NOT_FOUND);
        }

        $roleIds = $this->roleIds($request);

        if ($roleIds !== []) {
            $exists = Role::whereIn('id', $roleIds)->column('id');
            if (count($exists) !== count($roleIds)) {
                return $this->error('存在无效的角色 ID');
            }
        }

        Db::startTrans();
        try {
            $target->syncRoles($roleIds);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            return $this->error('角色分配失败：' . $e->getMessage());
        }

        app(RbacManager::class)->incrementPermissionVersion((int) $target->id, 'admin');

        return $this->success('角色分配成功', ['role_ids' => $roleIds]);
    }

    /**
     * 启用 / 禁用（toggle）
     *
     * POST /api/admin/admins/{id}/status
     */
    public function status(Request $request, int $id = 0): Json
    {
        $current = $this->admin($request);
        if ($current === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $id     = $id > 0 ? $id : (int) $request->param('id', 0);
        $target = $id > 0 ? AdminModel::find($id) : null;

        if ($target === null) {
            return $this->error('管理员不存在', null, ErrorCode::NOT_FOUND);
        }

        if ((int) $target->id === (int) $current->id) {
            return $this->error('不能通过 App 切换自己的启用状态', null, ErrorCode::FORBIDDEN);
        }

        if (($denied = $this->guardTarget($current, $target)) !== null) {
            return $denied;
        }

        $target->status = (int) $target->status === 1 ? 0 : 1;

        try {
            $target->save();
        } catch (\Throwable $e) {
            return $this->error('状态切换失败：' . $e->getMessage());
        }

        if ((int) $target->status === 0) {
            app(RbacManager::class)->incrementPermissionVersion((int) $target->id, 'admin');
        }

        return $this->success('状态切换成功', ['status' => (int) $target->status]);
    }

    /* =====================================================================
     * 内部辅助
     * ===================================================================== */

    /**
     * 从请求中挑出白名单字段（防批量赋值）
     *
     * @param string[] $fields
     */
    protected function pick(Request $request, array $fields): array
    {
        $all = $request->param();
        $out = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $all)) {
                $out[$field] = $all[$field];
            }
        }

        return $out;
    }

    /**
     * 角色 ID 列表（兼容 role_ids[] 与逗号分隔字符串）
     *
     * @return array<int, int>
     */
    protected function roleIds(Request $request): array
    {
        $raw = $request->param('role_ids', []);

        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }

        if (!is_array($raw)) {
            $raw = [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $raw), static fn (int $v): bool => $v > 0)));
    }

    /**
     * 目标账号操作权限（与 Web 侧 checkAdminOwnership 同口径）
     */
    protected function guardTarget(AdminModel $current, AdminModel $target): ?Json
    {
        $isSuper = app(RbacManager::class)->isSuperAdmin((int) $current->id, 'admin');

        if (!$isSuper && (int) $target->id === 1) {
            return $this->error('无权限操作超级管理员', null, ErrorCode::FORBIDDEN);
        }

        if (!$isSuper && (int) $target->id !== (int) $current->id) {
            return $this->error('无权限操作其他管理员', null, ErrorCode::FORBIDDEN);
        }

        return null;
    }

    /**
     * 角色分配权限：非超管不得分配超级管理员角色
     *
     * @param array<int, int> $roleIds
     */
    protected function guardRoleIds(AdminModel $current, array $roleIds): ?Json
    {
        if ($roleIds === []) {
            return null;
        }

        $exists = Role::whereIn('id', $roleIds)->column('id');
        if (count($exists) !== count($roleIds)) {
            return $this->error('存在无效的角色 ID');
        }

        if (app(RbacManager::class)->isSuperAdmin((int) $current->id, 'admin')) {
            return null;
        }

        $superRole = (string) config('wise-auth.super_role', 'super_admin');
        $slugs     = Role::whereIn('id', $roleIds)->column('slug');

        if (in_array($superRole, array_map('strval', $slugs), true)) {
            return $this->error('无权限分配超级管理员角色', null, ErrorCode::FORBIDDEN);
        }

        return null;
    }
}
