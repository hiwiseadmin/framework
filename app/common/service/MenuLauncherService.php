<?php
declare(strict_types=1);

namespace app\common\service;

use app\admin\model\Menu;
use wise\plugin\PluginContainerKeys;
use wise\plugin\scanner\PluginMetadata;

/**
 * 菜单启动台/入口服务
 *
 * 从 Index 控制器抽取的菜单树分类 + 启动台图标解析逻辑，供后台首页（Index）
 * 与 Dashboard（快捷入口面板 / /entries 接口）共用，避免两处维护重复代码。
 *
 * 入口/图标规范不变（见 docs/plugin-launcher-icon-spec.md）：
 *  - 插件入口图标完全来自插件 manifest.json 顶层 icon，与 menus 表图标解耦；
 *  - Dashboard entries 统一输出结构化 icon {type,value,color}，前端按 type 渲染。
 *
 * @package app\common\service
 */
class MenuLauncherService
{
    /**
     * 插件启动台图标映射（ident → {type,value,color}），请求级懒加载一次
     *
     * @var array<string, array{type:string,value:string,color:string}>|null
     */
    protected static ?array $pluginIconMap = null;

    /**
     * 将菜单树按顶部导航四部分分类：控制台 / 插件扩展 / 常用插件区 / 启动台
     *
     * @param array $menus 菜单树（parent_id=0 的根节点数组）
     * @return array{consoleMenus:array, extendRoot:?array, pinnedPlugins:array, launcherPlugins:array}
     */
    public static function classifyMenuLayout(array $menus): array
    {
        $consoleMenus    = [];
        $extendRoot      = null;
        $pinnedPlugins   = [];
        $launcherPlugins = [];

        foreach ($menus as $root) {
            $plugin       = $root['plugin'] ?? 'system';
            $isPluginRoot = !empty($root['is_plugin_root']);
            $isLauncher   = (int) ($root['mount'] ?? 0) === Menu::MOUNT_LAUNCHER;

            // 启动台准入（两条并集，覆盖两条链路）：
            //   1. 插件入口根（is_plugin_root=1）—— **插件链路**：多顶级 → 启动台；
            //      单顶级 → 挂在「插件扩展」下（其子节点也被收进启动台，见下方 system 分支）；
            //   2. mount = MenuMount::LAUNCHER 的容器 —— **库包链路**：库包声明该模式后由
            //      Menu::ensureContainer() 落库为 `parent_id=0 + is_plugin_root=1 + mount=3`
            //      （见其注释「两种落库形态」），故两条判定同时成立；
            //      这里保留 mount 判定是为了兜住"只改了 mount 没改 is_plugin_root"的存量行。
            if ($isPluginRoot || $isLauncher) {
                $launcherPlugins[] = static::buildLauncherItem($root);
            }

            if ($plugin === 'system') {
                if (($root['name'] ?? '') === Menu::PLUGIN_EXTEND_NAME) {
                    // 「插件扩展」容器：其子节点为单顶级插件入口
                    $extendRoot = $root;
                    foreach (static::getChildrenOf($root) as $child) {
                        if (!empty($child['is_plugin_root'])) {
                            $launcherPlugins[] = static::buildLauncherItem($child);
                        }
                    }
                } else {
                    // 控制台等系统顶级菜单
                    $consoleMenus[] = $root;
                }
                continue;
            }

            // 顶层且被钉选 → 进「常用插件区」（插件多顶级根、库包启动台容器同规则）
            // 补结构化图标：顶部入口由服务端直出，图标口径必须与启动台一致，
            // 否则「钉选后刷新」会退化成纯文字入口（启动台内有图标、顶部没有）。
            if (($isPluginRoot || $isLauncher) && !empty($root['pinned'])) {
                $pinnedPlugins[] = static::withStructuredIcon($root);
            }
        }

        return [
            'consoleMenus'    => $consoleMenus,
            'extendRoot'      => $extendRoot,
            'pinnedPlugins'   => $pinnedPlugins,
            'launcherPlugins' => $launcherPlugins,
        ];
    }

