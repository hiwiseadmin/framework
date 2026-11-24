<?php
namespace app\common\service;

/**
 * 后台主题服务
 *
 * 主题由三个正交维度组成：
 *  - mode    明暗模式   light / dark / auto（auto 跟随系统 prefers-color-scheme）
 *  - scheme  颜色主题   blue / green / red / purple
 *  - sidebar 侧边栏模式 auto / dark / light（auto = 跟随 mode）
 *
 * 作用域规则（与 ConfigService 一致，靠 $isUser 主动区分）：
 *  - $isUser=false        → 网站级配置，全站共享
 *  - $isUser=true         → 用户级配置，网站配置打底 + ext_fields 覆盖（仅当前登录用户自己）
 *
 * 当前登录用户由 ConfigService 从 Session 自动获取，本服务不接收、不触碰
 * 其他用户的任何配置。
 *
 * 本服务只负责主题的领域规则（取值合法性、归一化、默认值），
 * 所有读写均委托 ConfigService 完成，不直接操作存储。
 */
class ThemeService
{
    public const MODE_LIGHT = 'light';
    public const MODE_DARK  = 'dark';
    public const MODE_AUTO  = 'auto';

    /** 默认明暗模式（暗色优先） */
    public const DEFAULT_MODE = self::MODE_DARK;

    public const SCHEME_BLUE   = 'blue';
    public const SCHEME_GREEN  = 'green';
    public const SCHEME_RED    = 'red';
    public const SCHEME_PURPLE = 'purple';

    /** 默认颜色主题 */
    public const DEFAULT_SCHEME = self::SCHEME_BLUE;

    public const SIDEBAR_AUTO  = 'auto';
    public const SIDEBAR_DARK  = 'dark';
    public const SIDEBAR_LIGHT = 'light';

    /** 默认侧边栏模式 */
    public const DEFAULT_SIDEBAR = self::SIDEBAR_AUTO;

    public const KEY_MODE    = 'system.theme';
    public const KEY_SCHEME  = 'system.scheme';
    public const KEY_SIDEBAR = 'system.sidebar_theme';

    /** 合法取值集合 */
    public const MODES    = [self::MODE_LIGHT, self::MODE_DARK, self::MODE_AUTO];
    public const SCHEMES  = [self::SCHEME_BLUE, self::SCHEME_GREEN, self::SCHEME_RED, self::SCHEME_PURPLE];
    public const SIDEBARS = [self::SIDEBAR_AUTO, self::SIDEBAR_DARK, self::SIDEBAR_LIGHT];

    /** localStorage 存储键 */
    public const STORAGE_KEY = 'wise.theme';

    /** Cookie 存储键（供服务端首屏直出读取） */
    public const COOKIE_KEY = 'ws_theme';

    /**
     * 归一化明暗模式
     *
     * @param mixed $mode
     * @return string
     */
    public static function normalizeMode(mixed $mode): string
    {
        return in_array($mode, self::MODES, true) ? $mode : self::DEFAULT_MODE;
    }

    /**
     * 归一化颜色主题
     *
     * @param mixed $scheme
     * @return string
     */
    public static function normalizeScheme(mixed $scheme): string
    {
        return in_array($scheme, self::SCHEMES, true) ? $scheme : self::DEFAULT_SCHEME;
    }

    /**
     * 归一化侧边栏模式
     *
     * @param mixed $sidebar
     * @return string
     */
    public static function normalizeSidebar(mixed $sidebar): string
    {
        return in_array($sidebar, self::SIDEBARS, true) ? $sidebar : self::DEFAULT_SIDEBAR;
    }

