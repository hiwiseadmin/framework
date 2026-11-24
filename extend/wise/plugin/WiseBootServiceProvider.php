<?php

declare(strict_types=1);

namespace wise\plugin;

use wise\support\tools\DirFile;
use think\Service;
use think\facade\Event;
use wise\plugin\config\PluginConfig;
use wise\support\context\ContextualLogger;
use think\facade\Config;
use think\facade\Route;
use think\facade\Middleware;

/**
 * 统一插件启动基类 — 所有插件 BootServiceProvider 的唯一继承点。
 *
 * 提供完整的 11 步 boot 链，继承自 think\Service。
 * 插件通过 PluginManager::initServiceProvider() → app->register/bootService() 完成初始化。
 *
 * boot 链流程：
 *   1. copyStaticFiles（debug 模式）
 *   2. loadConfig（加载 config/*.php 到 Config domain，并平铺 app.php 到域根）
 *   2b. injectRuntimeConfig（注入用户运行时配置，优先级最高）
 *   2c. mergeGlobalConfig（按 $globalConfigMap 白名单把插件配置并入全局配置域）
 *   3. loadCommonFile（加载公共函数）
 *   4. registerNamespace（PSR-4 命名空间）
 *   5. registerVendor（第三方命名空间，从 manifest.json 的 vendor 读取）
 *   6. registerCommands（CLI 命令）
 *   7. loadEventFile（事件绑定/监听/订阅）
 *   8. loadMiddleware（中间件注册）
 *   9. registerRoute（路由注册，按请求类型选择 api/index/plugin）
 *  10. registerProviders（DI 绑定）
 *  11. registerServices（服务注册）
 *
 * 配置优先级（同名键）：
 *   用户在插件中心保存的值（plugin_config 表）
 *     > config/app.php 的静态值
 *     > 业务代码内联的默认值
 *
 * 使用示例：
 *
 *   class BootServiceProvider extends \wise\plugin\WiseBootServiceProvider
 *   {
 *       public function register(): void
 *       {
 *           $this->mergeConfig($this->getPluginIdent());
 *       }
 *   }
 */
abstract class WiseBootServiceProvider extends Service
{
    /** @var string 插件标识 */
    private $pluginIdent = '';

    /** @var string 插件目录 */
    private $pluginDir = '';

    /** @var array 提供的服务标识列表 */
    private $providers = [];

    /** @var array<string, true> 进程内已完成 boot 的插件（跨请求共享，Swoole 下避免重复初始化） */
    private static array $booted = [];

    /**
     * 允许并入「全局配置域」的插件配置白名单。
     *
     * 键 = 全局域名（对应 src/config/{domain}.php），值 = 该域内需按「逗号并集」
     * 合并的键名列表（未列出的键按插件值直接覆盖全局值）。
     *
     * 目的：让插件在不改动 src/config/*.php 的前提下，向全局配置域贡献配置项，
     * 从而既保持「框架零耦合」，又沿用框架/引擎的原生机制（如 view.taglib_pre_load
     * 是 think-template 的标准配置，引擎会自动解析，无需任何引擎层改造）。
     *
     * 用法：插件把配置写在 config/{domain}.php，再在自身 BootServiceProvider 声明域名：
     *
     *   protected array $globalConfigMap = ['view' => ['taglib_pre_load']];
     *
     * ⚠️ 时机约束：本机制只在 boot() 阶段写入，故**仅适用于「boot 之后才被读取」的配置域**。
     *    - view  域 ✅ 首次 View::fetch() 构造驱动时才快照（见 app/common/view/driver/Think.php）
     *    - lang  域 ❌ 禁止加入：lang.extend_list 在 App::initialize() 第 2 步
     *      loadLangPack() 即被消费并冻结（Lang 按 range 缓存），而插件 boot 发生在
     *      中间件阶段（更晚）。插件语言包请走 LangPack 的目录发现（plugins/{ident}/lang）。
     *
     * @var array<string, array<int, string>>
     */
    protected array $globalConfigMap = [];

    /**
     * 返回此服务提供者提供的服务标识列表。
     */
    public function getProviders(): array
    {
        if (empty($this->providers)) {
            $pluginDir = $this->getPluginDir();
            if (is_file($pluginDir . 'provider.php')) {
                try {
                    $providers = include $pluginDir . 'provider.php';
                    if (!empty($providers) && is_array($providers)) {
                        $this->providers = $providers;
                    }
                } catch (\Throwable $e) {
                    ContextualLogger::error($e->getMessage());
                }
            }
        }
        return $this->providers;
    }