    /**
     * Dashboard 快捷入口数据：框架（控制台） + 插件两组可点击图标入口
     *
     * 所有条目统一结构化 icon，供前端单一渲染逻辑消费。
     *
     * @param array $menus 菜单树（Menu::getMenuByRoleId 返回值，已含 RBAC 过滤）
     * @return array<int, array{group:string,label:string,items:array}>
     */
    public static function entries(array $menus): array
    {
        $layout = static::classifyMenuLayout($menus);

        $frameworkItems = [];
        foreach ($layout['consoleMenus'] as $root) {
            $item = static::frameworkItem($root);
            if ($item) {
                $frameworkItems[] = $item;
            }
        }

        $pluginItems = [];
        foreach ($layout['launcherPlugins'] as $item) {
            $pluginItems[] = [
                'ident' => $item['ident'] ?? '',
                'title' => $item['title'] ?? '',
                'url'   => $item['firstUrl'] !== '' ? $item['firstUrl'] : $item['path'],
                'icon'  => [
                    'type'  => $item['iconType'] ?? 'bi',
                    'value' => $item['iconValue'] ?? ($item['icon'] ?? 'bi bi-plugin'),
                    'color' => $item['iconColor'] ?? '',
                ],
            ];
        }

        $groups = [];
        if ($frameworkItems) {
            $groups[] = ['group' => 'framework', 'label' => '框架', 'items' => $frameworkItems];
        }
        if ($pluginItems) {
            $groups[] = ['group' => 'plugins', 'label' => '插件', 'items' => $pluginItems];
        }
        return $groups;
    }

    /**
     * 兼容两种树节点键名取子节点
     *
     * @param array $node
     * @return array
     */
    public static function getChildrenOf(array $node): array
    {
        return $node['childlist'] ?? $node['children'] ?? [];
    }

    /**
     * 框架（控制台）入口条目：取首个可导航路径，图标取菜单 bi 图标
     *
     * @param array $root 系统顶级目录节点
     * @return array|null
     */
    protected static function frameworkItem(array $root): ?array
    {
        $url = static::firstLeafUrl($root);
        if ($url === '') {
            return null;
        }
        return [
            'ident' => 'system',
            'title' => $root['name'] ?? '',
            'url'   => $url,
            'icon'  => [
                'type'  => 'bi',
                'value' => $root['icon'] ?? 'bi bi-grid',
                'color' => '',
            ],
        ];
    }

    /**
     * 深度优先查找第一个带 path 的叶子路径（dir → menu → button 两层内）
     *
     * @param array $node
     * @return string
     */
    public static function firstLeafUrl(array $node): string
    {
        if (!empty($node['path'])) {
            return (string) $node['path'];
        }
        $children = static::getChildrenOf($node);
        foreach ($children as $child) {
            if (!empty($child['path'])) {
                return (string) $child['path'];
            }
            $grand = static::getChildrenOf($child);
            foreach ($grand as $g) {
                if (!empty($g['path'])) {
                    return (string) $g['path'];
                }
            }
        }
        return '';
    }

    /**
     * 构建启动台条目（含图标/标题/首个可打开路径/钉选状态）
     *
     * @param array $root 插件入口节点
     * @return array
     */
    public static function buildLauncherItem(array $root): array
    {
        $firstUrl = static::firstLeafUrl($root);

        // 多顶级插件（parent_id=0）可钉选；单分组插件（parent_id!=0）归入「插件扩展」
        $canPin = (int) ($root['parent_id'] ?? -1) === 0 ? 1 : 0;
        $pinned = !empty($root['pinned']) ? 1 : 0;

        // 启动台图标（结构化，取自 manifest 顶层 icon，与菜单无关）
        $icon = static::resolveItemIcon($root);

        $item = [
            'ident'     => $root['plugin'] ?? '',
            'title'     => $root['name'] ?? '',
            'icon'      => $icon['type'] === 'bi' && $icon['value'] !== ''
                ? $icon['value']
                : 'bi bi-plugin',
            'iconType'  => $icon['type'],
            'iconValue' => $icon['value'],
            'iconColor' => $icon['color'],
            'path'      => $root['path'] ?? '',
            'firstUrl'  => $firstUrl,
            'pinned'    => $pinned,
            'canPin'    => $canPin,
            // 供前端定位顶部 Tab
            'parentMenuId' => $canPin ? (int) ($root['id'] ?? 0) : (int) ($root['parent_id'] ?? 0),
        ];

        // 未固定的多顶级插件：附带完整菜单树，供前端动态生成临时侧边栏
        if ($canPin && !$pinned) {
            $item['children'] = static::getChildrenOf($root);
        }

        return $item;
    }

