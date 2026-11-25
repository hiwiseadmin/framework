<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/**
 * 回收站＼�菜单 + 权限补种
 *
 * 与下载中心迁移同一套做法：独立补种，避免回改早已执行过的 create_menus_table；
 * 菜单 → 权限走 Menu::createMenus + syncPermissionForMenu（与安装期同源）。
 *
 * ⚠️ action 名一律小写：RbacMiddleware::getDispatchedRoute() 会把 controller/action
 * 全小写后匹配权限 route，camelCase 声明会导致「预分派匹配、分派后不匹配」。
 */
class AddRecycleMenu extends Migrator
{
    /** @var array<int, array<string, mixed>> */
    private array $menuItems = [
        ['name' => '回收站', 'icon' => 'bi bi-recycle', 'path' => '/admin/recycle/index', 'type' => 'menu', 'sort' => 20],
        ['name' => '回收站模型', 'path' => '/admin/recycle/getmodels', 'type' => 'button'],
        ['name' => '回收站列表', 'path' => '/admin/recycle/getlist', 'type' => 'button'],
        ['name' => '签发确认令牌', 'path' => '/admin/recycle/issuetoken', 'type' => 'button'],
        ['name' => '还原记录', 'path' => '/admin/recycle/restore', 'type' => 'button'],
        ['name' => '彻底删除', 'path' => '/admin/recycle/forcedelete', 'type' => 'button'],
        ['name' => '清空模块', 'path' => '/admin/recycle/clear', 'type' => 'button'],
    ];

    /** 需要显式纠正 HTTP 方法的动作（path_suffix 推断只认 save/update/delete/remove/store/create/edit） */
    private array $methodOverrides = [
        'issuetoken' => 'POST',
        'restore'    => 'POST',
        'forcedelete' => 'POST',
        'clear'      => 'POST',
    ];

    private const CATALOG_NAME = '系统工具';

    public function up(): void
    {
        $this->seedMenus();
    }

    public function down(): void
    {
        $this->removeMenus();
    }

    private function seedMenus(): void
    {
        if (!$this->hasTable('menus')) {
            return;
        }

        try {
            $menuModel = '\\app\\admin\\model\\Menu';

            $consoleRootId = $menuModel::resolveConsoleRoot();
            if ($consoleRootId === null) {
                return;
            }

            $container = $menuModel::ensureContainer($consoleRootId, 'system', self::CATALOG_NAME, [
                'icon' => 'bi bi-tools',
                'sort' => 750,
            ]);
            $catalogId = (int) $container['id'];

            $menuModel::createMenus('system', $this->menuItems, $catalogId);

            if (!$this->hasTable('auth_permissions')) {
                return;
            }

            $paths = array_column($this->menuItems, 'path');
            $menus = $menuModel::where('plugin', 'system')->whereIn('path', $paths)->select();

            foreach ($menus as $menu) {
                $menuModel::syncPermissionForMenu($menu);
            }

            $this->fixPermissionMethods();
            $this->clearRouteCache();
        } catch (\Throwable $e) {
            try {
                \wise\support\context\ContextualLogger::warning(
                    'AddRecycleMenu: 菜单/权限补种失败（可稍后手动补建）- ' . $e->getMessage(),
                    ['migration', 'recycle']
                );
            } catch (\Throwable) {
            }
        }
    }

    private function fixPermissionMethods(): void
    {
        if (!$this->hasTable('auth_permissions')) {
            return;
        }

        $bridge = app(\app\admin\service\MenuPermissionBridge::class);

        foreach ($this->methodOverrides as $action => $method) {
            $ident = $bridge->generateIdent('/admin/recycle/' . $action);
            Db::name('auth_permissions')->where('ident', $ident)->update(['method' => $method]);
        }
    }

    private function clearRouteCache(): void
    {
        try {
            app(\wise\auth\service\RbacManager::class)->clearRouteMatchCache();
        } catch (\Throwable) {
        }
    }

    private function removeMenus(): void
    {
        if (!$this->hasTable('menus')) {
            return;
        }

        try {
            $paths = array_column($this->menuItems, 'path');

            if ($this->hasTable('auth_permissions')) {
                $bridge = app(\app\admin\service\MenuPermissionBridge::class);
                $idents = array_map(static fn ($path) => $bridge->generateIdent($path), $paths);
                Db::name('auth_permissions')->whereIn('ident', $idents)->delete(true);
            }

            Db::name('menus')->where('plugin', 'system')->whereIn('path', $paths)->delete(true);

            $catalog = Db::name('menus')
                ->where('plugin', 'system')
                ->where('name', self::CATALOG_NAME)
                ->where('type', 'dir')
                ->find();

            if ($catalog) {
                $children = Db::name('menus')->where('parent_id', (int) $catalog['id'])->count();
                if ($children === 0) {
                    Db::name('menus')->where('id', (int) $catalog['id'])->delete(true);
                }
            }
        } catch (\Throwable $e) {
        }
    }
}
