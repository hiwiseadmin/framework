<?php
declare(strict_types=1);

namespace app\admin\service;

use app\admin\model\Menu;
use app\common\service\PermissionSyncRegistry;
use wise\auth\facade\Rbac;
use wise\auth\model\Permission as PermissionModel;

/**
 * 权限同步服务 —— 「菜单 → 权限」的唯一实现
 *
 * 设计约束（Phase 3）：
 *   - **只做下游转换**：输入是 `menus` 表（人工/迁移/包声明落库后的结果），
 *     不扫描控制器、不创建菜单；
 *   - 网页（Permission::updateFromMenuActionApi）与 CLI（menu:permission-sync）
 *     都是本服务的**薄适配器**，避免两套逻辑漂移；
 *   - 菜单派生规则完全复用 {@see MenuPermissionBridge}（ident/route/parent/method 同源）。
 *
 * 职责边界：
 *   - syncMenus()     菜单 → 权限（幂等）
 *   - syncPackages()  各包 / 插件声明 → 权限（委托 PermissionSyncRegistry）
 *   - inspectDrift()  只读漂移报告（不修复）
 */
class PermissionSyncService
{
    /**
     * 菜单 → 权限
     *
     * @param bool   $dryRun     只统计不写库
     * @param string $pathPrefix 只处理指定 path 前缀（CLI `--path=`）
     * @param bool   $fixMethod  用当前配置的 method 覆盖已存在权限行（修历史错误）
     * @return array{dry_run:bool, scanned:int, synced:int, skipped:int, failed:int, errors:array<int,string>}
     */
    public function syncMenus(bool $dryRun = false, string $pathPrefix = '', bool $fixMethod = false): array
    {
        $bridge = new MenuPermissionBridge();
        $stat   = [
            'dry_run' => $dryRun,
            'scanned' => 0,
            'synced'  => 0,
            'skipped' => 0,
            'failed'  => 0,
            'errors'  => [],
        ];

        $query = Menu::withTrashed(false)
            ->where('status', 1)
            ->order('sort', 'asc');

        if ($pathPrefix !== '') {
            $query->where('path', 'like', rtrim($pathPrefix, '%') . '%');
        }

        foreach ($query->select() as $menu) {
            $stat['scanned']++;

            if (trim((string) $menu->getAttr('path')) === '') {
                $stat['skipped']++;
                continue;
            }

            if ($dryRun) {
                $stat['synced']++;
                continue;
            }

            try {
                $bridge->syncPermissionForMenu($menu, $fixMethod);
                $stat['synced']++;
            } catch (\Throwable $e) {
                $stat['failed']++;
                if (count($stat['errors']) < 5) {
                    $stat['errors'][] = (string) $menu->getAttr('name') . ': ' . $e->getMessage();
                }
            }
        }

        if (!$dryRun) {
            Rbac::clearAllCache();
        }

        return $stat;
    }

    /**
     * 各包 / 插件声明 → 权限
     *
     * @return array{created:int, updated:int, deleted:int, providers:int, errors:array<int,string>}
     */
    public function syncPackages(bool $dryRun = false, bool $prune = false): array
    {
        $sync = PermissionSyncRegistry::run($dryRun, $prune);

        return [
            'created'   => (int) $sync['created'],
            'updated'   => (int) $sync['updated'],
            'deleted'   => (int) $sync['deleted'],
            'providers' => (int) $sync['providers'],
            'errors'    => (array) $sync['errors'],
        ];
    }

    /**
     * 统一编排（供网页与 CLI 共用）
     *
     * @param array{menus?:bool, packages?:bool, dry_run?:bool, prune?:bool, path?:string, fix_method?:bool} $options
     * @return array{menus:array|null, packages:array|null, prune:array|null, drift:array}
     */
    public function sync(array $options = []): array
    {
        $dryRun    = (bool) ($options['dry_run'] ?? false);
        $path      = (string) ($options['path'] ?? '');
        $fixMethod = (bool) ($options['fix_method'] ?? false);
        $prune     = (bool) ($options['prune'] ?? false);

        $result = [
            'menus'    => null,
            'packages' => null,
            'prune'    => null,
            'drift'    => null,
        ];

        if (!empty($options['menus'])) {
            $result['menus'] = $this->syncMenus($dryRun, $path, $fixMethod);
        }

        if ($prune) {
            $result['prune'] = $this->pruneOrphans($dryRun, $path);
        }

        if (!empty($options['packages'])) {
            $result['packages'] = $this->syncPackages($dryRun, $prune);
        }

        $result['drift'] = $this->inspectDrift();

        return $result;
    }

