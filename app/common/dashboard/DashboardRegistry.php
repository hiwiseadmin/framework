<?php
declare(strict_types=1);

namespace app\common\dashboard;

use think\facade\Cache;
use wise\support\ExtPoint;
use plugin\contract\DashboardWidgetInterface;
use app\common\dashboard\widget\SystemActivityWidget;
use app\common\dashboard\widget\SystemNotificationsWidget;
use app\common\dashboard\widget\SystemServerStatusWidget;
use app\common\dashboard\widget\SystemShortcutsWidget;
use app\common\dashboard\widget\SystemWelcomeWidget;

/**
 * Dashboard 数据面板注册中心
 *
 * 框架内置 + 插件面板的统一注册与发现入口：
 *  - 注册源：框架内置（boot()）与插件（register()，推荐插件 BootServiceProvider::boot() 调用）；
 *  - 存储：ExtPoint 扩展点（dashboard.widget），沿用 active/priority 能力管理；
 *  - 消费：catalog() 输出可见面板清单；data() 取面板数据（TTL 缓存）。
 *
 * 权限模型：页面入口受既有菜单 RBAC 控制；面板级细粒度权限通过 setPermissionChecker()
 * 注入（AdminTrait 提供），面板实现可用 DashboardRegistry::passesPermission($slug) 判断。
 *
 * @package app\common\dashboard
 */
class DashboardRegistry
{
    /** ExtPoint 扩展点名 */
    public const EXT_POINT = 'dashboard.widget';

    /**
     * 面板权限检查回调 fn(string $permissionSlug): bool
     *
     * @var callable|null
     */
    protected static $permissionChecker = null;

    /**
     * 注入权限检查回调（由 AdminTrait 在后台请求注入）
     *
     * @param callable $checker fn(string $slug): bool
     */
    public static function setPermissionChecker(callable $checker): void
    {
        static::$permissionChecker = $checker;
    }

    /**
     * 校验当前用户是否具备某面板权限 slug
     *
     * 未注入权限检查器时默认放行（页面 RBAC 兜底）。
     *
     * @param string $slug
     * @return bool
     */
    public static function passesPermission(string $slug): bool
    {
        if (empty($slug)) {
            return true;
        }
        $checker = static::$permissionChecker;
        if (!$checker) {
            return true;
        }
        try {
            return (bool) $checker($slug);
        } catch (\Throwable $e) {
            // 权限服务异常时默认放行，避免面板不可用
            return true;
        }
    }

    /**
     * 注册框架内置面板（app/AppService::boot() 调用一次）
     */
    public static function boot(): void
    {
        static::registerSystem(new SystemWelcomeWidget(), 10);
        static::registerSystem(new SystemShortcutsWidget(), 10);
        static::registerSystem(new SystemServerStatusWidget(), 10);
        static::registerSystem(new SystemNotificationsWidget(), 20);
        static::registerSystem(new SystemActivityWidget(), 30);
    }

    /**
     * 注册一个数据面板（框架内置 / 插件共用）
     *
     * @param DashboardWidgetInterface|string $widget   实现类实例或类名字符串
     * @param int                             $priority 排序优先级（小在前）
     * @return void
     */
    public static function register(DashboardWidgetInterface|string $widget, int $priority = 100): void
    {
        // 解析并校验实现（字符串类名 / 对象均可），确保 getKey() 可用
        if (is_string($widget)) {
            $instance = static::resolveInstance($widget);
        } else {
            $instance = $widget;
        }
        if (!$instance instanceof DashboardWidgetInterface) {
            return;
        }
        $key = $instance->getKey();
        if (str_starts_with($key, 'system.')) {
            // system.* 为框架保留段，仅允许内部 registerSystem 注册
            return;
        }
        // ExtPoint 载荷契约为 string|callable：统一存实现类名，实例化延迟到解析阶段
        ExtPoint::register(static::EXT_POINT, $key, get_class($instance), $priority);
    }

    /**
     * 注册内置面板（system.* 保留段）
     *
     * @param DashboardWidgetInterface $widget
     * @param int                      $priority
     * @return void
     */
    protected static function registerSystem(DashboardWidgetInterface $widget, int $priority = 100): void
    {
        $key = $widget->getKey();
        if (!str_starts_with($key, 'system.')) {
            return;
        }
        // 与 register() 一致：载荷存实现类名，解析时再 new（resolveInstance 已处理类名字符串）
        ExtPoint::register(static::EXT_POINT, $key, get_class($widget), $priority);
    }

