<?php
namespace app\common\service;

use think\facade\Cache;
use think\facade\Session;
use think\helper\Arr;
use wise\config\facade\WiseConfig;
use wise\support\context\RequestContext;

/**
 * 系统通用配置服务
 *
 * 封装 wise-config 的动态配置读取，提供模块化缓存和便捷读取方法。
 * 所有配置通过 WiseConfig 门面从数据库获取，自动带缓存。
 *
 * 请求级缓存通过 RequestContext（协程安全），
 * 替代旧的 static $configs 静态数组（Swoole 下会跨请求泄漏）。
 */
class ConfigService
{
    /** RequestContext 中缓存 key 的前缀 */
    private const CACHE_PREFIX = 'config_service:module:';

    /**
     * 用户级配置在 admins.ext_fields 中的挂载键
     *
     * 存储结构：ext_fields.config.{module}.{key} = value
     * 例：ext_fields.config.system.theme = 'dark'
     */
    private const USER_CONFIG_KEY = 'config';

    /**
     * 允许用户级覆盖的配置白名单（完整路径 module.key）
     *
     * 用户级配置只用于「个性化偏好」，不允许覆盖影响安全或全局行为的配置项。
     * 未登记在此白名单的键调用 setUser() 时会被静默丢弃。
     */
    private const USER_OVERRIDABLE = [
        'system.theme',
        'system.scheme',
        'system.sidebar_theme',
    ];

    /**
     * 获取指定模块的所有配置
     *
     * 作用域由 $isUser 决定：
     *  - $isUser=false（默认）→ 网站级配置（全站共享）
     *  - $isUser=true          → 当前用户级配置（网站配置 + 当前登录用户自己的覆盖值）
     *
     * @param string $module 模块名（对应 wise-config 的 namespace）
     * @param bool   $isUser 是否为当前用户级配置；false=网站级
     * @return array
     */
    public static function getAll(string $module = 'system', bool $isUser = false): array
    {
        $cacheKey = self::CACHE_PREFIX . $module;

        if (RequestContext::has($cacheKey)) {
            $data = RequestContext::get($cacheKey);
        } else {
            // wise-config 内部已做缓存，这里只做模块级请求级缓存
            $data = WiseConfig::namespace($module)->all();
            RequestContext::set($cacheKey, $data);
        }

        // 用户级：仅合并「当前登录用户自己」在 ext_fields 中的覆盖值（用户配置优先）
        if ($isUser) {
            $adminId = self::currentAdminId();
            if ($adminId > 0) {
                $overrides = self::getUserOverrides()[$module] ?? [];
                if ($overrides) {
                    $data = array_merge($data, $overrides);
                }
            }
        }

        return $data;
    }

    /**
     * 获取单个配置值
     *
     * 作用域由 $isUser 主动区分：
     *  - $isUser=false（默认）→ 网站级配置（全站共享）
     *  - $isUser=true          → 当前用户级配置（网站配置 + 当前登录用户自己的覆盖值）
     *
     * 用户级数据仅作用于当前登录用户（从 Session 自动获取，无需调用方传参），
     * 天然按用户隔离，不会暴露其他用户设置的任何内容；未登录时自动降级为网站级。
     *
     * @param string $key     配置键名（支持点号风格 system.site_name）
     * @param mixed  $default 默认值
     * @param string $module  模块名（对应 wise-config 的 namespace）
     * @param bool   $isUser  是否为当前用户级配置；false=网站级
     * @return mixed
     */
    public static function get(string $key, mixed $default = null, string $module = 'system',  bool $isUser = false): mixed
    {
        // 兼容 system.xxx 风格的 key（如 system.site_name → namespace=system, key=site_name）
        if (str_contains($key, '.')) {
            $parts = explode('.', $key, 2);
            $module = $parts[0];
            $key    = $parts[1];
        }

        // 用户级：先查当前用户自己的 ext_fields 覆盖值，命中则直接返回
        if ($isUser) {
            $adminId = self::currentAdminId();
            if ($adminId > 0) {
                $override = Arr::get(
                    self::getUserOverrides(),
                    $module . '.' . $key
                );
                if ($override !== null) {
                    return $override;
                }
            }
        }

        return WiseConfig::namespace($module)->get($key, $default);
    }