    /**
     * 回收「菜单已删除、权限仍残留」的节点
     *
     * 安全边界（避免误删）：
     *   - 只取 **软删除** 的菜单 path，且该 path 当前没有任何未删除菜单在用；
     *   - 只按 `path_to_dot` 精确匹配权限 ident，不碰通配权限、不碰插件/AI 权限；
     *   - 软删除权限行（保留审计与角色关联痕迹），不做物理删除。
     *
     * @param bool   $dryRun     只统计不写库
     * @param string $pathPrefix 只处理该前缀下的菜单（CLI `--path=`）
     * @return array{dry_run:bool, scanned:int, pruned:int, skipped:int, samples:array<int,string>, errors:array<int,string>}
     */
    public function pruneOrphans(bool $dryRun = false, string $pathPrefix = ''): array
    {
        $stat = [
            'dry_run' => $dryRun,
            'scanned' => 0,
            'pruned'  => 0,
            'skipped' => 0,
            'samples' => [],
            'errors'  => [],
        ];

        $bridge    = new MenuPermissionBridge();
        $identity  = static fn(string $path): string => $bridge->normalizer()->identity($path);

        $trashedQuery = Menu::onlyTrashed()->where('path', '<>', '');
        if ($pathPrefix !== '') {
            $trashedQuery->where('path', 'like', rtrim($pathPrefix, '%') . '%');
        }

        $trashedPaths = $trashedQuery->column('path');

        $activePaths = Menu::where('path', '<>', '')->column('path');
        $active      = [];
        foreach ($activePaths as $path) {
            $active[$identity((string) $path)] = true;
        }

        $seen = [];

        foreach ($trashedPaths as $path) {
            $stat['scanned']++;

            $pathIdentity = $identity((string) $path);

            if (isset($seen[$pathIdentity])) {
                $stat['skipped']++;
                continue;
            }
            $seen[$pathIdentity] = true;

            if (isset($active[$pathIdentity])) {
                $stat['skipped']++;
                continue;
            }

            $ident = $bridge->generateIdent((string) $path);
            if ($ident === '') {
                $stat['skipped']++;
                continue;
            }

            $permission = PermissionModel::where('ident', $ident)->find();
            if (!$permission) {
                $stat['skipped']++;
                continue;
            }

            if ($dryRun) {
                $stat['pruned']++;
                if (count($stat['samples']) < 5) {
                    $stat['samples'][] = $ident;
                }
                continue;
            }

            try {
                $permission->delete();
                $stat['pruned']++;
                if (count($stat['samples']) < 5) {
                    $stat['samples'][] = $ident;
                }
            } catch (\Throwable $e) {
                $stat['errors'][] = $ident . ': ' . $e->getMessage();
            }
        }

        if (!$dryRun && $stat['pruned'] > 0) {
            Rbac::clearAllCache();
        }

        return $stat;
    }

    /**
     * 权限与菜单的漂移概览（只读）
     *
     * @return array{orphan_count:int,orphan_samples:array,unbound_count:int,unbound_samples:array}
     */
    public function inspectDrift(): array
    {
        $menuPaths = Menu::withTrashed(false)
            ->where('status', 1)
            ->where('path', '<>', '')
            ->column('path');

        $normalize = static fn($path) => strtolower(rtrim((string) $path, '/'));

        $pathIndex = [];
        foreach ($menuPaths as $path) {
            $pathIndex[$normalize($path)] = true;
        }

        $routes  = PermissionModel::where('status', 1)->where('type', 'route')->column('route');
        $orphans = [];
        foreach ($routes as $route) {
            $route = (string) $route;
            if (trim($route) === '') {
                continue;
            }

            if (str_contains($route, '*')) {
                continue;
            }

            if (!isset($pathIndex[$normalize($route)])) {
                $orphans[] = $route;
            }
        }

        $unbound = Menu::withTrashed(false)
            ->where('status', 1)
            ->where('path', '<>', '')
            ->where(function ($query) {
                $query->whereNull('permission_id')->whereOr('permission_id', 0);
            })
            ->column('name');

        return [
            'orphan_count'    => count($orphans),
            'orphan_samples'  => array_slice($orphans, 0, 5),
            'unbound_count'   => count($unbound),
            'unbound_samples' => array_slice($unbound, 0, 5),
        ];
    }
}
