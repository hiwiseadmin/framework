<?php
declare(strict_types=1);

use app\admin\model\Menu;
use app\admin\service\MenuPermissionBridge;
use think\facade\Db;
use think\migration\Migrator;
use wise\auth\service\RbacManager;

/**
 * 控制器内回收站：菜单模块的按钮权限节点（样板）
 *
 * 背景：回收站能力已并入 `CrudAction`，控制器写 4 行 public 方法即可启用：
 *
 * ```php
 * public function recycleList()        { return $this->_recycleList(); }
 * public function recycleToken()       { return $this->_recycleToken(); }
 * public function recycleRestore()     { return $this->_restore(); }
 * public function recycleForceDelete() { return $this->_forceDelete(true); }
 * ```
 *
 * ⚠️ 新增路由必须在 menus 表里落节点（权限由菜单派生，框架不扫描控制器）：
 * RBAC 默认 `default_mode=whitelist`，未匹配到权限节点的路由对非超管一律 403。
 * 其它控制器接入回收站时，照抄本迁移改 4 个 path 即可。
 *
 * ⚠️ action 名一律小写：RbacMiddleware 会把 dispatched route 全小写后匹配权限 route。
 */
class AddMenuRecyclePermissions extends Migrator
{
    /** @var array<int, array<string, mixed>> */
    private array $buttons = [
        ['name' => '回收站列表',     'path' => '/admin/menu/recycleList',        'type' => 'button', 'sort' => 900],
        ['name' => '回收站确认令牌', 'path' => '/admin/menu/recycleToken',       'type' => 'button', 'sort' => 901],
        ['name' => '回收站还原',     'path' => '/admin/menu/recycleRestore',     'type' => 'button', 'sort' => 902],
        ['name' => '回收站彻底删除', 'path' => '/admin/menu/recycleForceDelete', 'type' => 'button', 'sort' => 903],
    ];

    /** 父菜单：菜单管理入口（按钮节点挂其下，不参与导航渲染） */
    private const PARENT_PATH = '/admin/menu/index';

    private const CATALOG_NAME = '系统工具';

    public function up(): void
    {
        $this->seed();
    }

    public function down(): void
    {
        $this->remove();
    }

    private function seed(): void
    {
        if (!$this->hasTable('menus')) {
            return;
        }

        try {
            $parentId = $this->resolveParentId();
            if ($parentId === null) {
                return;
            }

            Menu::createMenus('system', $this->buttons, $parentId);

            if (!$this->hasTable('auth_permissions')) {
                return;
            }

            $paths = array_column($this->buttons, 'path');
            $menus = Menu::where('plugin', 'system')->whereIn('path', $paths)->select();

            foreach ($menus as $menu) {
                Menu::syncPermissionForMenu($menu);
            }

            $this->clearRouteCache();
        } catch (\Throwable $e) {
            try {
                \wise\support\context\ContextualLogger::warning(
                    'AddMenuRecyclePermissions: 按钮权限补种失败（可稍后手动补建）- ' . $e->getMessage(),
                    ['migration', 'recycle']
                );
            } catch (\Throwable) {
            }
        }
    }

    /**
     * 解析按钮节点的父菜单：优先「菜单管理」入口，缺失时退回「系统工具」容器
     */
    private function resolveParentId(): ?int
    {
        $parent = Menu::withTrashed()->where('path', self::PARENT_PATH)->find();

        if ($parent) {
            return (int) $parent->getAttr('id');
        }

        $rootId = Menu::resolveConsoleRoot();
        if ($rootId === null) {
            return null;
        }

        $container = Menu::ensureContainer($rootId, 'system', self::CATALOG_NAME, [
            'icon' => 'bi bi-tools',
            'sort' => 750,
        ]);

        return isset($container['id']) ? (int) $container['id'] : null;
    }

    private function clearRouteCache(): void
    {
        try {
            app(RbacManager::class)->clearRouteMatchCache();
        } catch (\Throwable) {
        }
    }

    private function remove(): void
    {
        if (!$this->hasTable('menus')) {
            return;
        }

        try {
            $paths = array_column($this->buttons, 'path');

            if ($this->hasTable('auth_permissions')) {
                $bridge = app(MenuPermissionBridge::class);
                $idents = array_map(static fn (string $path): string => $bridge->generateIdent($path), $paths);
                Db::name('auth_permissions')->whereIn('ident', $idents)->delete(true);
            }

            Db::name('menus')->where('plugin', 'system')->whereIn('path', $paths)->delete(true);

            $this->clearRouteCache();
        } catch (\Throwable) {
        }
    }
}
