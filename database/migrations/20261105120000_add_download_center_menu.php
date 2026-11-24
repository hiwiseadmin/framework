<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/**
 * 下载中心＼�菜单 + 权限补种
 *
 * 与 `20261016120000_create_async_tasks_table` 同一套做法：
 *   - 独立迁移补种，避免回改早已执行过的 `create_menus_table`；
 *   - 菜单 → 权限走 `Menu::createMenus` + `syncPermissionForMenu`（与安装期同源）；
 *   - `path_suffix` 方法推断只认 save/update/delete/remove/store/create/edit，
 *     故 delete / clear 需要显式纠正为 POST（否则非超管 403）。
 */
class AddDownloadCenterMenu extends Migrator
{
    /** @var array<int, array<string, mixed>> */
    private array $menuItems = [
        ['name' => '下载中心', 'icon' => 'bi bi-download', 'path' => '/admin/download/index', 'type' => 'menu', 'sort' => 10],
        ['name' => '导出任务列表', 'path' => '/admin/download/getlist', 'type' => 'button'],
        ['name' => '下载导出文件', 'path' => '/admin/download/file', 'type' => 'button'],
        ['name' => '删除导出任务', 'path' => '/admin/download/delete', 'type' => 'button'],
        ['name' => '清理过期导出', 'path' => '/admin/download/clear', 'type' => 'button'],
    ];

    /** 需要显式纠正 HTTP 方法的动作 */
    private array $methodOverrides = [
        'delete' => 'POST',
        'clear'  => 'POST',
    ];

    /** 系统目录名（收纳本页） */
    private const CATALOG_NAME = '系统工具';

    public function up(): void
    {
        $this->seedMenus();
    }

    public function down(): void
    {
        $this->removeMenus();
    }

    /**
     * 补种「系统工具 / 下载中心」菜单，并派生权限节点
     *
     * 全程 try/catch：迁移不应因权限子系统尚未就绪而阻断；菜单缺失时可后续手动补建。
     */
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
                    'AddDownloadCenterMenu: 菜单/权限补种失败（可稍后手动补建）- ' . $e->getMessage(),
                    ['migration', 'download']
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
            $ident = $bridge->generateIdent('/admin/download/' . $action);
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
