<?php
declare(strict_types=1);

namespace app\common\security;

/**
 * 后台安全访问策略（纯函数，无框架依赖）
 *
 * 用于把「能否操作管理员 / 角色 / 权限 / 私有配置 / 他人任务」的判定
 * 从控制器中抽出来，便于单测与契约自检复用。
 *
 * 约定：所有方法均为 fail-closed——参数不完整或越权时返回 false。
 */
final class AccessPolicy
{
    /**
     * 是否允许管理目标管理员
     *
     * 超管可管理任何人；非超管只能管理自己。
     */
    public static function canManageAdmin(bool $isSuperAdmin, int $currentAdminId, int $targetAdminId): bool
    {
        if ($isSuperAdmin) {
            return true;
        }

        return $currentAdminId > 0 && $currentAdminId === $targetAdminId;
    }

    /**
     * 是否允许分配指定角色
     *
     * 非超管不得分配超级管理员角色；超管不受限制。
     *
     * @param array<int, string> $roleSlugs
     */
    public static function canAssignRoles(bool $isSuperAdmin, array $roleSlugs, string $superRoleSlug): bool
    {
        if ($isSuperAdmin) {
            return true;
        }

        foreach ($roleSlugs as $slug) {
            if ((string) $slug === $superRoleSlug) {
                return false;
            }
        }

        return true;
    }

    /**
     * 是否允许编辑目标角色
     *
     * 非超管不得编辑超级管理员角色，也不得编辑自己所属角色
     * （否则可把自己的角色刷成全部权限）。
     *
     * @param array<int, int|string> $currentRoleIds
     */
    public static function canEditRole(
        bool $isSuperAdmin,
        array $currentRoleIds,
        int $targetRoleId,
        string $targetRoleSlug,
        string $superRoleSlug
    ): bool {
        if ($isSuperAdmin) {
            return true;
        }

        if ($targetRoleSlug === $superRoleSlug) {
            return false;
        }

        foreach ($currentRoleIds as $roleId) {
            if ((int) $roleId === $targetRoleId) {
                return false;
            }
        }

        return true;
    }

    /**
     * 是否允许授予指定权限
     *
     * 非超管只能授予自己已经持有的权限 ID（含继承展开后的 ID）。
     *
     * @param array<int, int|string> $currentPermissionIds
     * @param array<int, int|string> $requestedPermissionIds
     */
    public static function canGrantPermissions(
        bool $isSuperAdmin,
        array $currentPermissionIds,
        array $requestedPermissionIds
    ): bool {
        if ($isSuperAdmin) {
            return true;
        }

        $owned = array_map('intval', $currentPermissionIds);
        foreach ($requestedPermissionIds as $permissionId) {
            if (!in_array((int) $permissionId, $owned, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 是否允许查看/操作指定可见性的配置项
     *
     * visibility=0 为私有配置，仅超管可读写。
     */
    public static function canAccessConfig(bool $isSuperAdmin, int $visibility): bool
    {
        return $isSuperAdmin || $visibility !== 0;
    }

    /**
     * 是否允许访问指定异步任务
     *
     * 超管可访问全部；非超管只能访问自己创建的任务。
     */
    public static function canAccessTask(bool $isSuperAdmin, int $currentAdminId, int $ownerId): bool
    {
        if ($isSuperAdmin) {
            return true;
        }

        return $currentAdminId > 0 && $ownerId === $currentAdminId;
    }

    /**
     * 是否允许修改权限节点
     *
     * 权限节点决定整个 RBAC 的边界，只允许超管变更。
     */
    public static function canMutatePermission(bool $isSuperAdmin): bool
    {
        return $isSuperAdmin;
    }
}
