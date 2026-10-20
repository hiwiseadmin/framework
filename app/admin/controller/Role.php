<?php
namespace app\admin\controller;

use wise\auth\facade\Rbac;
use wise\auth\facade\Perm;
use app\admin\model\Admin as AdminModel;
use app\admin\validate\Role as RoleValidate;
use wise\operationlog\attribute\OperationLog;
use think\exception\ValidateException;
use wise\support\tools\Tree;
use app\admin\service\PermissionTreeService;

#[OperationLog(channel: 'admin', tags: ['admin', 'role'])]
class Role extends BaseController
{
    /**
     * 角色权限配置中心（矩阵页面）
     * @return string
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 获取角色树数据（左侧面板）
     * @return \think\response\Json
     */
    public function getList()
    {
        try {
            $page   = $this->request->param('page/d', 1);
            $limit  = $this->request->param('limit/d', 10);
            $keyword = $this->request->param('keyword/s', '', 'strip_tags');

            // 分页查询（服务层统一统计成员数，口径 user_type='admin'）
            $result = Rbac::getRolePage($page, $limit, $keyword);

            // 父角色 → 子角色排序（同级按 sort、id），与矩阵表头保持同一顺序
            $list = $this->orderRolesByTree($result['list']);

            return $this->success('ok', [
                'total' => $result['total'],
                'list'  => $list,
                'page'  => $page,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取轻量角色树（用于选择题）
     * @return \think\response\Json
     */
    public function getRoleTree()
    {
        try {
            $tree = Rbac::getRoleTree();
            return $this->success('ok', $tree);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取权限矩阵数据
     * 返回所有权限（树形）+ 每个权限在每个角色下的状态
     *
     * @return \think\response\Json
     */
    public function getPermissionMatrix()
    {
        try {
            $roleId    = $this->request->param('role_id/d', 0);
            $groupId   = $this->request->param('group/s', '');
            $keyword   = $this->request->param('keyword/s', '', 'strip_tags');

            // 所有权限（树形）
            $permissions = Perm::getActiveList(['id', 'parent_id', 'ident', 'name', 'type', 'route', 'method', 'plugin', 'level'], $keyword);
            $permTree    = Tree::instance()->init($permissions)->getTreeArray(0);

            // 与「权限配置」树保持一致：补回菜单目录虚拟分组（id 为负、virtual=true）
            $permTree = (new PermissionTreeService())->build($permTree);

            // 所有启用的角色模型（以 ID 为键）
            // 注意：Role 模型无 ident 数据库字段（ident 为 slug 的访问器别名），
            // 此处必须使用 slug，否则 SQL 报错导致矩阵无法渲染。
            $roleModels = Rbac::getRoleModels(['id', 'parent_id', 'name', 'slug', 'is_inherit', 'sort']);

            // 收集每个角色的权限（直接 + 继承）
            $rolePermissions = [];
            foreach ($roleModels as $role) {
                $rolePermissions[$role->id] = [
                    'direct'     => $role->permissions()->column('id'),
                    'all'        => $role->getAllPermissions(),
                    'is_inherit' => $role->is_inherit,
                    'parent_id'  => $role->parent_id,
                ];
            }

            // 如果指定了目标角色，标记继承来源
            $inheritedFrom = [];
            if ($roleId && isset($rolePermissions[$roleId])) {
                $rp = $rolePermissions[$roleId];
                if ($rp['is_inherit'] && $rp['parent_id']) {
                    $parentRole = $roleModels[$rp['parent_id']] ?? null;
                    if ($parentRole) {
                        foreach ($rp['all'] as $pid) {
                            if (!in_array($pid, $rp['direct']) && $parentRole->hasPermission($pid)) {
                                $source = $parentRole->ident;
                                // 递归查找继承源
                                $ancestorIds = Rbac::getRoleAncestorIds($roleId);
                                foreach ($ancestorIds as $ancId) {
                                    $anc = $roleModels[$ancId] ?? null;
                                    if ($anc && in_array($pid, $anc->permissions()->column('id'))) {
                                        $source = $anc->ident;
                                        break;
                                    }
                                }
                                $inheritedFrom[$pid] = $source;
                            }
                        }
                    }
                }
            }

            // 表头角色顺序与左侧角色列表（侧边栏）一致：父角色 → 子角色，同级按 sort、id
            $orderedRoles = $this->orderRolesByTree(array_values(array_map(function ($r) {
                return $r->toArray();
            }, $roleModels)));

            $roles = array_values(array_map(function (array $row) use ($roleModels) {
                return $roleModels[(int) $row['id']]->toArray();
            }, $orderedRoles));

            return $this->success('ok', [
                'permissions'     => $permTree,
                'roles'           => $roles,
                'rolePermissions' => $rolePermissions,
                'inheritedFrom'   => $inheritedFrom,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 快速切换单个权限
     * POST /admin/role/togglePermission
     *
     * @return \think\response\Json
     */
    #[OperationLog(template: '修改角色 ID:{role_id} 权限', level: 'INFO')]
    public function togglePermission()
    {
        $roleId = $this->request->param('role_id/d', 0);
        $permId = $this->request->param('permission_id/d', 0);
        $action = $this->request->param('action/s', 'toggle'); // toggle|grant|revoke

        if (!$roleId || !$permId) {
            return $this->error('参数错误');
        }

        try {
            $role = Rbac::getRoleById($roleId);
            if (!$role) {
                return $this->error('角色不存在');
            }

            // 超级管理员保护
            $superRole = config('wise-auth.super_role', 'admin');
            if ($role->ident === $superRole) {
                return $this->error('超级管理员角色不允许修改权限');
            }

            if ($action === 'grant') {
                $role->grantPermission($permId);
                $status = 'granted';
            } elseif ($action === 'revoke') {
                $role->revokePermission($permId);
                $status = 'revoked';
            } else {
                // toggle
                $hasIt = in_array($permId, $role->permissions()->column('id'));
                if ($hasIt) {
                    $role->revokePermission($permId);
                    $status = 'revoked';
                } else {
                    $role->grantPermission($permId);
                    $status = 'granted';
                }
            }

            Rbac::clearRoleCache($roleId);
            return $this->success('ok', ['status' => $status, 'role_id' => $roleId, 'permission_id' => $permId]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 批量分配/撤销权限
     * POST /admin/role/batchAssign
     *
     * @return \think\response\Json
     */
    #[OperationLog(template: '批量分配角色 ID:{role_id} 权限', level: 'INFO')]
    public function batchAssign()
    {
        $roleId       = $this->request->param('role_id/d', 0);
        $permIds      = $this->request->param('permission_ids/a', []);
        $action       = $this->request->param('action/s', 'assign'); // assign|replace|revoke

        if (!$roleId) {
            return $this->error('参数错误');
        }

        try {
            $role = Rbac::getRoleById($roleId);
            if (!$role) {
                return $this->error('角色不存在');
            }

            $superRole = config('wise-auth.super_role', 'admin');
            if ($role->ident === $superRole) {
                return $this->error('超级管理员角色不允许修改权限');
            }

            if ($action === 'replace') {
                $role->syncPermissions($permIds);
            } elseif ($action === 'revoke') {
                $role->revokePermission($permIds);
            } else {
                // assign: 追加
                $role->grantPermission($permIds);
            }

            Rbac::clearRoleCache($roleId);
            return $this->success('ok', ['action' => $action, 'count' => count($permIds)]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取角色继承链（含各祖先角色的直接权限）
     * @return \think\response\Json
     */
    public function getInheritanceChain()
    {
        $roleId = $this->request->param('role_id/d', 0);

        if (!$roleId) {
            return $this->error('参数错误');
        }

        try {
            $role = Rbac::getRoleById($roleId);
            if (!$role) {
                return $this->error('角色不存在');
            }

            $chain   = [];
            $visited = [];

            // 向上追溯
            $current = $role;
            while ($current && !in_array($current->id, $visited)) {
                $visited[] = $current->id;
                $chain[] = [
                    'id'          => $current->id,
                    'name'        => $current->name,
                    'ident'       => $current->ident,
                    'is_inherit'  => $current->is_inherit,
                    'direct_perms' => $current->permissions()->column('id'),
                    'perm_count'  => $current->permissions()->count(),
                ];
                if ($current->is_inherit && $current->parent_id) {
                    $current = Rbac::getRoleById((int) $current->parent_id);
                } else {
                    break;
                }
            }

            return $this->success('ok', [
                'role_id'  => $roleId,
                'role_name' => $role->name,
                'chain'    => $chain,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取角色详情（含统计数据）
     * @return \think\response\Json
     */
    public function getDetail()
    {
        $roleId = $this->request->param('role_id/d', 0);

        if (!$roleId) {
            return $this->error('参数错误');
        }

        try {
            $role = Rbac::getRoleById($roleId);
            if (!$role) {
                return $this->error('角色不存在');
            }

            $directPerms    = $role->permissions()->column('id');
            $allPerms       = $role->getAllPermissions();
            $inheritedPerms = array_diff($allPerms, $directPerms);
            $userCount      = Rbac::getRoleUserCount($roleId);

            // 子角色（ident 为 slug 别名，字段名须用 slug）
            $children = Rbac::getChildren($roleId, ['id', 'name', 'slug']);

            // 成员（管理员）列表：先经服务层取 user_id，再查用户表，避免直接操作中间表
            $userIds = Rbac::getUserIdsByRole($roleId);
            $members = empty($userIds)
                ? []
                : AdminModel::whereIn('id', $userIds)
                    ->field('id, username, nickname, avatar, status')
                    ->select()
                    ->toArray();

            // 互斥角色（角色表 mutex 字段驱动）
            $conflicts = [];
            $mutexSlugs = is_array($role->mutex ?? null) ? $role->mutex : [];
            foreach ($mutexSlugs as $slug) {
                $conflictRole = Rbac::getRoleBySlug($slug);
                if ($conflictRole) {
                    $conflicts[] = ['id' => $conflictRole->id, 'name' => $conflictRole->name, 'ident' => $conflictRole->ident];
                }
            }

            return $this->success('ok', [
                'role'           => $role->toArray(),
                'direct_count'   => count($directPerms),
                'inherited_count' => count($inheritedPerms),
                'total_perm_count' => count($allPerms),
                'user_count'     => $userCount,
                'members'        => $members,
                'children'       => $children,
                'conflicts'      => $conflicts,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    // =============================
    // 原有方法（重构）
    // =============================

    /**
     * 角色预览页面
     * @return string
     */
    public function preview()
    {
        $id = $this->request->param('id/d', 0);
        $role = Rbac::getRoleOrEmpty($id);
        return $this->assign('role', $role)->fetch();
    }

    /**
     * 新增角色页面
     * @return string
     */
    public function add()
    {
        $parent_id = $this->request->param('id/d', 0);
        $parentRoles = Rbac::getAllRoles();
        $parentRoles = Tree::instance()->getTreeList(Tree::instance()->init($parentRoles)->getTreeArray(0));

        return $this->assign([
            'isEdit'      => false,
            'row'         => null,
            'formAction'  => url('save'),
            'pageTitle'   => '添加角色',
            'parent_id'   => $parent_id,
            'parentRoles' => $parentRoles,
        ])->fetch('form');
    }

    /**
     * 保存新增角色
     * @return \think\response\Json
     */
    #[OperationLog(template: '创建角色 {data.name}', level: 'INFO')]
    public function save()
    {
        try {
            $data = $this->request->param();
            validate(RoleValidate::class)->scene('add')->check($data);

            // 检查角色标识是否已存在
            if (Rbac::getRoleBySlug($data['ident'])) {
                return $this->error('角色标识已存在');
            }

            // 循环继承检测
            if (!empty($data['parent_id']) && $data['parent_id'] > 0
                && config('wise-auth.cycle_check_enabled', true)
            ) {
                if (Rbac::hasRoleCycle((int)$data['parent_id'])) {
                    return $this->error('父角色存在循环继承，请重新选择');
                }
            }

            $permissionIds = $this->normalizePermissionIds($data['permission_ids'] ?? []);
            unset($data['permission_ids']);

            // 字段白名单：仅保留 auth_roles 表的真实字段，避免表外字段导致写入异常
            // 同时将表单的 remark 映射到表字段 description
            $data = $this->normalizeRoleData($data);

            // 通过服务层创建角色（内部处理事务、权限同步与缓存清理）
            $role = Rbac::createRole($data, $permissionIds);

            return $this->success('角色创建成功', ['id' => $role->id]);
        } catch (ValidateException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 编辑角色页面
     * @return string|\think\response\Redirect
     */
    public function edit()
    {
        $id = $this->request->param('id/d', 0);

        $role = Rbac::getRoleById($id);
        if (!$role) {
            $this->error('角色不存在');
        }

        // 已有权限ID
        $role['permission_ids'] = $role->permissions()->column('id');

        // 互斥角色 slug（供前端 RBAC2 约束区预填充，逗号分隔）
        $mutex = $role->mutex ?? [];
        $role['mutex_slugs'] = is_array($mutex) ? implode(',', $mutex) : '';

        // 关联管理员数
        $role['admin_count'] = Rbac::getRoleUserCount($id);

        // 父角色选项
        $parentRoles = Rbac::getAllRoles();
        $parentRoles = Tree::instance()->getTreeList(Tree::instance()->init($parentRoles)->getTreeArray(0));

        return $this->assign([
            'isEdit'      => true,
            'row'         => $role,
            'formAction'  => url('update'),
            'pageTitle'   => '编辑角色',
            'parent_id'   => 0,
            'parentRoles' => $parentRoles,
        ])->fetch('form');
    }

    /**
     * 更新角色信息
     * @return \think\response\Json
     */
    #[OperationLog(template: '更新角色 ID:{id}', level: 'INFO')]
    public function update()
    {
        $id = $this->request->param('id/d', 0);

        try {
            $data = $this->request->param();
            validate(RoleValidate::class)->scene('edit')->check($data);

            $role = Rbac::getRoleById($id);
            if (!$role) {
                return $this->error('角色不存在');
            }

            // 超级管理员保护
            $superRole = config('wise-auth.super_role', 'admin');
            if ($role->ident === $superRole) {
                unset($data['ident']);
                // 超级管理员强制拥有所有启用权限
                $data['permission_ids'] = Perm::getActiveIds();
            }

            // 检查标识冲突
            if (isset($data['ident'])) {
                $existRole = Rbac::getRoleBySlug($data['ident']);
                if ($existRole && (int) $existRole->id !== (int) $id) {
                    return $this->error('角色标识已存在');
                }
            }

            // 循环继承检测
            if (!empty($data['parent_id']) && (int)$data['parent_id'] > 0
                && config('wise-auth.cycle_check_enabled', true)
            ) {
                if (Rbac::hasRoleCycle((int)$data['parent_id'], $id)) {
                    return $this->error('父角色存在循环继承，请重新选择');
                }
            }

            $permissionIds = $this->normalizePermissionIds($data['permission_ids'] ?? []);
            unset($data['permission_ids']);

            // 字段白名单：仅保留 auth_roles 表的真实字段，避免表外字段导致写入异常
            // 同时将表单的 remark 映射到表字段 description
            $data = $this->normalizeRoleData($data);

            // 通过服务层更新角色（内部处理事务、权限同步与缓存清理）
            Rbac::updateRole($role, $data, $permissionIds);

            return $this->success('角色更新成功');
        } catch (ValidateException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除角色
     * @return \think\response\Json
     */
    #[OperationLog(template: '删除角色 ID:{id}', level: 'WARNING')]
    public function delete()
    {
        $id = $this->request->param('id/d', 0);

        try {
            $role = Rbac::getRoleById($id);
            if (!$role) {
                return $this->error('角色不存在');
            }

            $superRole = config('wise-auth.super_role', 'admin');
            if ($role->ident === $superRole) {
                return $this->error('不能删除超级管理员角色');
            }

            // 检查是否有子角色
            if (Rbac::countChildren($id) > 0) {
                return $this->error('请先删除或转移子角色');
            }

            // 通过服务层删除角色（软删除 + 清理权限/用户关联 + 缓存清理）
            Rbac::deleteRole($role);

            return $this->success('角色删除成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 恢复已删除角色
     * POST /admin/role/restore
     */
    #[OperationLog(template: '恢复角色', level: 'INFO')]
    public function restore()
    {
        $id  = $this->request->param('id/d', 0);
        $ids = $this->request->param('ids/a', []);

        if (!$id && empty($ids)) {
            return $this->error('参数错误');
        }
        if ($id) $ids = array_merge($ids, [$id]);

        try {
            $affected = Rbac::restoreRoles($ids);
            return $this->success($affected > 0 ? '恢复成功' : '未找到可恢复的记录');
        } catch (\Exception $e) {
            return $this->error('恢复失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取所有可用角色（用于管理员角色分配）
     * @return \think\response\Json
     */
    public function getAllRoles()
    {
        try {
            $roles = Rbac::getAllRoles(['id', 'name', 'slug']);

            return $this->success('获取角色列表成功', $roles);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 角色对比页面
     * @return string
     */
    public function compare()
    {
        return $this->fetch();
    }

    /**
     * 获取角色对比数据
     * @return \think\response\Json
     */
    public function getCompareData()
    {
        $roleIdA = $this->request->param('role_a/d', 0);
        $roleIdB = $this->request->param('role_b/d', 0);

        if (!$roleIdA || !$roleIdB) {
            return $this->error('请选择两个角色进行对比');
        }

        try {
            $roleA = Rbac::getRoleById($roleIdA);
            $roleB = Rbac::getRoleById($roleIdB);
            if (!$roleA || !$roleB) {
                return $this->error('角色不存在');
            }

            // 获取两个角色的全部权限（含继承）
            $permsA = $roleA->getAllPermissions();
            $permsB = $roleB->getAllPermissions();

            // 直接权限
            $directA = $roleA->permissions()->column('id');
            $directB = $roleB->permissions()->column('id');

            // 差异分析
            $onlyA    = array_diff($permsA, $permsB);
            $onlyB    = array_diff($permsB, $permsA);
            $both     = array_intersect($permsA, $permsB);
            $neither  = array_diff(
                Perm::getActiveIds(),
                array_unique(array_merge($permsA, $permsB))
            );

            // 获取权限名称
            $allPerms = Perm::getActiveList(['id', 'name', 'ident', 'type']);
            $permNameMap = [];
            foreach ($allPerms as $p) {
                $permNameMap[$p['id']] = $p;
            }

            $formatPerms = function ($ids) use ($permNameMap, $directA, $directB) {
                $result = [];
                foreach ($ids as $pid) {
                    $p = $permNameMap[$pid] ?? null;
                    if (!$p) continue;
                    $result[] = [
                        'id'      => $pid,
                        'name'    => $p['name'],
                        'ident'   => $p['ident'],
                        'type'    => $p['type'],
                        'in_a'    => in_array($pid, $directA) ? 'direct' : 'inherited',
                        'in_b'    => in_array($pid, $directB) ? 'direct' : 'inherited',
                    ];
                }
                return $result;
            };

            return $this->success('ok', [
                'role_a'     => ['id' => $roleA->id, 'name' => $roleA->name, 'ident' => $roleA->ident],
                'role_b'     => ['id' => $roleB->id, 'name' => $roleB->name, 'ident' => $roleB->ident],
                'only_a'     => $formatPerms($onlyA),
                'only_b'     => $formatPerms($onlyB),
                'both'       => $formatPerms($both),
                'neither'    => $formatPerms($neither),
                'counts'     => [
                    'only_a'    => count($onlyA),
                    'only_b'    => count($onlyB),
                    'both'      => count($both),
                    'neither'   => count($neither),
                    'total'     => count($allPerms),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 切换角色状态
     * @return \think\response\Json
     */
    #[OperationLog(template: '切换角色 ID:{id} 状态', level: 'INFO')]
    public function toggle()
    {
        $id     = $this->request->param('id/d', 0);
        $status = $this->request->param('status/d', 0);

        try {
            $role = Rbac::getRoleById($id);
            if (!$role) {
                return $this->error('角色不存在');
            }

            $superRole = config('wise-auth.super_role', 'admin');
            if ($role->ident === $superRole && $status === 0) {
                return $this->error('不能禁用超级管理员角色');
            }

            Rbac::toggleRoleStatus($role, $status);

            return $this->success('操作成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 角色按「父角色 → 子角色」扁平化排序
     *
     * 与左侧角色列表（侧边栏）保持一致：同级按 sort 升序、再按 id 升序。
     * 输出保留原始字段，并追加 level（层级，0 起）。
     *
     * @param array $roles
     * @return array
     */
    private function orderRolesByTree(array $roles): array
    {
        $byId     = [];
        $children = [];

        foreach ($roles as $role) {
            $id = (int) ($role['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $byId[$id] = $role;

            $children[(int) ($role['parent_id'] ?? 0)][] = $id;
        }

        $compare = static function (int $a, int $b) use ($byId): int {
            $sortA = (int) ($byId[$a]['sort'] ?? 0);
            $sortB = (int) ($byId[$b]['sort'] ?? 0);

            return $sortA === $sortB ? $a <=> $b : $sortA <=> $sortB;
        };

        foreach ($children as &$ids) {
            usort($ids, $compare);
        }
        unset($ids);

        $ordered = [];
        $seen    = [];

        $walk = function (int $parentId, int $level) use (&$walk, &$ordered, &$seen, $children, $byId): void {
            foreach ($children[$parentId] ?? [] as $id) {
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                $role          = $byId[$id];
                $role['level'] = $level;
                $ordered[]     = $role;

                $walk($id, $level + 1);
            }
        };
        $walk(0, 0);

        // 父节点缺失/数据成环的角色兜底追加，避免列表漏项
        foreach ($byId as $id => $role) {
            if (isset($seen[$id])) {
                continue;
            }
            $role['level'] = 0;
            $ordered[]     = $role;
        }

        return $ordered;
    }

    /**
     * 规范化角色表单数据
     *
     * 仅保留 auth_roles 表的真实字段，并将表单的 remark 映射到表字段 description，
     * 避免把表外字段传入模型写入导致异常。
     *
     * @param array $data
     * @return array
     */
    /**
     * 规范化权限ID列表
     *
     * 角色表单通过隐藏域 permission_ids 以逗号分隔的字符串提交
     * （由前端 role.js 的 updatePermStats() 维护），此处统一转换为整型数组；
     * 同时兼容以数组直接提交的场景（如批量授权 permission_ids/a）。
     *
     * @param mixed $ids
     * @return array
     */
    private function normalizePermissionIds($ids): array
    {
        if (is_string($ids)) {
            if ($ids === '') {
                return [];
            }
            $ids = explode(',', $ids);
        }

        $ids = is_array($ids) ? $ids : [$ids];

        return array_values(array_filter(array_map('intval', $ids)));
    }

    private function normalizeRoleData(array $data): array
    {
        $allowed = ['name', 'ident', 'parent_id', 'is_inherit', 'sort', 'status', 'description', 'max_members', 'mutex'];

        // remark → description 映射
        if (!isset($data['description']) && isset($data['remark'])) {
            $data['description'] = $data['remark'];
        }

        // ident 字段经 Role::setIdentAttr 修改器映射为 slug，此处保留 ident 供模型处理
        $filtered = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $filtered[$field] = $data[$field];
            }
        }

        // max_members 转非负整型（0 = 不限制）
        if (isset($filtered['max_members'])) {
            $filtered['max_members'] = max(0, (int) $filtered['max_members']);
        }

        // mutex：前端提交逗号分隔的互斥角色 slug，统一转为数组供模型 JSON 存储
        if (isset($filtered['mutex'])) {
            $filtered['mutex'] = $this->normalizeMutexSlugs($filtered['mutex']);
        }

        return $filtered;
    }

    /**
     * 规范化互斥角色标识列表
     *
     * 支持逗号分隔字符串、JSON 字符串、数组三种输入，
     * 统一返回去重后的 slug 字符串数组。
     *
     * @param mixed $mutex
     * @return array
     */
    private function normalizeMutexSlugs($mutex): array
    {
        if (is_array($mutex)) {
            $mutex = implode(',', $mutex);
        }

        if (is_string($mutex)) {
            $decoded = json_decode($mutex, true);
            $mutex = is_array($decoded) ? $decoded : explode(',', $mutex);
        }

        $slugs = [];
        foreach ((array) $mutex as $slug) {
            $slug = trim((string) $slug);
            if ($slug !== '' && !in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }
}