    /**
     * 注册插件服务。
     */
    public function register()
    {
    }

    /**
     * 绑定插件服务到容器。
     *
     * @param string $provider 服务标识
     * @param string $class 服务类名
     * @return bool 是否成功绑定
     */
    protected function bind($provider, $class): bool
    {
        $app = $this->app;
        $pluginIdent = $this->getPluginIdent();
        if ($app->has($provider)) {
            return false;
        }
        $app->bind($provider, function () use ($class, $app, $pluginIdent) {
            $this->app->get(PluginContainerKeys::MANAGER)->initServiceProvider($pluginIdent);
            return $app->make($class);
        });
        return true;
    }

    /**
     * 插件启动时执行的 11 步初始化操作。
     *
     * 进程级幂等：同一插件在一个 worker 进程内仅完整 boot 一次。
     * 插件可能被 PluginLoader（HTTP 首请求）与 PluginServiceProvider（CLI 命令）
     * 多处触发 initServiceProvider()，若不幂等，common.php 二次 include 会触发
     * "Cannot redeclare" fatal，路由/事件/中间件也会重复注册。
     */
    public function boot()
    {
        $pluginIdent = $this->getPluginIdent();

        if (isset(self::$booted[$pluginIdent])) {
            return;
        }

        $pluginDir = $this->getPluginDir();

        if (config('app.debug')) {
            $this->copyStaticFiles($pluginDir, $pluginIdent);
        }

        $this->loadConfig($pluginDir, $pluginIdent);

        $this->injectRuntimeConfig($pluginIdent);

        $this->mergeGlobalConfig($pluginIdent);

        $this->loadCommonFile($pluginDir);
        $this->registerNamespace($pluginDir, $pluginIdent);
        $this->registerVendor($pluginDir);
        $this->loadEventFile($pluginDir);
        $this->loadMiddleware($pluginDir);
        $this->registerRoute($pluginDir, $pluginIdent);
        $this->registerProviders($pluginDir);
        $this->registerServices($pluginDir);

        self::$booted[$pluginIdent] = true;
    }

    /**
     * 重置进程级 boot 标记（供管理命令/测试用例在进程内重新初始化插件时调用）。
     */
    public static function resetBootState(): void
    {
        self::$booted = [];
    }