    /**
     * 获取当前用户可见的面板目录（含元数据，供前端“添加面板”与布局校验）
     *
     * @return array<int, array>
     */
    public static function catalog(): array
    {
        $items = [];
        foreach (ExtPoint::all(static::EXT_POINT) as $key => $item) {
            if (isset($item['active']) && !$item['active']) {
                continue;
            }
            $instance = static::resolveInstance($item['concrete'] ?? null);
            if (!$instance instanceof DashboardWidgetInterface) {
                continue;
            }
            if (!$instance->isAccessible()) {
                continue;
            }
            $items[] = static::meta($instance);
        }
        // 与注册优先级一致：按 source(内置在前) + key 稳定排序
        usort($items, function ($a, $b) {
            $as = $a['source'] === 'system' ? 0 : 1;
            $bs = $b['source'] === 'system' ? 0 : 1;
            return $as === $bs ? strcmp($a['key'], $b['key']) : $as <=> $bs;
        });
        return $items;
    }

    /**
     * 取单个面板实例（不存在 / 不可见返回 null）
     *
     * @param string $key
     * @return DashboardWidgetInterface|null
     */
    public static function widget(string $key): ?DashboardWidgetInterface
    {
        $all = ExtPoint::all(static::EXT_POINT);
        if (!isset($all[$key])) {
            return null;
        }
        $entry = $all[$key];
        if (isset($entry['active']) && !$entry['active']) {
            return null;
        }
        $instance = static::resolveInstance($entry['concrete'] ?? null);
        if (!$instance instanceof DashboardWidgetInterface) {
            return null;
        }
        return $instance->isAccessible() ? $instance : null;
    }

    /**
     * 取面板数据（应用契约的 TTL 缓存语义）
     *
     * @param string $key
     * @param array  $config 用户 props
     * @return array
     */
    public static function data(string $key, array $config = []): array
    {
        $w = static::widget($key);
        if (!$w) {
            throw new \InvalidArgumentException("widget [{$key}] 不存在或不可见");
        }
        $cfg = array_merge($w->getDefaultConfig(), $config);
        $ck  = $w->getCacheKey($cfg);
        $ttl = $w->getCacheTTL();
        if ($ck === '' || $ttl <= 0) {
            return $w->getData($cfg);
        }
        $cacheKey = 'dashboard:data:' . $key . ':' . md5($ck . ':' . json_encode($cfg));
        return Cache::remember($cacheKey, $ttl, fn() => $w->getData($cfg));
    }

    /**
     * 面板元数据（前端可用的目录条目）
     *
     * @param DashboardWidgetInterface $widget
     * @return array
     */
    protected static function meta(DashboardWidgetInterface $widget): array
    {
        $key   = $widget->getKey();
        $size  = $widget->getDefaultSize();
        $min   = $widget->getMinSize();
        return [
            'key'           => $key,
            'source'        => static::sourceOf($key),
            'title'         => $widget->getTitle(),
            'description'   => $widget->getDescription(),
            'icon'          => $widget->getIcon(),
            'category'      => $widget->getCategory(),
            'size'          => ['w' => (int)($size['w'] ?? 2), 'h' => (int)($size['h'] ?? 2)],
            'min'           => ['w' => (int)($min['w'] ?? 1), 'h' => (int)($min['h'] ?? 1)],
            'accent'        => $widget->getSuggestedAccent(),
            'defaultConfig' => $widget->getDefaultConfig(),
            'propsSchema'   => $widget->getConfigSchema(),
        ];
    }

    /**
     * 推导来源：system.* 为框架；否则取首个 '.' 前段作为插件标识
     *
     * @param string $key
     * @return string
     */
    protected static function sourceOf(string $key): string
    {
        if (str_starts_with($key, 'system.')) {
            return 'system';
        }
        $pos = strpos($key, '.');
        return $pos === false ? $key : substr($key, 0, $pos);
    }

    /**
     * 统一实例化：支持对象 / 类名字符串 / null
     *
     * @param mixed $widget
     * @return object|null
     */
    protected static function resolveInstance(mixed $widget): ?object
    {
        if (is_object($widget)) {
            return $widget;
        }
        if (is_string($widget) && $widget !== '' && class_exists($widget)) {
            try {
                return new $widget();
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }
}
