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
     * @param bool   $purge       是否物理删除（true=无痕：连已软删的行一并 force delete，
     *                            重装不会 restore；false=软删可恢复）
     * @return bool
     */
    public function unregister(string $pluginIdent, bool $purge = false): bool
    {
        try {
            $permissions = $purge
                ? Permission::withTrashed()->where('plugin', $pluginIdent)->select()
                : Permission::where('plugin', $pluginIdent)->select();

            $permissionIds = [];

            foreach ($permissions as $permission) {
                $permissionIds[] = (int) $permission->getAttr('id');
            }

            Db::transaction(function () use ($permissions, $permissionIds, $purge) {
                if (!empty($permissionIds)) {
                    Db::name('auth_role_permission')
                        ->whereIn('permission_id', $permissionIds)
                        ->delete();
                }

                foreach ($permissions as $permission) {
                    if ($purge) {
                        $permission->force()->delete();
                    } else {
                        $permission->delete();
                    }
                }
            });

            if ($purge) {
                $this->clearRbacCache();
            }

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

    /**
     * 清 RBAC 权限缓存（权限/角色绑定被物理删除后必须失效）
     */
    protected function clearRbacCache(): void
    {
        try {
            app(\wise\auth\service\RbacManager::class)->clearAllCache();
        } catch (\Throwable $e) {
        }
    }
}