    /**
     * 获取插件清单（manifest.json）。
     *
     * @return array<string, mixed>
     */
    protected function getManifest(): array
    {
        $pluginDir = $this->getPluginDir();
        $manifestFile = $pluginDir . 'manifest.json';
        if (file_exists($manifestFile)) {
            $content = file_get_contents($manifestFile);
            if ($content !== false) {
                $manifest = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($manifest)) {
                    return $manifest;
                }
            }
        }
        return [];
    }

    /**
     * 获取插件目录路径。
     */
    protected function getPluginDir(): string
    {
        if (empty($this->pluginDir)) {
            if (!empty($this->pluginIdent)) {
                $this->pluginDir = plugin_path() . $this->pluginIdent . DIRECTORY_SEPARATOR;
            } else {
                $reflection = new \ReflectionClass($this);
                $this->pluginDir = dirname($reflection->getFileName()) . DIRECTORY_SEPARATOR;
                $this->pluginIdent = basename($this->pluginDir);
            }
        }
        return $this->pluginDir;
    }

    /**
     * 获取插件标识。
     */
    protected function getPluginIdent(): string
    {
        if (empty($this->pluginIdent)) {
            $reflection = new \ReflectionClass($this);
            $this->pluginDir = dirname($reflection->getFileName()) . DIRECTORY_SEPARATOR;
            $this->pluginIdent = basename($this->pluginDir);
        }
        return $this->pluginIdent;
    }


    /**
     * 合并插件配置到 Config domain（供插件 register() 中调用）。
     *
     * 本方法在类文档与 BootServiceProvider.stub 模板中被推荐使用，
     * 且已有插件（如 pipe-game）在 register() 中调用它 ——
     * 但历史上基类从未实现该方法，一旦这些插件被安装就会触发
     * "Call to undefined method" 致命错误。
     *
     * 语义：加载 config/*.php → 平铺 app.php → 注入用户运行时配置。
     * 与 boot() 中的完整流程等价，可安全重复调用（幂等）。
     *
     * @param string $pluginIdent 插件标识
     * @return void
     */
    protected function mergeConfig(string $pluginIdent): void
    {
        $pluginDir = $this->getPluginDir();

        $this->loadConfig($pluginDir, $pluginIdent);
        $this->injectRuntimeConfig($pluginIdent);
        $this->mergeGlobalConfig($pluginIdent);
    }

    /**
     * 注册回收站模型（插件侧一行接入）
     *
     * 三种形态：
     *   - null    → 读插件自身配置 {$ident}.recycle.models
     *   - string  → 读 {$ident}.{$config}.models（自定义配置域）
     *   - array   → 直接注册，不读配置
     *
     * 插件只需在 `config/recycle.php` 写 `return ['models' => [...]]`，
     * 然后在自身 BootServiceProvider 里调用一次本方法（通常放在 boot 末尾）。
     * 空清单直接 return，不留空实现。
     *
     * @param array|string|null $config
     * @param int               $priority 同扩展点多个来源时的优先级
     */
    protected function registerRecycle(array|string|null $config = null, int $priority = 100): void
    {
        $ident = $this->getPluginIdent();

        $models = is_array($config)
            ? $config
            : (array) config($ident . '.' . (is_string($config) ? $config : 'recycle') . '.models', []);

        if (empty($models)) {
            return;
        }

        if (!class_exists(\wise\support\ExtPoint::class) || !class_exists(\wise\support\ExtPoints::class)) {
            return;
        }

        \wise\support\ExtPoint::register(
            \wise\support\ExtPoints::RECYCLE_PROVIDER,
            $ident,
            static fn () => $models,
            $priority
        );
    }

    /**
     * Step 2c: 按 $globalConfigMap 白名单把插件配置并入全局配置域。
     *
     * 数据来源：loadConfig() 已按「文件名作子键」规则把 config/{domain}.php
     * 载入 config('{ident}.{domain}')，故此处无需二次 include 文件。
     *
     * 合并语义：
     *   - 借助 think\Config::set() 自身的 array_merge 语义（Config.php:201-217）：
     *     只覆盖同名键，不会清空全局域（如 view 域的 type / tpl_replace_string）其它配置项；
     *   - 白名单中声明为「并集键」的键（如 taglib_pre_load）取逗号并集并去重，
     *     避免多插件互相覆盖（think-template 支持以逗号分隔的多个标签库）。
     *
     * 幂等：boot() 已有进程级幂等；且并集键为集合运算，重复执行结果不变，
     * 故 register() 中调用 mergeConfig() 的场景同样安全。
     *
     * @param string $pluginIdent 插件标识
     * @return void
     */
    protected function mergeGlobalConfig(string $pluginIdent): void
    {
        foreach ($this->globalConfigMap as $domain => $appendKeys) {
            $domain = (string) $domain;

            $pluginConfig = Config::get("{$pluginIdent}.{$domain}", null);

            if (!is_array($pluginConfig) || $pluginConfig === []) {
                continue;
            }

            if (is_array($appendKeys) && $appendKeys !== []) {
                $global = Config::get($domain, []);

                foreach ($appendKeys as $key) {
                    $key = (string) $key;

                    if (!array_key_exists($key, $pluginConfig)) {
                        continue;
                    }

                    $pluginConfig[$key] = $this->mergeCommaList(
                        is_array($global) ? ($global[$key] ?? '') : '',
                        $pluginConfig[$key]
                    );
                }
            }

            Config::set($pluginConfig, $domain);
        }
    }

    /**
     * 逗号分隔配置项的幂等并集：全局已有项在前、插件新增项在后，去重、去空白。
     *
     * @param mixed $current 全局域当前值
     * @param mixed $incoming 插件声明值
     * @return string
     */
    private function mergeCommaList($current, $incoming): string
    {
        $parse = static function ($value): array {
            $items = is_array($value) ? $value : explode(',', (string) $value);

            return array_values(array_filter(
                array_map(static fn ($item) => trim((string) $item), $items),
                static fn ($item) => $item !== ''
            ));
        };

        return implode(',', array_values(array_unique(array_merge($parse($current), $parse($incoming)))));
    }

    /**
     * Step 1: 复制静态资源（仅 debug 模式）
     * @param string $pluginDir
     * @param string $pluginIdent
     */
    protected function copyStaticFiles($pluginDir, $pluginIdent): void
    {
        $staticDir = $pluginDir . 'statics' . DIRECTORY_SEPARATOR;
        $publicDir = public_path() . 'statics/plugins' . DIRECTORY_SEPARATOR . $pluginIdent . DIRECTORY_SEPARATOR;

        if (is_dir($staticDir) && !$this->compareDirs($staticDir, $publicDir)) {
            DirFile::copyDirs($staticDir, $publicDir);
        }
    }

    /**
     * Step 2: 加载插件配置（config/*.php → Config domain）。
     *
     * 装载规则（两种写法并存，互不覆盖）：
     *   a) 按文件名作子键：config/app.php → config('ident.app.xxx')
     *      —— 存量代码（如 shortlink_config() 读 'shortlink.app.xxx'）依赖此写法。
     *   b) 主配置 app.php 平铺到域根：config('ident.xxx')
     *      —— 业务代码的主流写法。修复前 loadConfig 只做 a)，导致
     *         config('wisecms.site_name') 恒读不到，业务全部回落默认值。
     *
     * @param string $pluginDir
     * @param string $pluginIdent
     */
    protected function loadConfig($pluginDir, $pluginIdent): void
    {
        $configDir = $pluginDir . 'config' . DIRECTORY_SEPARATOR;
        if (is_dir($configDir)) {
            $configFiles = glob($configDir . '*.php');
            if ($configFiles === false) {
                $configFiles = [];
            }
            try {
                foreach ($configFiles as $configFile) {
                    $configName = pathinfo($configFile, PATHINFO_FILENAME);
                    $config = include $configFile;
                    if (is_array($config)) {
                        Config::set([$configName => $config], $pluginIdent);
                    }
                }

                $this->flattenAppConfig($pluginIdent);
            } catch (\Throwable $e) {
                ContextualLogger::error($e->getMessage());
                if (config('app.debug')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * 把插件主配置 app.php 的内容平铺到插件域根。
     *
     * 使 config('ident.site_name') 与 config('ident.app.site_name') 等价，
     * 兼容业务代码中大量使用的扁平写法。
     *
     * 同名子键优先（如 config/console.php 存在于域根时不被覆盖）。
     *
     * @param string $pluginIdent 插件标识
     * @return void
     */
    protected function flattenAppConfig(string $pluginIdent): void
    {
        $app = Config::get("{$pluginIdent}.app", null);

        if (!is_array($app)) {
            return;
        }

        foreach ($app as $key => $value) {
            $key = (string) $key;

            if (Config::has("{$pluginIdent}.{$key}")) {
                continue;
            }

            Config::set([$key => $value], $pluginIdent);
        }
    }

    /**
     * 把用户在插件中心保存的运行时配置注入 Config domain（最高优先级）。
     *
     * 数据来源：manifest.json 的 config 声明（schema）⊕ plugin_config 表（用户值）。
     * 优先级：用户运行时值 > config/app.php 静态值 > 业务代码内联默认值。
     *
     * 同时写入两处，保证两种读取写法都生效：
     *   - 域根：config('ident.site_name')
     *   - app 子键：config('ident.app.site_name')（仅在该子键已存在时写）
     *
     * 容错：plugin_config 表未迁移、或插件未声明 config 时静默跳过，
     * 绝不能因配置读取失败阻断插件启动。
     *
     * @param string $pluginIdent 插件标识（目录名）
     * @return array<string, mixed> 实际注入的配置值
     */
    protected function injectRuntimeConfig(string $pluginIdent): array
    {
        try {
            $manager = $this->app->get(PluginContainerKeys::MANAGER);

            $pluginConfig = $this->app->get(PluginContainerKeys::CONFIG);

            $schema = $manager->getPluginConfigSchema($pluginIdent);
            if ($schema === []) {
                return [];
            }

            $values = $pluginConfig->values($pluginIdent, $schema);
            if ($values === []) {
                return [];
            }

            $hasAppSubKey = Config::has("{$pluginIdent}.app");

            foreach ($values as $key => $value) {
                $key = (string) $key;

                Config::set([$key => $value], $pluginIdent);

                if ($hasAppSubKey) {
                    $app = Config::get("{$pluginIdent}.app", []);
                    if (is_array($app)) {
                        $app[$key] = $value;
                        Config::set(['app' => $app], $pluginIdent);
                    }
                }
            }

            return $values;
        } catch (\Throwable $e) {
            ContextualLogger::warning(sprintf(
                '插件 "%s" 运行时配置注入失败：%s',
                $pluginIdent,
                $e->getMessage()
            ));

            return [];
        }
    }

    /**
     * Step 3: 加载公共函数文件。
     * @param string $pluginDir 插件目录
     */
    protected function loadCommonFile($pluginDir): void
    {
        $commonFile = $pluginDir . 'common.php';
        if (file_exists($commonFile)) {
            include $commonFile;
        }
    }

    /**
     * Step 4: 注册 PSR-4 命名空间。
     *
     * 委托给 NamespaceRegistrar，与其保持行为一致 —— 除 addPsr4() 外还需清空
     * Composer 的「类不存在」负缓存：请求会先经过 app\plugin\middleware\PluginAdminAuth，
     * 它在插件命名空间注册前就会 class_exists() 探测控制器类并污染该缓存，
     * 不清理会导致后续 class_exists() 恒为 false（PluginDispatch 报 403）。
     *
     * @param string $pluginDir 插件目录
     * @param string $pluginIdent 插件标识
     */
    protected function registerNamespace($pluginDir, $pluginIdent): void
    {
        (new \wise\plugin\adapter\NamespaceRegistrar())->register($pluginIdent, $pluginDir);
    }

    /**
     * Step 5: 注册第三方命名空间（从 manifest.json 的 vendor 读取）
     * @param string $pluginDir 插件目录
     */
    protected function registerVendor($pluginDir): void
    {
        $loader = require root_path() . 'vendor/autoload.php';
        $prefixes = $loader->getPrefixesPsr4();
        $manifest = $this->getManifest();
        $namespace = $manifest['vendor'] ?? [];
        if (!empty($namespace)) {
            foreach ($namespace as $ns => $path) {
                $nsString = is_int($ns) ? $path : $ns;
                if (!isset($prefixes[$nsString])) {
                    $actualPath = is_int($ns)
                        ? $pluginDir . 'extend' . DIRECTORY_SEPARATOR
                        : $pluginDir . ltrim($path, '/\\');
                    $loader->addPsr4($nsString, $actualPath);
                }
            }
        }
    }

    /**
     * Step 6: 注册 CLI 命令（备用路径）。
     *
     * 与 PluginServiceProvider::registerPluginConsoleCommands 对齐：
     *   - 校验声明项确为 think\console\Command 子类，否则记 warning 并跳过，
     *     避免「声明了却没生效且无反馈」；
     *   - 文本键 / 数字键两类都注册：
     *       文本键：以 key 作为命令名直接注册（不拼插件前缀）；
     *       数字键：直接入列，交给 Console::addCommands 走
     *              「名字留空 → 用命令自身 setName」语义（见 think\Console::addCommand）；
     *   - 本方法不做任何命令前缀处理（前缀由 registerPluginConsoleCommands 负责）。
     *
     * 当前插件命令的实际注册由 registerPluginConsoleCommands 完成，
     * 本方法保留为备用注册路径。
     */
    protected function registerCommands(string $pluginIdent): void
    {
        $commands = config("{$pluginIdent}.console.commands", []);
        if (empty($commands) || !is_array($commands)) {
            return;
        }

        $valid   = [];
        $invalid = [];

        foreach ($commands as $key => $command) {
            if (!is_string($command) || $command === '') {
                $invalid[] = is_scalar($command) ? (string) $command : get_debug_type($command);
                continue;
            }

            if (!class_exists($command) || !is_subclass_of($command, \think\console\Command::class)) {
                $invalid[] = $command;
                continue;
            }

            if (is_string($key) && $key !== '') {
                $valid[$key] = $command;
            } else {
                $valid[] = $command;
            }
        }

        if ($invalid !== []) {
            ContextualLogger::warning(sprintf(
                '[WiseBootServiceProvider] 插件 %s 的命令声明已跳过（类不存在或非 think\console\Command 子类）：%s',
                $pluginIdent,
                implode('、', $invalid)
            ));
        }

        if ($valid !== []) {
            $this->commands($valid);
        }
    }

    /**
     * Step 7: 加载事件文件（bind/listen/subscribe）。
     */
    protected function loadEventFile($pluginDir): void
    {
        $eventFile = $pluginDir . 'event.php';
        if (file_exists($eventFile)) {
            try {
                $events = include $eventFile;

                if (isset($events['bind']) && is_array($events['bind'])) {
                    Event::bind($events['bind']);
                }

                if (isset($events['listen']) && is_array($events['listen'])) {
                    Event::listenEvents($events['listen']);
                }

                if (isset($events['subscribe']) && is_array($events['subscribe'])) {
                    Event::subscribe($events['subscribe']);
                }
            } catch (\Throwable $e) {
                ContextualLogger::error($e->getMessage());
                if (config('app.debug')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Step 8: 加载中间件配置。
     */
    protected function loadMiddleware($pluginDir): void
    {
        $middlewareFile = $pluginDir . 'middleware.php';
        if (file_exists($middlewareFile)) {
            try {
                $middlewares = include $middlewareFile;
                if (is_array($middlewares)) {
                    foreach ($middlewares as $middleware) {
                        Middleware::add($middleware);
                    }
                }
            } catch (\Throwable $e) {
                ContextualLogger::error($e->getMessage());
                if (config('app.debug')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Step 9: 注册路由。
     *
     * 三类路由文件，语义不同，**不能再用「当前请求是不是默认插件」二选一**：
     *
     *   - route/api.php    → /api/** 专用（无 plugin/ 前缀）
     *   - route/index.php  → 前台路由，规则自带前缀（cms/、s/、jump/ ...），
     *                        与「谁是默认插件」无关，必须**始终**注册
     *   - route/plugin.php → 插件后台自定义路由（plugin/{ident} 前缀）
     *
     * 历史缺陷＼�：
     *   旧实现只在 `request->plugin['defaultPlugin'] === true` 时注册 index.php，
     *   而该标记由 IndexDispatch 在**路由匹配之后**才写入 —— 插件 boot 发生在
     *   全局中间件阶段（PluginLoader，早于路由匹配），此时标记必然为 false，
     *   于是 /cms/** 永远命中不到，被 /cms/<c> 兜底当成控制器名 → 403。
     *   后果：前台只剩 IndexDispatch 的回退路径可用，主题生成的 /cms/** 链接全死。
     */
    protected function registerRoute($pluginDir, $pluginIdent): void
    {
        $isApi = $this->app->request->controller(true) == 'api';

        if ($isApi) {
            $this->includeRouteFile($pluginDir . 'route/api.php');
            return;
        }

        $this->includeRouteFile($pluginDir . 'route/index.php');

        $this->includeRouteFile($pluginDir . 'route/plugin.php', 'plugin/' . $pluginIdent);
    }

    /**
     * 加载并注册一个路由文件。
     *
     * @param string      $routeFile 路由文件绝对路径
     * @param string|null $group     路由分组前缀（null = 不加前缀）
     */
    protected function includeRouteFile(string $routeFile, ?string $group = null): void
    {
        if (!is_file($routeFile)) {
            return;
        }

        try {
            if ($group === null) {
                Route::group(function () use ($routeFile) {
                    include $routeFile;
                });
            } else {
                Route::group($group, function () use ($routeFile) {
                    include $routeFile;
                });
            }
        } catch (\Throwable $e) {
            ContextualLogger::error($e->getMessage());
            if (config('app.debug')) {
                throw $e;
            }
        }
    }

    /**
     * Step 10: 注册 DI 绑定（provider.php）。
     */
    protected function registerProviders($pluginDir): void
    {
        if (is_file($pluginDir . 'provider.php')) {
            try {
                $providers = include $pluginDir . 'provider.php';
                if (!empty($providers) && is_array($providers)) {
                    foreach ($providers as $key => $provider) {
                        if (!$this->app->has($key)) {
                            $this->app->bind($key, $provider);
                        }
                    }
                }
            } catch (\Exception $e) {
                ContextualLogger::error($e->getMessage());
            }
        }
    }

    /**
     * Step 11: 注册子服务提供者（service.php）。
     */
    protected function registerServices($pluginDir): void
    {
        if (is_file($pluginDir . 'service.php')) {
            try {
                $services = include $pluginDir . 'service.php';
                if (!empty($services) && is_array($services)) {
                    foreach ($services as $class) {
                        if (is_string($class) && class_exists($class)) {
                            $service = new $class($this->app);
                            $this->app->register($service);
                            $this->app->bootService($service);
                        } elseif ($class instanceof \Closure) {
                            call_user_func($class, $this->app);
                        }
                    }
                }
            } catch (\Throwable $e) {
                ContextualLogger::error($e->getMessage());
            }
        }
    }

    /**
     * 比较两个目录是否相同。
     */
    private function compareDirs($dir1, $dir2): bool
    {
        if (!is_dir($dir1) || !is_dir($dir2)) {
            return false;
        }
        $files1 = scandir($dir1);
        $files2 = scandir($dir2);
        return $files1 == $files2;
    }
}