    /**
     * 写入配置
     *
     * 作用域由 $isUser 主动区分：
     *  - $isUser=false（默认）→ 写入网站级配置（全站共享）
     *  - $isUser=true          → 写入当前用户级配置（仅当前登录用户，受白名单约束，不影响其他用户）
     *
     * @param string $key    配置键名（支持 system.theme 点号风格）
     * @param mixed  $value  配置值
     * @param array  $extra  额外元数据（仅网站级生效：type / group / title / description / tags 等）
     * @param string $module 模块名（对应 wise-config 的 namespace）
     * @param bool   $isUser 是否为当前用户级配置；false=网站级
     * @return bool
     */
    public static function set(string $key, mixed $value, array $extra = [], string $module = 'system', bool $isUser = false): bool
    {
        // 兼容 system.xxx 风格的 key（如 system.theme → namespace=system, key=theme）
        if (str_contains($key, '.')) {
            $parts = explode('.', $key, 2);
            $module = $parts[0];
            $key    = $parts[1];
        }

        // 用户级：写入当前登录用户自己的 ext_fields 覆盖值（不落库到 wise-config，不跨用户共享）
        if ($isUser) {
            return self::setUser([
                $module . '.' . $key => $value,
            ]);
        }

        $result = WiseConfig::namespace($module)->set($key, $value, $extra);

        // 写后立即失效请求级缓存，避免同一请求内读到旧值
        RequestContext::remove(self::CACHE_PREFIX . $module);

        return $result;
    }

    // ─────────────────────────────────────────────────────────────
    //  用户级配置（存储于 admins.ext_fields.config.*）
    // ─────────────────────────────────────────────────────────────

    /**
     * 获取当前登录管理员的用户级配置覆盖值
     *
     * 用户 ID 从当前登录态（Session）自动获取，不接收外部传入，
     * 因此只会返回「当前用户自己」显式设置过的项，未设置的项不出现，
     * 以便调用方回退到网站级配置；未登录时返回空数组。
     *
     * @return array 结构 [module => [key => value]]
     */
    public static function getUserOverrides(): array
    {
        $adminId = self::currentAdminId();
        if ($adminId <= 0) {
            return [];
        }

        // 仅在协程环境（Swoole）启用请求级缓存：协程按 ID 分桶、随协程结束自动回收。
        // FPM / php -S 单进程下 RequestContext 是全局单桶，若 clear() 未被可靠调用，
        // 用户级配置会跨请求残留（改主题后 iframe 刷新仍读到旧值）。用户覆盖查询本身
        // 只是对 admins 表的一次 find，非常轻，非协程环境直接每次查库最安全。
        $isCoroutine = class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0;
        $cacheKey = self::CACHE_PREFIX . 'user:' . $adminId;

        if ($isCoroutine && RequestContext::has($cacheKey)) {
            return RequestContext::get($cacheKey);
        }

        $overrides = [];
        try {
            $admin = \app\admin\model\Admin::where('id', $adminId)->find();
            if ($admin) {
                $stored = Arr::get((array) $admin->ext_fields, self::USER_CONFIG_KEY, []);
                if (is_array($stored)) {
                    // 过滤为空字符串的项，视为「未设置」
                    $overrides = array_filter($stored, static fn($v) => $v !== '' && $v !== null);
                }
            }
        } catch (\Throwable $e) {
            // 表不存在 / 未登录等异常时降级为纯网站级配置
            $overrides = [];
        }

        if ($isCoroutine) {
            RequestContext::set($cacheKey, $overrides);
        }

        return $overrides;
    }

