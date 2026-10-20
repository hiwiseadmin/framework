<?php
namespace app\admin\controller;

use app\common\controller\traits\CrudTrait;
use app\admin\service\PermissionSyncService;
use app\admin\service\PermissionTreeService;
use wise\auth\model\Permission as PermissionModel;
use wise\auth\facade\Rbac;
use app\admin\validate\Permission as PermissionValidate;
use wise\operationlog\attribute\OperationLog;
use think\exception\ValidateException;
use think\facade\Db;
use think\Model;

#[OperationLog(channel: 'admin', tags: ['admin', 'permission'])]
class Permission extends BaseController
{
    use CrudTrait;

    protected $plugin = 'system';

    protected function getModel(): Model
    {
        return new PermissionModel();
    }

    /**
     * 权限管理列表
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 获取权限列表数据（树形结构）
     */
    public function getList()
    {
        $type      = $this->request->param('type/s');
        $withRoles = $this->request->param('with_roles/d', 0);

        if ($type == 'treearray') {
            $permissions = $this->getTreeArray();
        } elseif ($type == 'treelist') {
            $permissions = $this->getTreeList();
        } else {
            $permissions = $this->getFlatList($withRoles);
        }

        return $this->success('ok', ['list' => $permissions]);
    }

    /**
     * 权限预览
     */
    public function preview()
    {
        $id         = $this->request->param('id/d', 0);
        $permission = PermissionModel::find($id);

        if (!$permission) {
            return $this->error('权限不存在');
        }

        return $this->assign('permission', $permission)->fetch();
    }

    /**
     * 新增页面（覆盖 trait，追加权限树）
     */
    public function add()
    {
        $parentId    = $this->request->param('id/d', 0);
        $permissions = $this->getTreeList();

        return $this->assign([
            'isEdit'      => false,
            'row'         => null,
            'formAction'  => url('save'),
            'pageTitle'   => '添加权限',
            'parentId'    => $parentId,
            'permissions' => $permissions,
        ])->fetch('form');
    }

    /**
     * 新增权限
     */
    #[OperationLog(template: '创建权限 {data.name}', level: 'INFO')]
    public function save()
    {
        $data = $this->request->param();
        unset($data['id']);
        $data['plugin'] = $this->plugin;

        // 请求方法通配符统一：前端表单选项为 ALL，后端匹配判定统一使用 *
        if (isset($data['method'])) {
            $data['method'] = strtoupper($data['method']) === 'ALL' ? '*' : strtoupper($data['method']);
        }

        // 权限继承属于角色层字段，auth_permissions 表无此列，防御性剔除，避免未知字段导致保存失败
        unset($data['is_inherit']);

        try {
            validate(PermissionValidate::class)->scene('add')->check($data);

            if (PermissionModel::where('ident', $data['ident'])->find()) {
                return $this->error('权限标识已存在');
            }

            Db::startTrans();

            $data['status']   = $data['status'] ?? 1;
            $permission = PermissionModel::create($data);
            $permission->updatePath();

            Db::commit();
            Rbac::clearAllCache();

            return $this->success('权限创建成功', ['id' => $permission->id]);
        } catch (ValidateException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        }
    }

    /**
     * 编辑页面（覆盖 trait，追加权限树）
     */
    public function edit()
    {
        $id  = $this->request->param('id/d', 0);
        $row = PermissionModel::find($id);

        if (!$row) {
            return $this->error('权限不存在');
        }

        // 兼容历史数据：通配符统一为 * 展示
        if ($row->method === 'ALL') {
            $row->method = '*';
        }

        $permissions = $this->getTreeList();

        return $this->assign([
            'isEdit'      => true,
            'row'         => $row,
            'formAction'  => url('update'),
            'pageTitle'   => '编辑权限',
            'permissions' => $permissions,
        ])->fetch('form');
    }

