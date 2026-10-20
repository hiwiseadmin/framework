<?php
namespace app\admin\controller;

use app\common\controller\traits\CrudTrait;
use app\admin\model\Admin as AdminModel;
use wise\auth\facade\Rbac;
use app\admin\facade\CurrentAdmin;
use wise\operationlog\attribute\OperationLog;
use think\facade\Db;
use think\exception\ValidateException;
use think\Model;

#[OperationLog(channel: 'admin', tags: ['admin', 'administrator'])]
class Admin extends BaseController
{
    use CrudTrait;

    protected function getModel(): Model
    {
        return new AdminModel();
    }

    /**
     * 管理员列表
     */
    public function index()
    {
        $roles = Rbac::getAllRoles();
        return $this->assign('roles', $roles)->fetch();
    }

    /**
     * 获取管理员列表数据
     */
    public function getList()
    {
        $page     = $this->request->param('page/d', 1);
        $limit    = $this->request->param('limit/d', 10);
        $username = $this->request->param('username/s', '');
        $roleId   = $this->request->param('role_id/d', 0);
        $status   = $this->request->param('status/d', -1);

        try {
            $query = AdminModel::with(['roles'])->order('id', 'desc');

            if ($username) {
                $query->where(function ($q) use ($username) {
                    $q->where('username', 'like', "%{$username}%")
                      ->whereOr('nickname', 'like', "%{$username}%");
                });
            }

            if ($roleId > 0) {
                $adminIds = Rbac::getUserIdsByRole($roleId);
                $query->where('id', 'in', $adminIds ?: [0]);
            }

            if ($status != -1) {
                $query->where('status', $status);
            }

            if (!CurrentAdmin::isSuperAdmin()) {
                $query->where('id', '<>', 1);
            }

            $total = $query->count();
            $list  = $query->page($page, $limit)->select()->toArray();

            foreach ($list as &$admin) {
                $admin['role_names'] = implode(',', array_column($admin['roles'], 'name'));
                unset($admin['password']);
            }

            return $this->success('ok', [
                'total' => $total,
                'list'  => $list,
                'page'  => $page,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 管理员预览
     */
    public function preview()
    {
        $id    = $this->request->param('id/d', 0);
        $admin = AdminModel::with(['roles'])->findOrEmpty($id);
        return $this->assign('admin', $admin)->fetch();
    }

    /**
     * 新增页面（覆盖 trait，追加角色列表）
     */
    public function add()
    {
        $roles = Rbac::getAllRoles();

        return $this->assign([
            'isEdit'     => false,
            'row'        => null,
            'roles'      => $roles,
            'formAction' => url('save'),
            'pageTitle'  => '添加用户',
        ])->fetch('form');
    }

    /**
     * 编辑页面（覆盖 trait，追加角色列表和已有角色ID）
     */
    public function edit()
    {
        $id    = $this->request->param('id/d', 0);
        $admin = AdminModel::find($id);

        if (!$admin) {
            return $this->error('管理员不存在');
        }

        $this->checkAdminOwnership((int) $admin->id);

        $admin['role_ids'] = $admin->roles()->column('role_id');
        $roles = Rbac::getAllRoles();

        return $this->assign([
            'isEdit'     => true,
            'row'        => $admin,
            'roles'      => $roles,
            'formAction' => url('update'),
            'pageTitle'  => '编辑用户',
        ])->fetch('form');
    }

    /**
     * 新增保存（覆盖 trait，处理角色分配）
     */
    #[OperationLog(template: '创建管理员 {data.username}', level: 'INFO')]
    public function save()
    {
        $data = $this->request->param();
        unset($data['id']);

        try {
            // 模型层统一校验（自动加载 app\admin\validate\Admin 的 add 场景）
            // ⚠️ 必须先校验再过滤：password_confirm 不是表字段，
            //    若先 filterDataGeneric 会被移除，导致 confirm 规则永远失败。
            (new AdminModel())->validateOrFail($data, [], 'add');

            // 应用可写字段白名单，防批量赋值
            $data = $this->filterDataGeneric(new AdminModel(), $data);

            $data['last_login_time'] = null;
            $data['last_login_ip']   = '';
            $roleIds = $this->request->param('role_ids/a', []);
            unset($data['role_ids']);

            $this->beforeSave($data, true);

            Db::startTrans();
            $admin = AdminModel::create($data);
            // 始终同步角色（含空数组，保证取消全部角色时也能正确更新成员数）
            $admin->syncRoles($roleIds);
            $this->afterSave($admin, true);
            Db::commit();

            return $this->success('管理员创建成功', ['id' => $admin->id]);
        } catch (ValidateException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        }
    }

    /**
     * 更新管理员（覆盖 trait，处理角色同步 + 排除敏感字段）
     */
    #[OperationLog(template: '更新管理员信息 ID:{id}', level: 'INFO')]
    public function update()
    {
        $id   = $this->request->param('id/d', 0);
        $data = $this->request->param();

        unset($data['password'], $data['username'], $data['role_ids']);

        // 应用可写字段白名单，防批量赋值
        $data = $this->filterDataGeneric(new AdminModel(), $data);

        $roleIds = $this->request->param('role_ids/a', []);

        try {
            // 模型层统一校验（自动加载 app\admin\validate\Admin 的 edit 场景）
            (new AdminModel())->validateOrFail($data, [], 'edit');

            $admin = AdminModel::find($id);
            if (!$admin) {
                return $this->error('管理员不存在');
            }

            $this->checkAdminOwnership((int) $admin->id);

            $this->beforeSave($data, false);

            Db::startTrans();
            $admin->save($data);
            // 始终同步角色（含空数组，保证取消全部角色时也能正确更新成员数）
            $admin->syncRoles($roleIds);
            $this->afterSave($admin, false);
            Db::commit();

            return $this->success('管理员更新成功');
        } catch (ValidateException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        }
    }

    /**
     * 重置密码
     */
    #[OperationLog(template: '重置管理员 ID:{id} 密码', level: 'WARNING')]
    public function resetPassword()
    {
        $id               = $this->request->param('id/d', 0);
        $password         = $this->request->param('password/s', '', 'strip_tags');
        $password_confirm = $this->request->param('password_confirm/s', '', 'strip_tags');

        if ($id == 1) {
            return $this->error('不能重置超级管理员密码');
        }
        if (!$password_confirm) {
            return $this->error('确认密码不能为空');
        }
        if (!$password) {
            return $this->error('密码不能为空');
        }
        if ($password != $password_confirm) {
            return $this->error('两次输入密码不一致');
        }

        try {
            // 模型层统一校验（自动加载 app\admin\validate\Admin 的 password 场景）
            (new AdminModel())->validateOrFail([
                'password'         => $password,
                'password_confirm' => $password_confirm,
            ], [], 'password');

            $admin = AdminModel::find($id);
            if (!$admin) {
                return $this->error('管理员不存在');
            }

            $this->checkAdminOwnership((int) $admin->id);

            $admin->password = $password;
            $admin->save();

            return $this->success('密码重置成功');
        } catch (ValidateException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 校验管理员操作权限
     *
     * 规则：
     *  - 超级管理员可操作任何人
     *  - 非超级管理员只能操作自己
     *  - 非超级管理员不能操作超级管理员
     *
     * @param int $targetId 目标管理员 ID
     * @throws \think\Exception
     */
    protected function checkAdminOwnership(int $targetId): void
    {
        $currentId = CurrentAdmin::getAdminId();

        if ($targetId == 1 && !CurrentAdmin::isSuperAdmin()) {
            throw new \think\Exception('无权限操作超级管理员');
        }

        if (!CurrentAdmin::isSuperAdmin() && $currentId != $targetId) {
            throw new \think\Exception('无权限操作其他管理员');
        }
    }

    /**
     * 删除前：超级管理员/自身检查
     */
    protected function beforeDelete(array $ids): void
    {
        if (in_array(1, $ids)) {
            throw new \think\Exception('不能删除超级管理员');
        }
        if (in_array(CurrentAdmin::getAdminId(), $ids)) {
            throw new \think\Exception('不能删除当前登录管理员');
        }
    }

    /**
     * 删除后：清理角色关联
     */
    protected function afterDelete(array $ids): void
    {
        // 通过服务层清理用户-角色关联，确保角色成员数实时同步
        Rbac::detachUsersFromRoles($ids);
    }

    /**
     * 分配角色
     */
    #[OperationLog(template: '分配管理员 ID:{id} 角色', level: 'INFO')]
    public function assignRole()
    {
        $id      = $this->request->param('id/d', 0);
        $roleIds = $this->request->param('role_ids/a', []);

        try {
            $admin = AdminModel::find($id);
            if (!$admin) {
                return $this->error('管理员不存在');
            }

            $this->checkAdminOwnership((int) $admin->id);

            $admin->syncRoles($roleIds);
            return $this->success('角色分配成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 批量删除
     */
    #[OperationLog(template: '批量删除管理员', level: 'WARNING')]
    public function multi()
    {
        $ids = $this->request->param('ids/a', []);

        if (empty($ids)) {
            return $this->error('请选择要删除的管理员');
        }

        try {
            $this->beforeDelete($ids);

            Db::startTrans();
            AdminModel::where('id', 'in', $ids)->delete();
            $this->afterDelete($ids);
            Db::commit();

            return $this->success('管理员批量删除成功');
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除管理员（单条 / 批量）
     *
     * 入口保留在控制器：`_delete()` 负责通用事务，`beforeDelete/afterDelete`
     * 负责超管保护与角色关联清理。
     */
    #[OperationLog(template: '删除管理员 ID:{id}', level: 'WARNING')]
    public function delete()
    {
        return $this->_delete();
    }

    /**
     * 彻底删除管理员（物理删除，走 beforeDelete 超管保护）
     */
    #[OperationLog(template: '彻底删除管理员 ID:{id}', level: 'WARNING')]
    public function forceDelete()
    {
        return $this->_forceDelete();
    }

    /**
     * 恢复管理员（模型未启用软删除时由 `_restore()` 返回明确错误）
     */
    public function restore()
    {
        return $this->_restore();
    }

    /**
     * 切换状态（toggle 模式）
     */
    #[OperationLog(template: '切换管理员 ID:{id} 状态', level: 'INFO')]
    public function status()
    {
        $id    = $this->request->param('id/d', 0);
        $admin = AdminModel::find($id);

        if (!$admin) {
            return $this->error('管理员不存在');
        }

        $this->checkAdminOwnership((int) $admin->id);

        try {
            $admin->status = $admin->status == 1 ? 0 : 1;
            $admin->save();
            return $this->success('状态切换成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
