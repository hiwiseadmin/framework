<?php
declare(strict_types=1);

namespace app\common\slot;

use wise\support\ExtPoint;
use wise\support\ExtPoints;

/**
 * 前端插槽管理器
 *
 * 负责管理前端页面的命名插槽定义和插件内容注入。
 *
 * 架构：
 *  - 插槽定义（Slot Definition）：框架页面预定义的占位区域
 *  - 插槽内容（Slot Content）：插件通过 manifest.json 注入的 UI 组件
 *
 * 使用示例：
 * <pre>
 * // 1. 框架定义插槽
 * SlotManager::define('dashboard.widgets.top', '仪表盘顶部挂件区', 'grid', 6);
 * SlotManager::define('order.detail.sidebar', '订单详情侧边栏', 'stack', 10);
 *
 * // 2. 插件注册内容（通过 ExtPoint）
 * ExtPoint::register(ExtPoints::SLOT_CONTENT, 'shop_sales', [
 *     'slot'      => 'dashboard.widgets.top',
 *     'component' => 'plugin/shop/widget/SalesOverview',
 *     'title'     => '销售概览',
 *     'priority'  => 10,
 *     'icon'      => 'bi bi-graph-up',
 * ]);
 *
 * // 3. 获取页面所有插槽数据
 * $slots = SlotManager::resolve('dashboard');
 *
 * // 4. 注入到前端 __WS_CONFIG__
 * $config['slots'] = SlotManager::resolve($page);
 * </pre>
 *
 * @package app\common\slot
 */
class SlotManager
{
    /**
     * 权限检查回调
     *
     * @var callable|null fn(string $permissionSlug): bool
     */
    protected static $permissionChecker = null;

    /**
     * 设置权限检查回调
     *
     * 用于在 AdminTrait 中注入当前管理员的权限检查逻辑。
     *
     * @param callable $checker fn(string $permissionSlug): bool
     */
    public static function setPermissionChecker(callable $checker): void
    {
        static::$permissionChecker = $checker;
    }

    /**
     * 已定义的插槽
     *
     * 结构: [ slotName => ['title' => string, 'renderer' => string, 'maxItems' => int, 'page' => string] ]
     *
     * @var array<string, array>
     */
    protected static array $definitions = [];

    /**
     * 系统默认插槽定义
     */
    protected static array $systemDefaults = [];

    /**
     * 定义系统默认插槽（框架启动时调用一次）
     */
    public static function boot(): void
    {
        // 仪表盘页面
        static::define('dashboard.widgets.top', '顶部统计区', 'grid', 6, 'dashboard');
        static::define('dashboard.widgets.middle', '中间图表区', 'grid', 4, 'dashboard');
        static::define('dashboard.widgets.bottom', '底部列表区', 'grid', 4, 'dashboard');
        static::define('dashboard.widgets.sidebar', '侧边快捷区', 'stack', 4, 'dashboard');

        // 订单详情页
        static::define('order.detail.sidebar', '订单侧边栏', 'stack', 8, 'order_detail');
        static::define('order.detail.tabs', '订单详情Tab', 'tabs', 5, 'order_detail');
        static::define('order.detail.before_table', '表格前操作区', 'list', 6, 'order_detail');
        static::define('order.detail.after_table', '表格后扩展区', 'list', 6, 'order_detail');

        // 用户详情页
        static::define('user.profile.header', '用户头部信息', 'grid', 4, 'user_profile');
        static::define('user.profile.tabs', '用户详情Tab', 'tabs', 5, 'user_profile');
        static::define('user.profile.sidebar', '用户侧边栏', 'stack', 6, 'user_profile');

        // 全局页面
        static::define('admin.header.right', '顶栏右侧', 'list', 4, 'global');
        static::define('admin.footer', '页脚', 'list', 4, 'global');
        static::define('admin.sidebar.bottom', '侧边栏底部', 'list', 4, 'global');
    }

    /**
     * 定义一个插槽
     *
     * @param string $name       插槽名称，如 'dashboard.widgets.top'
     * @param string $title      显示名称
     * @param string $renderer   渲染模式：grid | stack | list | tabs
     * @param int    $maxItems   最大容纳数量（0 = 不限）
     * @param string $page       所属页面标识
     */
    public static function define(
        string $name,
        string $title,
        string $renderer = 'stack',
        int $maxItems = 10,
        string $page = ''
    ): void {
        static::$definitions[$name] = [
            'name'     => $name,
            'title'    => $title,
            'renderer' => $renderer,
            'maxItems' => $maxItems,
            'page'     => $page,
        ];
    }

    /**
     * 获取插槽定义
     */
    public static function getDefinition(string $name): ?array
    {
        return static::$definitions[$name] ?? null;
    }

    /**
     * 获取所有插槽定义
     */
    public static function getDefinitions(): array
    {
        return static::$definitions;
    }

