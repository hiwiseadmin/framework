<?php

declare(strict_types=1);

namespace wise\plugin\adapter;

use app\admin\model\Menu;
use app\admin\service\MenuItemSync;
use wise\plugin\scanner\PluginMetadata;
use wise\support\context\ContextualLogger;
use wise\support\menu\MenuSchema;

/**
 * 菜单注册器 — 将插件的菜单定义写入框架菜单系统。
 *
 * 展示模型（不再由 manifest 主动声明展示形式，改为按菜单结构自动分流）：
 *   顶部导航四部分：控制台 / 插件扩展 / 常用插件区 / 启动台
 *
 *   分流规则（按插件「顶级菜单数量」）：
 *   - 顶级菜单 = 1 个  → 进入「插件扩展」：
 *       · 若该顶级是 dir → 直接作为入口平铺到「插件扩展」；
 *       · 若该顶级是 menu → 自动包一层「插件名」dir 作为入口；
 *       · 这类插件不能 pinned。
 *   - 顶级菜单 >= 2 个 → 进入「启动台」，由用户主动 pinned 才进入「常用插件区」。
 *   - 所有插件同时进入「启动台」（统一入口）。
 *
 * 复用 MenuPermissionBridge 自动同步菜单和权限。
 *
 * 覆盖策略：**默认保留已存在菜单的字段**（尊重运营在后台的改名/排序/停用），
 * 只有显式 `register($metadata, force: true)` 才以 manifest 为准覆盖 ——
 * 与库包链路 `MenuItemSync`（`!$force`）统一，见
 *
 * 删除策略：`register()` **从不删除**任何菜单，因此插件作者把菜单从 manifest 拿掉后
 * 库里的旧节点需要显式清理 —— 见 {@see self::prune()}（软删可恢复，与库包链路
 * `menu:sync --prune` 共用同一差集实现）。
 */
class MenuRegistrar
{
    /**
     * 将 manifest 的 mount 字符串映射为 Menu 模型常量。
     *
     * @param string $mount
     * @return int
     */
    public function normalizeMount(?string $mount): int
    {
        switch (strtolower((string)$mount)) {
            case 'sidebar_group':
                return Menu::MOUNT_SIDEBAR_GROUP;
            case 'sidebar_fixed':
                return Menu::MOUNT_SIDEBAR_FIXED;
            case 'launcher':
                return Menu::MOUNT_LAUNCHER;
            case 'module':
            default:
                return Menu::MOUNT_MODULE;
        }
    }

    /**
     * 注册插件菜单（含菜单派生权限）。
     *
     * 按插件「顶级菜单数量」分流到「插件扩展」或「启动台」。
     *
     * ## 覆盖策略（与库包链路统一）
     *
     * **默认 `preserveExisting = true`**：已存在的菜单**不改写字段**，保留运营在后台改过的
     * `name/icon/sort/status/type/description`，只补建缺失项、恢复软删除项；权限仍照常
     * 派生与绑定（`MenuPermissionBridge::syncBatch()` 第 3 步不受 preserve 影响）。
     *
     * `$force = true` 时以 manifest 声明为准**覆盖**（重命名、改 path/type、重置排序等）。
     * 与 `MenuItemSync` 的 `force` 语义一致 —— 两条链路此前不一致（插件路径恒覆盖），
     * 现已统一为「默认保留，显式 force 才覆盖」。
     *
     * @param PluginMetadata $metadata 插件元数据
     * @param bool           $force    true = 以 manifest 为准覆盖已存在菜单的字段
     * @return void
     */
    public function register(PluginMetadata $metadata, bool $force = false): void
    {
        $menus = MenuSchema::normalizeTree($metadata->getMenus());
        if (empty($menus)) {
            return;
        }

        $ident = $metadata->getIdent();

        try {
            if (count($menus) <= 1) {
                $this->registerSingleTop($metadata, $menus, $force);
            } else {
                $this->registerMultiTop($metadata, $menus, $force);
            }
        } catch (\Throwable $e) {
            ContextualLogger::error(sprintf(
                '插件 "%s" 菜单注册失败: %s',
                $ident,
                $e->getMessage()
            ));
            throw $e;
        }
    }

