<?php
declare(strict_types=1);

namespace app\install\service;

use app\admin\model\Menu;
use app\common\service\PermissionSyncRegistry;
use wise\auth\service\PermissionRegistrar;

/**
 * 权限初始化服务
 *
 * 安装期将三类来源的权限生成到 auth_permissions 表：
 *   1. 菜单派生权限：复用 MenuPermissionBridge（菜单→权限的唯一翻译层），
 *      按 id 升序遍历系统菜单（父先子后），对每个有 path 的菜单幂等生成权限。
 *   2. 控制器 Attribute 派生权限：复用 wise-auth 内置 PermissionRegistrar（无需自写反射扫描器），
 *      按 config/wise-auth.php 的 permission_sources / attribute_dirs 扫描 #[AuthPermission] 落库。
 *   3. 包 / 插件自声明权限：通过扩展点 PERMISSION_SYNC_PROVIDER 遍历各包注册的
 *      权限同步提供者统一收集并落库，
 *      使超级管理员之外的角色也能在后台按角色授予这些权限。
 *
 * 幂等性：
 *   - 菜单→权限：Perm::registerPermission 以 ident 唯一键 upsert。
 *   - Attribute→权限：Permission::loadFromConfig 以 ident 唯一键 upsert。
 *   - 包自声明→权限：各提供者内部以 ident 唯一键 upsert。
 */
class PermissionInitService
{
    /**
     * 系统菜单在 menus 表中的 plugin 标识（迁移 create_menus_table 硬编码为 'system'）
     */
    private const SYSTEM_PLUGIN = 'system';

    /**
     * 生成权限列表
     *
     * @return array{menus:int, attributes:int, ai_tools:int} menus=处理的菜单权限数，attributes=Attribute 注册的权限数，ai_tools=各包自声明权限数
     */
    public static function build(): array
    {
        $menuCount       = self::syncMenuPermissions();
        $attributeCount  = self::syncAttributePermissions();
        $packageCount    = self::syncPackagePermissions();

        return [
            'menus'      => $menuCount,
            'attributes' => $attributeCount,
            'ai_tools'   => $packageCount,
        ];
    }

    /**
     * 各包 / 插件自声明权限 → 权限表
     *
     * 通过扩展点 PERMISSION_SYNC_PROVIDER 统一驱动，宿主不感知任何具体包
     * （各包自有的权限注册器均走此链路）；
     * 未安装任何提供者时返回 0，不阻断安装流程。
     *
     * @return int 注册的权限数量（created + updated）
     */
    protected static function syncPackagePermissions(): int
    {
        try {
            $sync = PermissionSyncRegistry::run();

            return $sync['created'] + $sync['updated'];
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 菜单 → 权限
     *
     * 按 id 升序遍历系统菜单（父先子后），确保子菜单能解析到父菜单已回写的 permission_id。
     * 无 path 的纯目录菜单不生成权限（由 MenuPermissionBridge 内部处理）。
     *
     * @return int 处理的（有 path 的）菜单数量
     */
    protected static function syncMenuPermissions(): int
    {
        $count = 0;

        $menus = Menu::where('plugin', self::SYSTEM_PLUGIN)
            ->order('id', 'asc')
            ->select();

        foreach ($menus as $menu) {
            if (empty($menu->getAttr('path'))) {
                continue;
            }
            Menu::syncPermissionForMenu($menu);
            $count++;
        }

        return $count;
    }

    /**
     * 控制器 Attribute → 权限
     *
     * 直接复用 wise-auth 内置 PermissionRegistrar::register()：
     * 遍历 config('wise-auth.permission_sources')，其中 attribute 分支会扫描
     * attribute_dirs 下的控制器并按 #[AuthPermission] 声明落库。
     *
     * @return int 注册的权限数
     */
    protected static function syncAttributePermissions(): int
    {
        $registrar = app(PermissionRegistrar::class);
        return $registrar->register();
    }
}