    /**
     * 按页面获取插槽定义
     *
     * @return array<string, array>
     */
    public static function getDefinitionsByPage(string $page): array
    {
        $result = [];
        $globalSlots = [];

        foreach (static::$definitions as $name => $def) {
            if ($def['page'] === 'global') {
                $globalSlots[$name] = $def;
            } elseif ($def['page'] === $page) {
                $result[$name] = $def;
            }
        }

        // 全局插槽拼接到所有页面
        return array_merge($result, $globalSlots);
    }

    /**
     * 检查插槽是否已定义
     */
    public static function has(string $name): bool
    {
        return isset(static::$definitions[$name]);
    }

    /**
     * 解析指定页面的所有插槽及已注册内容
     *
     * @param string $page      页面标识，如 'dashboard'
     * @param bool   $resolveContent 是否同时解析内容
     * @return array  [ slotName => ['definition' => [...], 'contents' => [...]] ]
     */
    public static function resolve(string $page, bool $resolveContent = true): array
    {
        $definitions = static::getDefinitionsByPage($page);
        $result = [];

        foreach ($definitions as $name => $def) {
            $slot = ['definition' => $def, 'contents' => []];

            if ($resolveContent) {
                $slot['contents'] = static::resolveContents($name);
            }

            $result[$name] = $slot;
        }

        return $result;
    }

    /**
     * 解析指定插槽的已注册内容
     *
     * 从 ExtPoints::SLOT_CONTENT 中查找匹配的内容，
     * 按 priority 排序，受 maxItems 限制。
     *
     * @return array
     */
    public static function resolveContents(string $slotName): array
    {
        $allContents = ExtPoint::all(ExtPoints::SLOT_CONTENT);
        $def = static::$definitions[$slotName] ?? null;
        $maxItems = $def['maxItems'] ?? 10;

        $matched = [];

        foreach ($allContents as $key => $item) {
            $content = $item['concrete'];
            // content 可以是数组或返回数组的闭包
            if (is_callable($content)) {
                $content = $content();
            }

            if (!is_array($content)) continue;
            if (($content['slot'] ?? '') !== $slotName) continue;

            // 权限校验
            $permission = $content['permission'] ?? '';
            if (!empty($permission) && static::$permissionChecker && !(static::$permissionChecker)($permission)) {
                continue;
            }

            $matched[$key] = array_merge($content, [
                '_priority' => $item['priority'] ?? 100,
                '_active'   => $item['active'] ?? true,
            ]);
        }

        // 按优先级排序
        uasort($matched, fn($a, $b) => $a['_priority'] <=> $b['_priority']);

        // 只保留活跃的内容，截断到 maxItems
        $active = array_filter($matched, fn($c) => $c['_active']);
        $result = array_slice($active, 0, $maxItems, true);

        // 去掉内部字段
        return array_map(function ($item) {
            unset($item['_priority'], $item['_active']);
            return $item;
        }, $result);
    }

    /**
     * 从插件 manifest.json 注册插槽内容
     *
     * 在插件安装/启用时调用。
     *
     * @param string $pluginIdent 插件标识
     * @param array  $slots       manifest.json 中的 slots 数组
     */
    public static function registerFromManifest(string $pluginIdent, array $slots): void
    {
        foreach ($slots as $index => $slot) {
            $slotName = $slot['slot'] ?? '';
            if (empty($slotName)) continue;

            if (!static::has($slotName)) {
                // 插槽未定义，跳过（不阻断插件安装）
                continue;
            }

            $key = "{$pluginIdent}.{$index}";
            ExtPoint::register(
                ExtPoints::SLOT_CONTENT,
                $key,
                [
                    'slot'      => $slotName,
                    'component' => $slot['component'] ?? '',
                    'title'     => $slot['title'] ?? '',
                    'icon'      => $slot['icon'] ?? '',
                    'props'     => $slot['props'] ?? [],
                    'permission' => $slot['permission'] ?? '',
                    'plugin'    => $pluginIdent,
                ],
                $slot['priority'] ?? 100
            );
        }
    }

    /**
     * 注销插件的所有插槽内容
     *
     * 在插件卸载时调用。
     *
     * @param string $pluginIdent 插件标识
     */
    public static function unregisterFromManifest(string $pluginIdent): void
    {
        $allContents = ExtPoint::all(ExtPoints::SLOT_CONTENT);
        $prefix = "{$pluginIdent}.";

        foreach ($allContents as $key => $item) {
            if (str_starts_with($key, $prefix)) {
                ExtPoint::unregister(ExtPoints::SLOT_CONTENT, $key);
            }
        }
    }

    /**
     * 将插槽数据序列化为前端可用的 JSON
     *
     * @param string $page 页面标识
     * @return array 可直接 JSON 编码
     */
    public static function toFrontendData(string $page): array
    {
        $slots = static::resolve($page);

        $data = [];
        foreach ($slots as $name => $slot) {
            $data[] = [
                'name'       => $name,
                'title'      => $slot['definition']['title'] ?? '',
                'renderer'   => $slot['definition']['renderer'] ?? 'stack',
                'maxItems'   => $slot['definition']['maxItems'] ?? 10,
                'contents'   => array_values($slot['contents']),
            ];
        }

        return $data;
    }
}