    /**
     * 更新权限
     */
    #[OperationLog(template: '更新权限 ID:{id}', level: 'INFO')]
    public function update()
    {
        $id   = $this->request->param('id/d', 0);
        $data = $this->request->param();

        // 请求方法通配符统一：前端表单选项为 ALL，后端匹配判定统一使用 *
        if (isset($data['method'])) {
            $data['method'] = strtoupper($data['method']) === 'ALL' ? '*' : strtoupper($data['method']);
        }

        // 权限继承属于角色层字段，auth_permissions 表无此列，防御性剔除，避免未知字段导致保存失败
        unset($data['is_inherit']);

        try {
            validate(PermissionValidate::class)->scene('edit')->check($data);

            $permission = PermissionModel::find($id);
            if (!$permission) {
                return $this->error('权限不存在');
            }

            // 检查标识冲突
            if (isset($data['ident']) && PermissionModel::where('ident', $data['ident'])
                ->where('id', '<>', $id)->find()
            ) {
                return $this->error('权限标识已存在');
            }

            Db::startTrans();

            $oldParentId = $permission->parent_id;
            $permission->save($data);

            // parent_id 变化时更新 path
            if ($permission->parent_id != $oldParentId) {
                $permission->updatePath();
            }

            Db::commit();
            Rbac::clearAllCache();

            return $this->success('权限更新成功');
        } catch (ValidateException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除前：检查子权限 + 清理角色关联
     *
     * 角色关联必须在删除**之前**清理：物理删除后 `find()` 取不到行，
     * 软删除后默认作用域也会排除该行；放到 afterDelete 会导致关联长期残留。
     */
    protected function beforeDelete(array $ids): void
    {
        foreach ($ids as $id) {
            if (PermissionModel::where('parent_id', $id)->count() > 0) {
                throw new \think\Exception('请先删除子权限');
            }
        }

        foreach ($ids as $id) {
            $perm = PermissionModel::find($id);
            if ($perm) {
                $perm->roles()->detach();
            }
        }
    }

    /**
     * 删除后：刷新缓存
     */
    protected function afterDelete(array $ids): void
    {
        Rbac::clearAllCache();
    }

    /**
     * 删除权限（单条 / 批量）
     *
     * `beforeDelete()` 负责子权限检查与角色关联清理，`afterDelete()` 刷新缓存；
     * 入口必须委托给 trait 实现。
     */
    #[OperationLog(template: '删除权限 ID:{id}', level: 'WARNING')]
    public function delete()
    {
        return $this->_delete();
    }

    /**
     * 彻底删除权限（物理删除，走 beforeDelete 清理角色关联）
     */
    #[OperationLog(template: '彻底删除权限 ID:{id}', level: 'WARNING')]
    public function forceDelete()
    {
        return $this->_forceDelete();
    }

    /**
     * 恢复已删除权限
     */
    #[OperationLog(template: '恢复权限', level: 'INFO')]
    public function restore()
    {
        $id  = $this->request->param('id/d', 0);
        $ids = $this->request->param('ids/a', []);

        if (!$id && empty($ids)) {
            return $this->error('参数错误');
        }
        if ($id) {
            $ids = array_merge($ids, [$id]);
        }

        try {
            $affected = PermissionModel::restoreByIds($ids);
            if ($affected > 0) {
                Rbac::clearAllCache();
            }
            return $this->success($affected > 0 ? '恢复成功' : '未找到可恢复的记录');
        } catch (\Exception $e) {
            return $this->error('恢复失败: ' . $e->getMessage());
        }
    }

    /**
     * 检查哪些角色引用了指定权限
     */
    public function getReferencedRoles()
    {
        $id   = $this->request->param('id/d', 0);
        $perm = PermissionModel::find($id);

        if (!$perm) {
            return $this->error('权限不存在');
        }

        // 直接分配该权限的角色
        $directRoles = $perm->roles()->field('id,name,slug')->select()->toArray();
        $directIds   = array_column($directRoles, 'id');

        // 继承获得该权限的角色：沿 is_inherit 祖先链向上，任一祖先直接拥有该权限
        $inheritingRoles = [];
        try {
            $roleModels = Rbac::getRoleModels(['id', 'parent_id', 'name', 'slug', 'is_inherit']);
            foreach ($roleModels as $role) {
                if (in_array($role->id, $directIds)) {
                    continue;
                }
                if (!$role->is_inherit || !$role->parent_id) {
                    continue;
                }
                $current = $role;
                $visited = [];
                while ($current && !in_array($current->id, $visited)) {
                    $visited[] = $current->id;
                    if ($current->id !== $role->id && in_array($id, $current->permissions()->column('id'))) {
                        $inheritingRoles[] = ['id' => $role->id, 'name' => $role->name, 'slug' => $role->slug];
                        break;
                    }
                    if ($current->is_inherit && $current->parent_id) {
                        $current = Rbac::getRoleById((int)$current->parent_id);
                    } else {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // 继承计算失败不影响直接引用展示
        }

        return $this->success('ok', [
            'permission'       => ['id' => $perm->id, 'name' => $perm->name],
            'roles'            => $directRoles,
            'direct_roles'     => $directRoles,
            'inheriting_roles' => $inheritingRoles,
            'count'            => count($directRoles),
        ]);
    }

    /**
     * 从菜单同步权限
     *
     * 前端「同步权限」表单提交：
     *   - menus=1     启用菜单/按钮 → 权限节点（幂等；目录无 path，计入 skipped）
     *   - packages=1  各包/插件声明的权限（含 AI 工具）→ 权限节点（幂等）
     *
     * 业务逻辑统一收敛到 {@see PermissionSyncService}，与 CLI `menu:permission-sync`
     * 共用同一实现；本方法仅做参数解析与响应封装。
     */
    public function updateFromMenuActionApi()
    {
        $syncMenus    = (int) $this->request->param('menus', 0);
        $syncPackages = (int) $this->request->param('packages', $this->request->param('ai_tools', 0));

        if (!$syncMenus && !$syncPackages) {
            return $this->error('请至少选择一项同步内容');
        }

        $result = (new PermissionSyncService())->sync([
            'menus'    => (bool) $syncMenus,
            'packages' => (bool) $syncPackages,
        ]);

        return $this->success('同步完成', $result);
    }

    /**
     * 角色权限统计
     *
     * 统计每个角色绑定 / 继承的权限数量，前端用于展示角色权限分布。
     *
     * 完全通过 wise-auth 的 Rbac facade 访问，不直接触及包模型与中间表。
     */
    public function getRolePermissionTally()
    {
        // 获取所有启用角色（轻量：id/name/slug 等），由 Rbac::getAllRoles 统一封装
        $allRoles = Rbac::getAllRoles(['id', 'name']);

        $rows = [];
        foreach ($allRoles as $role) {
            // 含继承展开，已过滤禁用权限
            $permIds = Rbac::resolveRolesPermissionIds([(int)$role['id']]);

            $rows[] = [
                'role_id'    => (int)$role['id'],
                'role_name'  => $role['name'] ?? '',
                'perm_count' => count($permIds),
            ];
        }

        // 按权限数降序
        usort($rows, fn($a, $b) => $b['perm_count'] <=> $a['perm_count']);

        return $this->success('ok', ['roles' => $rows, 'total' => count($rows)]);
    }

    /**
     * 状态切换（单个 toggle / 批量 update）
     */
    #[OperationLog(template: '切换权限状态', level: 'INFO')]
    public function status()
    {
        $id     = $this->request->param('id/d', 0);
        $ids    = $this->request->param('ids/a', []);
        $status = $this->request->param('status/d', -1);
        $model  = $this->getModel();

        try {
            // 单个 toggle：自动取反
            if ($id && empty($ids)) {
                $perm = PermissionModel::find($id);
                if (!$perm) {
                    return $this->error('权限不存在');
                }
                $perm->status = $perm->status == 1 ? 0 : 1;
                $perm->save();
                Rbac::clearAllCache();
                return $this->success('操作成功');
            }

            // 批量更新
            if ($id) {
                $ids = array_merge([$id], $ids);
            }
            if (empty($ids)) {
                return $this->error('请选择操作项');
            }

            $model->whereIn($model->getPk(), $ids)->update(['status' => $status]);
            Rbac::clearAllCache();
            return $this->success('操作成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== 私有辅助方法 ====================

    /**
     * 获取权限树数组
     */
    private function getTreeArray()
    {
        $items = PermissionModel::where('status', 1)
            ->field('id,parent_id,name,ident,type,method,route,plugin,level')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $tree = \wise\support\tools\Tree::instance()->init($items)->getTreeArray(0);

        // 补回菜单目录（dir）虚拟分组：让权限树与「菜单管理」的层级一致。
        // 虚拟节点 id 为负数且带 virtual=true，前端不渲染复选框、不会进角色权限。
        return (new PermissionTreeService())->build($tree);
    }

    /**
     * 获取权限树列表（含缩进）
     */
    private function getTreeList()
    {
        $items = PermissionModel::where('status', 1)
            ->field('id,parent_id,name,ident,type,method,route,level')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $tree = \wise\support\tools\Tree::instance(['nbsp' => "\u{3000}"]);
        $tree->init($items);

        return $tree->getTreeList($tree->getTreeArray(0));
    }

    /**
     * 获取扁平列表
     */
    private function getFlatList($withRoles = 0)
    {
        $query = PermissionModel::order('sort', 'asc')->order('id', 'asc');
        $items = $query->select()->toArray();

        if ($withRoles) {
            foreach ($items as &$item) {
                $perm  = PermissionModel::find($item['id']);
                $roles = $perm ? $perm->roles()->field('id,name')->select()->toArray() : [];
                $item['role_count']       = count($roles);
                $item['referenced_roles'] = $roles;
            }
        }

        return $items;
    }
}