    /**
     * 清理「manifest 已不再声明」的菜单（**软删除，可恢复**）。
     *
     * 于是插件作者把菜单从 manifest 里拿掉后，库里的旧节点会永久留着 —— 越升级越多。
     *
     * 判定与库包链路**完全共用**（委托 {@see MenuItemSync::prune()} →
     * {@see \wise\support\menu\MenuTreePruner}），因此两侧不会各清各的：
     *   - **软删**（`delete_time`）而非物理删：manifest 重新声明该节点时，落库原语走
     *     `withTrashed()` 会恢复**同一行**（连同 `permission_id` 与既有角色授权）；
     *   - **权限行保留**（可能已被角色引用），不在此删除；
     *   - **插件根容器**（`is_plugin_root=1`）与「仍被声明子树引用的父目录」永不删；
     *   - 前台/后台手工建的菜单（`plugin` 为空或其它标识）不在清理范围内。
     *
     * ⚠️ 必须在 `register()` **之后**调用：先按最新声明补齐结构，再做差集。
     * ⚠️ 声明为空（manifest 未写 `menus` / 节点全非法）时**不清理** —— 那更可能是声明写坏了，
     *    而不是「这个插件一个菜单都不要」。
     *
     * @param  PluginMetadata $metadata
     * @param  bool           $dryRun true = 只返回将清理的节点，不落库（CLI 预检）
     * @return array<int, array{id:int,name:string,path:string,type:string}> 被清理（或将清理）的节点
     */
    public function prune(PluginMetadata $metadata, bool $dryRun = false): array
    {
        $menus = MenuSchema::normalizeTree($metadata->getMenus());
        if (empty($menus)) {
            return [];
        }

        return (new MenuItemSync())->prune($metadata->getIdent(), $menus, $dryRun);
    }

    /**
     * 注册「单顶级菜单」插件：入口挂到「插件扩展」下。
     *
     * @param PluginMetadata $metadata
     * @param array          $menus    顶级菜单数组（长度 = 1）
     * @param bool           $force    是否以 manifest 为准覆盖已存在菜单
     * @return void
     */
    protected function registerSingleTop(PluginMetadata $metadata, array $menus, bool $force = false): void
    {
        $ident    = $metadata->getIdent();
        $extendId = Menu::ensurePluginExtendRoot();
        $single   = $menus[0];

        $isDir = ($single['type'] ?? Menu::TYPE_CATALOG) === Menu::TYPE_CATALOG;
        $rootName = $isDir ? ($single['name'] ?? $ident) : $metadata->getDisplayName();
        $rootIcon = $isDir
            ? ($single['icon'] ?? 'bi bi-plugin')
            : ($metadata->getMenuRoot()['icon'] ?? 'bi bi-plugin');

        $root = Menu::createPluginRoot($ident, [
            'name'      => $rootName,
            'icon'      => $rootIcon,
            'parent_id' => $extendId,
            'mount'     => Menu::MOUNT_SIDEBAR_GROUP,
            'pinned'    => 0,
            'sort'      => (int)($single['sort'] ?? 0),
        ]);
        $rootId = (int)$root->getAttr('id');

        if ($isDir) {
            $children = $single['children'] ?? $single['child'] ?? [];
            if (!empty($children)) {
                if (!is_array(reset($children))) {
                    $children = [$children];
                }
                Menu::createMenusWithPermissions($ident, $children, $rootId, 0, !$force);
            }
        } else {
            Menu::createMenusWithPermissions($ident, [$single], $rootId, 0, !$force);
        }
    }

    /**
     * 注册「多顶级菜单」插件：入口挂到顶级（parent_id=0），默认进启动台。
     *
     * @param PluginMetadata $metadata
     * @param array          $menus    顶级菜单数组（长度 >= 2）
     * @param bool           $force    是否以 manifest 为准覆盖已存在菜单
     * @return void
     */
    protected function registerMultiTop(PluginMetadata $metadata, array $menus, bool $force = false): void
    {
        $ident = $metadata->getIdent();

        $root = Menu::createPluginRoot($ident, [
            'name'      => $metadata->getDisplayName(),
            'icon'      => $metadata->getMenuRoot()['icon'] ?? 'bi bi-plugin',
            'parent_id' => 0,
            'mount'     => Menu::MOUNT_LAUNCHER,
            'pinned'    => 0,
            'sort'      => (int)($metadata->getMenuRoot()['sort'] ?? 0),
        ]);
        $rootId = (int)$root->getAttr('id');

        Menu::createMenusWithPermissions($ident, $menus, $rootId, 0, !$force);
    }