    /**
     * 条目结构化图标：插件 manifest 顶层 icon → 菜单节点自身 bi 图标 → 默认 bi bi-plugin
     *
     * **启动台九宫格与顶部「常用插件区」入口共用这一份口径**：同一个应用在启动台里和
     * 钉选后的顶部入口必须显示同一个图标。
     *
     * 图标优先级：插件 manifest 顶层 icon → 菜单节点自身 bi 图标（**库包没有 manifest，
     * 只能走这一档**，否则启动台上会是一片默认的 bi bi-plugin）→ 兜底默认图标。
     *
     * @param array $root 菜单根节点（含 plugin / icon）
     * @return array{type:string,value:string,color:string}
     */
    public static function resolveItemIcon(array $root): array
    {
        $icon = static::resolveLaunchIcon((string) ($root['plugin'] ?? ''));

        $fallbackIcon  = trim((string) ($root['icon'] ?? ''));
        $hasPluginIcon = $icon['type'] === 'bi'
            ? $icon['value'] !== '' && $icon['value'] !== 'bi bi-plugin'
            : $icon['value'] !== '';

        if (!$hasPluginIcon && $fallbackIcon !== '') {
            $icon = ['type' => 'bi', 'value' => $fallbackIcon, 'color' => $icon['color']];
        }

        return $icon;
    }

    /**
     * 给菜单根节点补结构化图标字段（iconType / iconValue / iconColor / iconBi）
     *
     * 供顶部「常用插件区」服务端直出：
     *   - `iconBi` 是可直接输出的 bi 兜底类名（模板 `<i class="{$menu.iconBi}">`）；
     *   - 其余三态交前端按 iconType 分派（image → 背景图 / emoji → 文本），与启动台九宫格同一渲染口径。
     *
     * **保留原节点的 id / childlist / path 等字段**——模板还要用它们渲染该插件对应的侧边栏。
     *
     * @param array $root
     * @return array
     */
    protected static function withStructuredIcon(array $root): array
    {
        $icon = static::resolveItemIcon($root);

        $root['iconType']  = $icon['type'];
        $root['iconValue'] = $icon['value'];
        $root['iconColor'] = $icon['color'];
        $root['iconBi']    = $icon['type'] === 'bi' && $icon['value'] !== '' ? $icon['value'] : 'bi bi-plugin';

        return $root;
    }

    /**
     * 懒加载一次全部插件的 manifest 顶层 icon 映射（ident → {type,value,color}）
     *
     * @return void
     */
    protected static function loadPluginIconMap(): void
    {
        if (static::$pluginIconMap !== null) {
            return;
        }
        static::$pluginIconMap = [];
        try {
            /** @var \wise\plugin\PluginManager $manager */
            $manager = app(PluginContainerKeys::MANAGER);
            foreach ($manager->getAllPlugins() as $meta) {
                if ($meta instanceof PluginMetadata && !empty($meta->getIcon()['value'])) {
                    static::$pluginIconMap[$meta->getIdent()] = $meta->getIcon();
                }
            }
        } catch (\Throwable $e) {
            // 插件系统不可用：保留空映射，各条目走默认图标
        }
    }

    /**
     * 解析插件启动台图标（manifest 顶层 icon），失败回退默认 bi bi-plugin
     *
     * @param string $ident
     * @return array{type:string,value:string,color:string}
     */
    public static function resolveLaunchIcon(string $ident): array
    {
        static::loadPluginIconMap();
        return static::$pluginIconMap[$ident]
            ?? ['type' => 'bi', 'value' => 'bi bi-plugin', 'color' => ''];
    }
}