    /**
     * 写入当前登录用户的用户级配置（批量原始方法）
     *
     * 仅白名单内的键（self::USER_OVERRIDABLE）允许写入，其余静默忽略。
     * 单条写入也可通过 set($key, $value, isUser: true) 便捷调用。
     *
     * @param array $values 键值对，键支持 'system.theme' 点号风格或 ['system'=>['theme'=>'dark']] 嵌套风格
     * @return bool 是否写入成功
     */
    public static function setUser(array $values): bool
    {
        $adminId = self::currentAdminId();
        if ($adminId <= 0 || !$values) {
            return false;
        }

        $admin = \app\admin\model\Admin::where('id', $adminId)->find();
        if (!$admin) {
            return false;
        }

        $overrides = Arr::get((array) $admin->ext_fields, self::USER_CONFIG_KEY, []);
        $overrides = is_array($overrides) ? $overrides : [];

        foreach ($values as $key => $value) {
            // 嵌套风格 ['system' => ['theme' => 'dark']] 展平为 system.theme
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    self::applyUserOverride($overrides, $key . '.' . $subKey, $subValue);
                }
                continue;
            }
            self::applyUserOverride($overrides, (string) $key, $value);
        }

        $admin->ext_fields = array_merge((array) $admin->ext_fields, [
            self::USER_CONFIG_KEY => $overrides,
        ]);

        $saved = $admin->save();

        if ($saved) {
            RequestContext::remove(self::CACHE_PREFIX . 'user:' . $adminId);
        }

        return (bool) $saved;
    }

    /**
     * 清除当前登录用户的用户级配置覆盖（恢复为网站级配置）
     *
     * @param array $keys 要清除的键（点号风格），留空则清除全部用户级配置
     * @return bool
     */
    public static function removeUserOverrides(array $keys = []): bool
    {
        $adminId = self::currentAdminId();
        if ($adminId <= 0) {
            return false;
        }

        $admin = \app\admin\model\Admin::where('id', $adminId)->find();
        if (!$admin) {
            return false;
        }

        $extFields = (array) $admin->ext_fields;
        $overrides = Arr::get($extFields, self::USER_CONFIG_KEY, []);
        $overrides = is_array($overrides) ? $overrides : [];

        if (!$keys) {
            $overrides = [];
        } else {
            foreach ($keys as $key) {
                Arr::forget($overrides, $key);
            }
        }

        $extFields[self::USER_CONFIG_KEY] = $overrides;
        $admin->ext_fields = $extFields;

        $saved = (bool) $admin->save();

        if ($saved) {
            RequestContext::remove(self::CACHE_PREFIX . 'user:' . $adminId);
        }

        return $saved;
    }

    /**
     * 获取当前登录管理员 ID
     *
     * 从 Session 读取 admin_id（与 AdminService 登录时写入的 key 一致），
     * 方法内部自动获取，调用方无需传参。未登录或非后台环境时返回 0。
     *
     * @return int
     */
    public static function currentAdminId(): int
    {
        try {
            $id = (int) Session::get('admin_id');
            return $id > 0 ? $id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 写入单个覆盖项（带白名单校验）
     *
     * @param array  $overrides 引用传入的覆盖数组
     * @param string $path      点号路径，如 system.theme
     * @param mixed  $value     值
     * @return void
     */
    private static function applyUserOverride(array &$overrides, string $path, mixed $value): void
    {
        if (!in_array($path, self::USER_OVERRIDABLE, true)) {
            return;
        }

        // 空值视为「删除该覆盖项」，回退到网站级配置
        if ($value === '' || $value === null) {
            Arr::forget($overrides, $path);
            return;
        }

        Arr::set($overrides, $path, $value);
    }

    /**
     * 获取网站配置
     *
     * @return array
     */
    public static function getSiteConfig(): array
    {
        return [
            'site_name'        => self::get('site_name', '我的网站'),
            'site_title'       => self::get('site_title', ''),
            'site_keywords'    => self::get('site_keywords', ''),
            'site_description' => self::get('site_description', ''),
            'site_logo'        => self::get('site_logo', ''),
            'site_icp'         => self::get('site_icp', ''),
        ];
    }

    /**
     * 获取上传配置
     *
     * @return array
     */
    public static function getUploadConfig(): array
    {
        return [
            'upload_max_size' => (int) self::get('upload_max_size', 2),
            'upload_ext'      => self::get('upload_ext', 'jpg,png,gif'),
            'upload_type'     => self::get('upload_type', 'local'),
        ];
    }

    /**
     * 获取邮件配置
     *
     * @return array
     */
    public static function getEmailConfig(): array
    {
        return [
            'smtp_host'   => self::get('smtp_host', ''),
            'smtp_port'   => (int) self::get('smtp_port', 465),
            'smtp_user'   => self::get('smtp_user', ''),
            'smtp_pass'   => self::get('smtp_pass', ''),
            'smtp_secure' => self::get('smtp_secure', 'ssl'),
        ];
    }

    /**
     * 清空缓存
     *
     * @param string $module 模块名
     * @return void
     */
    public static function clearCache(string $module = 'all'): void
    {
        if ($module === 'all') {
            // 清除 RequestContext 中所有 config_service: 前缀的缓存
            $keys = ['system', 'upload', 'email', 'sms', 'site'];
            foreach ($keys as $k) {
                RequestContext::remove(self::CACHE_PREFIX . $k);
            }
            self::clearUserCache();
            WiseConfig::clearAllCache();
        } else {
            RequestContext::remove(self::CACHE_PREFIX . $module);
            self::clearUserCache();
            // 显式指定 namespace=模块名、group=system，避免依赖 ConfigManager 上残留的当前命名空间
            WiseConfig::clearCacheForGroup('system', $module);
        }
    }

    /**
     * 清除当前请求内缓存的用户级配置
     *
     * RequestContext 中用户级缓存 key 形如 config_service:module:user:{adminId}，
     * 遍历清理（不依赖具体 adminId）。
     *
     * @return void
     */
    private static function clearUserCache(): void
    {
        $prefix = self::CACHE_PREFIX . 'user:';
        foreach (RequestContext::all() as $key => $_) {
            if (str_starts_with((string) $key, $prefix)) {
                RequestContext::remove((string) $key);
            }
        }
    }
}
