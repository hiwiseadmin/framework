<?php
declare(strict_types=1);

namespace app\api\service;

use app\admin\model\Admin;
use app\admin\model\Menu as MenuModel;
use app\common\service\MenuLauncherService;
use wise\auth\service\RbacManager;

/**
 * App 端导航数据（菜单树 / 快捷入口）
 *
 * Web 侧由 `app\admin\model\Menu::getMenuByRoleId()` + `CurrentAdmin`（Session）驱动；
 * App 侧没有 Session，身份来自 JWT 注入的 `$request->admin`，故在此统一收敛：
 *   - 超管：全量菜单树（与 Web 的 Menu::getMenuByRoleId 内部超管分支同源）
 *   - 非超管：按角色过滤（MenuPermissionBridge，含继承与状态过滤）
 *
 * 被 `/api/admin/menus` 与 `/api/admin/dashboard` 共用，避免两处各写一份分支逻辑。
 */
class AdminNavigationService
{
    /**
     * 当前管理员可见的菜单树
     *
     * @return array<int, array<string, mixed>>
     */
    public function menus(Admin $admin): array
    {
        $id = (int) $admin->id;

        if (app(RbacManager::class)->isSuperAdmin($id, 'admin')) {
            return MenuModel::getTreeArray();
        }

        $roleIds = [];
        foreach ($admin->getRoles() as $role) {
            $roleIds[] = (int) $role->id;
        }

        return $roleIds === [] ? [] : MenuModel::getMenuByRoleId($roleIds);
    }

    /**
     * 工作台快捷入口（框架功能 + 插件图标分组）
     *
     * @return array<int, array<string, mixed>>
     */
    public function entries(Admin $admin): array
    {
        return MenuLauncherService::entries($this->menus($admin));
    }
}