    /**
     * 判断插件根节点是否为「多顶级」结构（可钉选）。
     *
     * 结构信息由注册时写入 mount 字段持久化：
     *   - MOUNT_LAUNCHER      多顶级插件（可钉选）
     *   - MOUNT_SIDEBAR_GROUP 单顶级插件（不可钉选）
     *
     * @param \think\Model $root
     * @return bool
     */
    protected function isPinnable($root): bool
    {
        return (int)$root->getAttr('mount') === Menu::MOUNT_LAUNCHER;
    }

    /**
     * 将多顶级插件钉选到「常用插件区」。
     *
     * 仅多顶级插件可钉选；单顶级插件（结构为 MOUNT_SIDEBAR_GROUP）不可。
     *
     * @param string $pluginIdent
     * @return bool
     */
    public function pinPlugin(string $pluginIdent): bool
    {
        $root = Menu::findPluginRoot($pluginIdent);
        if (!$root || !$this->isPinnable($root)) {
            return false;
        }
        $root->setAttr('pinned', 1);
        $root->save();
        return true;
    }

    /**
     * 取消钉选（移回启动台）。
     *
     * @param string $pluginIdent
     * @return bool
     */
    public function unpinPlugin(string $pluginIdent): bool
    {
        $root = Menu::findPluginRoot($pluginIdent);
        if (!$root) {
            return false;
        }
        $root->setAttr('pinned', 0);
        $root->save();
        return true;
    }

    /**
     * 删除插件菜单（含插件根节点）。
     *
     * @param string $pluginIdent 插件标识
     * @param bool   $purge       是否物理删除（true=无痕，含软删的行与菜单派生权限；
     *                            false=软删可恢复，重装时自动 restore）
     * @return bool
     */
    public function unregister(string $pluginIdent, bool $purge = false): bool
    {
        try {
            (new MenuItemSync())->uninstall($pluginIdent, $purge);
            return true;
        } catch (\Throwable $e) {
            ContextualLogger::warning(sprintf(
                '插件 "%s" 菜单删除失败: %s',
                $pluginIdent,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * 重写插件根节点的挂载配置（供后台「菜单布局」覆盖）。
     *
     * 归属三选一：插件扩展（parent_id=扩展节点）、启动台（parent_id=0, pinned=0）、
     * 常用区（parent_id=0, pinned=1）。单顶级插件不可设常用区。
     *
     * @param string $pluginIdent 插件标识
     * @param array  $layout      ['placement' => 'extend'|'launcher'|'pinned', 'sort' => int, 'name' => string, 'icon' => string]
     * @return bool
     */
    public function updateRootLayout(string $pluginIdent, array $layout): bool
    {
        $root = Menu::findPluginRoot($pluginIdent);
        if (!$root) {
            return false;
        }

        $isSingle = !$this->isPinnable($root);
        $placement = $layout['placement'] ?? '';

        switch ($placement) {
            case 'extend':
                if ($isSingle) {
                    $root->setAttr('parent_id', Menu::ensurePluginExtendRoot());
                    $root->setAttr('pinned', 0);
                }
                break;

            case 'pinned':
                if ($isSingle) {
                    return false;
                }
                $root->setAttr('parent_id', 0);
                $root->setAttr('pinned', 1);
                break;

            case 'launcher':
            default:
                $root->setAttr('parent_id', 0);
                $root->setAttr('pinned', 0);
                break;
        }

        if (array_key_exists('sort', $layout)) {
            $root->setAttr('sort', (int)$layout['sort']);
        }
        if (array_key_exists('name', $layout)) {
            $root->setAttr('name', (string)$layout['name']);
        }
        if (array_key_exists('icon', $layout)) {
            $root->setAttr('icon', (string)$layout['icon']);
        }
        $root->save();

        return true;
    }
}
