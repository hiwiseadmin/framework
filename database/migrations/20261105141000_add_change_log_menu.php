<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/**
 * 变更记录＼�菜单 + 权限补种
 *
 * 与回收站迁移同一套做法：独立补种，不回改早已执行过的 create_menus_table；
 * 菜单 → 权限走 Menu::createMenus + syncPermissionForMenu（与安装期同源）。
 *
 * ⚠️ 两个约定：
 *   1. action 名一律小写（RbacMiddleware::getDispatchedRoute() 会全小写后匹配）；
 *   2. 页面接口全部用 POST（前端统一 POST + CSRF），而 MenuPermissionBridge 的方法推断
 *      只认 save/update/delete/remove/store/create/edit，其余默认 GET —— 因此必须用
 *      methodOverrides 显式纠正，否则非超管会 403。
 */
class AddChangeLogMenu extends Migrator
{
    /** @var array<int, array<string, mixed>> */
    private array $menuItems = [
        ['name' => '变更记录', 'icon' => 'bi bi-clock-history', 'path' => '/admin/change_log/index', 'type' => 'menu', 'sort' => 21],
        ['name' => '变更列表', 'path' => '/admin/change_log/getlist', 'type' => 'button'],
        ['name' => '变更模型', 'path' => '/admin/change_log/getmodels', 'type' => 'button'],
        ['name' => '变更时间线', 'path' => '/admin/change_log/timeline', 'type' => 'button'],
        ['name' => '签发回滚令牌', 'path' => '/admin/change_log/issuetoken', 'type' => 'button'],
        ['name' => '回滚变更', 'path' => '/admin/change_log/rollback', 'type' => 'button'],
    ];

    /** 需要显式纠正 HTTP 方法的动作 */
    private array $methodOverrides = [
        'getlist'    => 'POST',
        'getmodels'  => 'POST',
        'timeline'   => 'POST',
        'issuetoken' => 'POST',
        'rollback'   => 'POST',
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
                    'AddChangeLogMenu: 菜单/权限补种失败（可稍后手动补建）- ' . $e->getMessage(),
                    ['migration', 'change_log']
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
            $ident = $bridge->generateIdent('/admin/change_log/' . $action);
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
        } catch (\Throwable) {
        }
    }
}
