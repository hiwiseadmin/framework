<?php

declare(strict_types=1);

namespace wise\plugin\adapter;

use wise\auth\facade\Perm;
use wise\auth\model\Permission;
use wise\plugin\scanner\PluginMetadata;
use think\facade\Db;
use wise\support\context\ContextualLogger;

/**
 * 权限注册器 — 将插件的独立权限定义写入框架权限系统。
 *
 * 处理两类权限：
 * 1. 独立权限（type != 'menu'）：通过 Perm::registerPermission() 注册
 * 2. 菜单派生权限：由 MenuRegistrar（MenuPermissionBridge）自动处理
 */
class PermissionRegistrar
{
    /**
     * 注册插件独立权限（非菜单派生）。
     *
     * @param PluginMetadata $metadata 插件元数据
     * @return void
     */
    public function register(PluginMetadata $metadata): void
    {
        $permissions = $metadata->getPermissions();
        if (empty($permissions)) {
            return;
        }

        $pluginIdent = $metadata->getIdent();
        $standalonePerms = array_filter($permissions, function ($p) {
            return ($p['type'] ?? 'custom') !== 'menu';
        });

        if (empty($standalonePerms)) {
            return;
        }

        try {
            foreach ($standalonePerms as $perm) {
                $perm['plugin'] = $pluginIdent;
                Perm::registerPermission($perm);
            }
        } catch (\Throwable $e) {
            ContextualLogger::error(sprintf(
                '插件 "%s" 权限注册失败: %s',
                $pluginIdent,
                $e->getMessage()
            ));
            throw $e;
        }
    }

    /**
     * 删除插件所有权限及关联的角色绑定。
     *
     * @param string $pluginIdent 插件标识
     * @return bool
     */
    public function unregister(string $pluginIdent): bool
    {
        try {
            // ⚠️ 必须先把模型取回来、再逐条 delete()，不能用静态查询链
            //     `Permission::where(...)->delete()` —— 该写法在软删模型上
            //     **不触发软删**（实测 affected = 0，行原封不动保留）。
            //     同样的坑也适用于其它 `use SoftDelete` 的模型。
            $permissions = Permission::where('plugin', $pluginIdent)->select();

            $permissionIds = [];

            foreach ($permissions as $permission) {
                $permissionIds[] = (int) $permission->getAttr('id');
            }

            Db::transaction(function () use ($permissions, $permissionIds) {
                if (!empty($permissionIds)) {
                    // 清除角色-权限关联（role_permission 中间表）
                    // ⚠️ 必须用 Db::name()：Db::table() 不做表前缀解析，会拼成
                    //     `auth_role_permission`（缺 `ws_`）→ 表不存在抛异常 →
                    //     事务整体回滚 → 权限行根本删不掉（2026-09-28 修复）。
                    Db::name('auth_role_permission')
                        ->whereIn('permission_id', $permissionIds)
                        ->delete();
                }

                foreach ($permissions as $permission) {
                    $permission->delete();
                }
            });

            return true;
        } catch (\Throwable $e) {
            ContextualLogger::warning(sprintf(
                '插件 "%s" 权限删除失败: %s',
                $pluginIdent,
                $e->getMessage()
            ));
            return false;
        }
    }
}