    /**
     * 解析出最终生效的主题
     *
     * 优先级（由高到低）：
     *   1. $clientOverrides  —— 本浏览器 Cookie 中的最新选择（客户端即时写入，
     *      可覆盖「ext_fields 尚未落库」的防抖窗口，消除 iframe 单独刷新读到旧值的时序问题）
     *   2. 用户级覆盖          —— 当前登录用户已登录时读其 ext_fields
     *   3. 网站级配置          —— 系统默认
     *
     * 当前登录用户由 ConfigService 内部从 Session 自动获取。
     *
     * @param array|null $clientOverrides 客户端主题覆盖 {mode?, scheme?, sidebar?}，可为空
     * @return array{mode:string, scheme:string, sidebar:string}
     */
    public static function resolve(?array $clientOverrides = null): array
    {
        $isUser = ConfigService::currentAdminId() > 0;

        $theme = [
            'mode'    => self::normalizeMode(ConfigService::get(self::KEY_MODE,    self::DEFAULT_MODE,    'system', $isUser)),
            'scheme'  => self::normalizeScheme(ConfigService::get(self::KEY_SCHEME,  self::DEFAULT_SCHEME,  'system', $isUser)),
            'sidebar' => self::normalizeSidebar(ConfigService::get(self::KEY_SIDEBAR, self::DEFAULT_SIDEBAR, 'system', $isUser)),
        ];

        if (is_array($clientOverrides)) {
            $map = [
                'mode'    => [self::class, 'normalizeMode'],
                'scheme'  => [self::class, 'normalizeScheme'],
                'sidebar' => [self::class, 'normalizeSidebar'],
            ];
            foreach ($map as $field => $normalizer) {
                $raw = $clientOverrides[$field] ?? '';
                if ($raw !== '' && $raw !== null) {
                    $theme[$field] = call_user_func($normalizer, $raw);
                }
            }
        }

        return $theme;
    }

    /**
     * 保存主题配置
     *
     * 只处理传入的维度，未传入的维度保持不变。
     * 传入空字符串表示「清除该维度的用户级覆盖，回退网站配置」。
     *
     * 当前登录用户已登录 → 写用户级（仅当前用户自己）；未登录 → 写网站级。
     *
     * @param array $values 键为 mode / scheme / sidebar
     * @return array 保存后的完整主题
     */
    public static function save(array $values): array
    {
        $map = [
            'mode'    => [self::KEY_MODE,    'normalizeMode'],
            'scheme'  => [self::KEY_SCHEME,  'normalizeScheme'],
            'sidebar' => [self::KEY_SIDEBAR, 'normalizeSidebar'],
        ];

        $payload = [];
        foreach ($map as $field => [$key, $normalizer]) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $raw = $values[$field];
            $payload[$key] = ($raw === '' || $raw === null) ? '' : call_user_func([self::class, $normalizer], $raw);
        }

        if (!$payload) {
            return self::resolve();
        }

        if (ConfigService::currentAdminId() > 0) {
            ConfigService::setUser($payload);
        } else {
            foreach ($payload as $key => $value) {
                ConfigService::set($key, $value, [
                    'type'  => 'text',
                    'group' => 'theme',
                ], 'system', false);
            }
        }

        return self::resolve();
    }

    /**
     * 清除当前登录用户的主题覆盖，恢复为网站级配置
     *
     * @return array 恢复后的完整主题
     */
    public static function resetUser(): array
    {
        ConfigService::removeUserOverrides([
            self::KEY_MODE,
            self::KEY_SCHEME,
            self::KEY_SIDEBAR,
        ]);

        return self::resolve();
    }

    /**
     * 转换为模板可直接输出的 <html> data 属性
     *
     * - mode=auto 时服务端无法预知系统偏好，输出 dark 作为首屏基线，
     *   由前端引导脚本在首帧前据 prefers-color-scheme 修正（不产生闪烁）。
     * - sidebar=auto 时输出与 mode 一致的值，前端同理修正。
     *
     * @param array $theme resolve() 的返回值
     * @return array{bs_theme:string, color_scheme:string, ws_sidebar:string}
     */
    public static function toHtmlAttributes(array $theme): array
    {
        $mode    = $theme['mode'] === self::MODE_AUTO ? self::MODE_DARK : $theme['mode'];
        $sidebar = $theme['sidebar'] === self::SIDEBAR_AUTO ? $mode : $theme['sidebar'];

        return [
            'bs_theme'     => $mode,
            'color_scheme' => $theme['scheme'],
            'ws_sidebar'   => $sidebar,
        ];
    }

    /**
     * 获取前端引导脚本所需的主题上下文
     *
     * 该数组会被 JSON 注入到内联引导脚本中，供首帧前确定主题。
     * 当前登录用户由 ConfigService 内部从 Session 自动获取。
     *
     * @return array{mode:string, scheme:string, sidebar:string, editable:bool}
     */
    public static function toBootstrapContext(): array
    {
        $theme = self::resolve();

        return [
            'mode'     => $theme['mode'],
            'scheme'   => $theme['scheme'],
            'sidebar'  => $theme['sidebar'],
            'editable' => ConfigService::currentAdminId() > 0,
        ];
    }
}
