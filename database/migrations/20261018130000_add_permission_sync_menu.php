<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/**
 * 后台「同步权限」入口的菜单与权限节点
 *
 * 背景：
 *   - 「权限配置」页的工具栏按钮「更新权限」实际请求的是
 *     /admin/permission/updateFromMenuActionApi，但菜单里只有
 *     /admin/permission/update（编辑单条权限的提交动作），
 *     导致该接口没有权限节点 —— 非超管点击必然 403。
 *   - 这里补一条独立的按钮节点（不改动原 update 节点，它仍服务于编辑表单），
 *     并把授权同步给「原本就能编辑权限」的角色，保证行为不回退。
 */
class AddPermissionSyncMenu extends Migrator
{
    /** 同步接口路径（同时作为菜单 path 与权限 route） */
    private const SYNC_PATH = '/admin/permission/updateFromMenuActionApi';

    /** 权限 ident（path_to_dot 策略：Str::snake + 点号） */
    private const SYNC_IDENT = 'admin.permission.update_from_menu_action_api';

    public function up(): void
    {
        $parentMenuId = Db::name('menus')
            ->where('path', '/admin/permission/index')
            ->whereNull('delete_time')
            ->value('id');

        if (!$parentMenuId) {
            // 宿主未安装标准权限菜单，跳过
            return;
        }

        $parentPermId = (int) Db::name('menus')->where('id', $parentMenuId)->value('permission_id');

        // 1) 权限节点
        $permId = Db::name('auth_permissions')->where('ident', self::SYNC_IDENT)->value('id');

        if (!$permId) {
            $permId = Db::name('auth_permissions')->insertGetId([
                'ident'       => self::SYNC_IDENT,
                'name'        => '同步权限',
                'description' => '按菜单/扩展包声明同步权限节点',
                'type'        => 'route',
                'route'       => self::SYNC_PATH,
                'method'      => 'POST',
                'parent_id'   => $parentPermId,
                'plugin'      => 'system',
                'source'      => 'database',
                'sort'        => 0,
                'status'      => 1,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        }

        // 2) 菜单按钮（幂等）
        $menuId = Db::name('menus')
            ->where('path', self::SYNC_PATH)
            ->where('parent_id', $parentMenuId)
            ->value('id');

        if (!$menuId) {
            $menuId = Db::name('menus')->insertGetId([
                'parent_id'     => $parentMenuId,
                'name'          => '同步权限',
                'path'          => self::SYNC_PATH,
                'type'          => 'button',
                'plugin'        => 'system',
                'sort'          => 0,
                'status'        => 1,
                'permission_id' => $permId,
                'create_time'   => date('Y-m-d H:i:s'),
            ]);
        } else {
            Db::name('menus')->where('id', $menuId)->update([
                'permission_id' => $permId,
                'update_time'   => date('Y-m-d H:i:s'),
            ]);
        }

        // 3) 授权继承：原本能「更新权限」（编辑单条）的角色，同样获得同步入口
        $legacyPermId = Db::name('auth_permissions')
            ->where('route', '/admin/permission/update')
            ->where('type', 'route')
            ->value('id');

        if ($legacyPermId) {
            $roleIds = Db::name('auth_role_permission')
                ->where('permission_id', $legacyPermId)
                ->column('role_id');

            foreach (array_unique(array_map('intval', $roleIds)) as $roleId) {
                if ($roleId <= 0) {
                    continue;
                }

                $exists = Db::name('auth_role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permId)
                    ->find();

                if (!$exists) {
                    Db::name('auth_role_permission')->insert([
                        'role_id'       => $roleId,
                        'permission_id' => $permId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $permId = Db::name('auth_permissions')->where('ident', self::SYNC_IDENT)->value('id');

        if ($permId) {
            Db::name('auth_role_permission')->where('permission_id', $permId)->delete();
            Db::name('auth_permissions')->where('id', $permId)->delete();
        }

        Db::name('menus')->where('path', self::SYNC_PATH)->delete();
    }
}
