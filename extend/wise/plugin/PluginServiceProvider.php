<?php

declare(strict_types=1);

namespace wise\plugin;

use think\Service;
use think\App;
use wise\support\context\ContextualLogger;
use wise\plugin\PluginContainerKeys;
use wise\plugin\PluginManager;
use wise\plugin\config\PluginConfig;
use wise\plugin\config\PluginConfigFacade;
use wise\plugin\config\driver\DatabaseConfigDriver;
use wise\plugin\config\driver\FileConfigDriver;
use wise\plugin\scanner\PluginScanner;
use wise\plugin\dependency\DependencyChecker;
use wise\plugin\market\LocalMarket;
use wise\plugin\market\HttpMarket;
use wise\plugin\command\lifecycle\PluginInstall;
use wise\plugin\command\lifecycle\PluginUninstall;
use wise\plugin\command\lifecycle\PluginUpgrade;
use wise\plugin\command\lifecycle\PluginReinstall;
use wise\plugin\command\lifecycle\PluginEnable;
use wise\plugin\command\lifecycle\PluginDisable;
use wise\plugin\command\lifecycle\PluginScan;
use wise\plugin\command\tool\PluginConfigCheck;
use wise\plugin\command\tool\PluginCreate;
use wise\plugin\command\tool\ConnectorCreate;
use wise\plugin\command\tool\MenuDerive;
use wise\plugin\command\tool\PluginAudit;
use wise\plugin\command\tool\PluginDev;
use wise\plugin\command\tool\PluginExtract;
use wise\plugin\command\tool\PluginPublish;
use wise\plugin\command\tool\PluginTest;
use wise\plugin\command\migrate\Run as MigrateRun;
use wise\plugin\command\migrate\Create as MigrateCreate;
use wise\plugin\command\migrate\Rollback as MigrateRollback;
use wise\plugin\command\migrate\Breakpoint as MigrateBreakpoint;
use wise\plugin\command\migrate\Status as MigrateStatus;
use wise\plugin\command\seed\Run as SeedRun;
use wise\plugin\command\seed\Create as SeedCreate;
use wise\plugin\command\tool\MigrationRegister;

/**
 * PluginServiceProvider 是插件管理器的主服务提供者
 *
 * 将核心服务注册到容器中，并建立新旧系统事件桥接。
 * 插件初始化已迁移至 app\middleware\PluginLoader 中间件，
 * 通过 ThinkPHP 标准中间件管道在请求时延迟初始化。
 */
class PluginServiceProvider extends Service
{
    /**
     * 注册服务到容器
     *
     * 所有插件管理器核心服务均以单例形式绑定，整个应用生命周期内共享同一实例。
     */
    public function register(): void
    {
        $configFile = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'plugin.php';
        if (file_exists($configFile)) {
            try{
                $defaultConfig = include $configFile;
                if (is_array($defaultConfig)) {
                    $appConfig = $this->app->config->get('plugin', []);
                    $this->app->config->set(array_replace_recursive($defaultConfig, $appConfig), 'plugin');
                }
            } catch (\Throwable $e) {
                ContextualLogger::error('Failed to load plugin config', ['message' => $e->getMessage()]);
            }
        }

        $this->app->bind(PluginConfig::class, function (App $app) {
            return $app->make(PluginContainerKeys::CONFIG);
        });

        $this->app->bind(PluginContainerKeys::SCANNER, function (App $app): PluginScanner {
            return new PluginScanner($app);
        });

        $this->app->bind(PluginContainerKeys::DEPENDENCY_CHECKER, function (App $app): DependencyChecker {
            return new DependencyChecker($app);
        });

        $this->app->bind(PluginContainerKeys::MANAGER, function (App $app): PluginManager {
            return new PluginManager($app);
        });

        $this->app->bind(PluginContainerKeys::CONFIG_DRIVER, function (App $app): DatabaseConfigDriver|FileConfigDriver {
            $config = $app->config->get('plugin', []);
            $driverName = $config['config_driver'] ?? 'database';

            if ($driverName === 'file') {
                $configDir = $config['config_dir'] ?? $app->getRootPath() . 'plugin_config';
                return new FileConfigDriver($configDir);
            }

            return new DatabaseConfigDriver();
        });

        $this->app->bind(PluginContainerKeys::CONFIG, function (App $app): PluginConfig {
            return new PluginConfig($app);
        });

        $this->app->bind(PluginContainerKeys::MARKET, function (App $app): LocalMarket|HttpMarket {
            $config = $app->config->get('plugin', []);
            $marketDriver = $config['market_driver'] ?? 'local';
            $marketConfig = $config['market_config'] ?? [];

            if ($marketDriver === 'http') {
                return new HttpMarket($marketConfig);
            }

            return new LocalMarket($app, $marketConfig);
        });


        $this->registerSystemCommands();

        $this->deferBootEnabledPlugins();

        $this->registerPluginConsoleCommands();
    }

    /**
     * 启动服务提供者
     *
     * 插件初始化已在 register() 阶段以 Console::starting() 回调形式延迟注册，
     * 此处无需重复处理。保留空实现以符合 Service 基类契约。
     */
    public function boot(): void
    {
    }

    /**
     * 将「初始化所有已启用插件」延迟到 Console::start() 阶段执行。
     *
     * 为什么必须延迟＼�：
     *   插件启用状态存于数据库（plugin_config.__state.enabled），读取链路为
     *   PluginManager::getCachedPlugins() → refreshEnabledState() → PluginConfig
     *   → DatabaseConfigDriver → PluginConfigModel（ORM Model）。
     *   无论 register() 还是 boot() 阶段读，ORM Model 都还没有数据库连接：
     *     register 阶段：`Unable to resolve NULL driver for [think\Cache]`
     *     boot     阶段：`Call to a member function connect() on null`（Model.php:408）
     *   根因是服务注册顺序 —— 本 Provider 在 services 数组第 2 位，而
     *   `think\service\ModelService`（建立模型 DB 连接）在第 8 位，**晚于本 Provider 的
     *   register() 与 boot()**。此时调 getAllPlugins() 会把全部插件判为 enabled=false，
     *   且 refreshEnabledState() 会把这份「全 false」固化进 enabledState
     *   （实测 register/boot 阶段均 10/10 全 N），后续再读也不会自愈。
     *
     *   Console::start() 在所有 service 的 register()/boot() 之后执行
     *   （见 think\Console::__construct：initialize → loadCommands → start），
     *   此刻 ORM 已就绪，启用状态可正确读取。
     *
     * 为什么不会无限递归（历史雷区）：插件 boot 链只做命名空间注册、配置合并、
     *   路由/事件/服务注册，**不解析 Console facade**（已核验 WiseBootServiceProvider
     *   与 wiseeditor 的 boot 链）。递归只发生在「回调内部再去解析 Console 实例」时；
     *   本回调只调 PluginManager::initServiceProvider()，不碰 Console。
     *
     * 时序（TP 8 控制台）：App::initialize() 跑完全部 register()+boot()
     *   → Console::loadCommands()（读 config/console.php）
     *   → Console::start() 顺序 flush startCallbacks：
     *        [本回调：插件初始化] → [registerPluginConsoleCommands 的命令注册]
     *   插件命名空间先就绪，命令声明的 class_exists 才能通过。
     */
    protected function deferBootEnabledPlugins(): void
    {
        \think\Console::starting(function (): void {
            $this->bootEnabledPlugins();
        });
    }

    /**
     * 注册系统级插件管理命令
     */
    protected function registerSystemCommands(): void
    {
        $this->commands([
            PluginInstall::class,
            PluginUninstall::class,
            PluginUpgrade::class,
            PluginReinstall::class,
            PluginEnable::class,
            PluginDisable::class,
            PluginScan::class,
            PluginConfigCheck::class,
            PluginCreate::class,
            ConnectorCreate::class,
            PluginAudit::class,
            PluginDev::class,
            PluginPublish::class,
            PluginTest::class,
            PluginExtract::class,
            MenuDerive::class,
            MigrateRun::class,
            MigrateCreate::class,
            MigrateRollback::class,
            MigrateBreakpoint::class,
            MigrateStatus::class,
            SeedRun::class,
            SeedCreate::class,
            MigrationRegister::class,
        ]);
    }

    /**
     * 初始化所有已启用插件（CLI 入口）
     *
     * 语义与 HTTP 侧 `wise\plugin\middleware\PluginLoader::bootPlugins()` 完全对齐：
     * 遍历全部已发现插件 → 跳过未启用 → 跳过库包硬依赖缺失 → `initServiceProvider()`。
     *
     * 为什么必须这样做＼�：
     *   历史上 CLI 侧只对「声明了 config/console.php 且 commands 非空」的插件调
     *   initServiceProvider()。后果是**无 console.php 的插件（如 wiseeditor）在 CLI 下
     *   服务提供者从不加载** —— 其容器绑定（`app('editor.manager')`）、事件监听、
     *   中间件白名单全部缺失。所有异步链路（队列 worker、定时任务、AI 生成、采集入库）
     *   都会静默降级：典型表现是 wisecms `HtmlSanitizer::resolveWhitelist()` 因取不到
     *   editor.manager 而退回内置白名单，保存编辑器内容时丢掉 colgroup/col/hr/rel。
     *
     * 为什么放在 boot() 阶段（实测结论，非常关键）：
     *   插件启用状态存于数据库（plugin_config.__state.enabled），读取链路为
     *   PluginManager::getCachedPlugins() → refreshEnabledState() → PluginConfig →
     *   DatabaseConfigDriver → PluginConfigModel（DB）。
     *   而在 register() 阶段 DB / 缓存服务尚未 boot：
     *     register 阶段：`Unable to resolve NULL driver for [think\Cache]`
     *     boot     阶段：查询正常（Y）
     *   故 register() 阶段调 getAllPlugins() 会把**全部插件**判为 enabled=false
     *   （实测 register 阶段 10/10 全 N）。
     *   这是本方法必须挂在 boot() 的第一个理由。
     *
     * 为什么挂 boot() 不会导致命令注册迟到：
     *   TP 8 控制台启动链 ——
     *     think 入口 → new App → console->run()
     *       → Console::__construct()
     *           → initialize()  → App::initialize()
     *                              → load() 注册全部 service 的 register()
     *                              → BootService initializer 调 App::boot()
     *                                 → 各 service 的 boot()（含本类 boot()）
     *           → loadCommands() → 读取 config/console.php 的命令
     *           → start()        → flush Console::$startCallbacks（此时才 addCommands 插件命令）
     *   即所有 boot() 都**早于** Console::start()，命令注册不迟到。
     *
     * 为什么不能放进 Console::starting 回调（历史雷区）：
     *   插件 boot 链内部会调用 Console facade，而 `Console::starting()` 在
     *   `Console::start()` 里才 flush，于是 boot 链再次触发 startCallbacks
     *   → 无限递归 → Allowed memory size exhausted。
     *   现在的做法：boot() 阶段同步完成 initServiceProvider()，只把**命令**交给
     *   `Service::commands()`（内部即 `Console::starting()`）延迟注册。
     *
     * 幂等性：initServiceProvider() 有 WiseBootServiceProvider::$booted 进程级去重，
     *   与 HTTP PluginLoader 双入口共存不会重复 boot。
     */
    protected function bootEnabledPlugins(): void
    {
        if (!$this->app->has(PluginContainerKeys::MANAGER)) {
            return;
        }

        try {
            $manager = $this->app->get(PluginContainerKeys::MANAGER);
            $plugins = $manager->getAllPlugins();

            foreach ($plugins as $name => $metadata) {
                if (!$metadata->isEnabled()) {
                    continue;
                }

                $missingPackages = $manager->missingPackages($metadata);

                if ($missingPackages !== []) {
                    $this->logPluginWarning(sprintf(
                        '插件 "%s" 依赖的库包未安装，已跳过加载：%s',
                        $name,
                        implode('、', array_map(
                            static fn (array $item): string => $item['package'],
                            $missingPackages
                        ))
                    ));

                    continue;
                }

                try {
                    $manager->initServiceProvider($name);
                } catch (\Throwable $e) {
                    $this->logPluginError(sprintf(
                        '[PluginServiceProvider] 初始化插件失败: %s —— %s: %s @ %s:%d',
                        $name,
                        get_class($e),
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ), $name);
                }
            }
        } catch (\Throwable $e) {
            $this->logPluginError(sprintf(
                '[PluginServiceProvider] 插件批量初始化失败: %s —— %s: %s @ %s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }
    }

    /**
     * 扫描 plugins/ 目录下各插件的 config/console.php，
     * 注册各插件自定义的 CLI 命令。
     *
     * ⚠️ 关键：整个解析过程**必须**延迟到 Console::start() 执行。
     *   console.php 里声明的命令类是否可解析，取决于插件的 PSR-4 命名空间是否已注册 ——
     *   而命名空间由 bootEnabledPlugins() 注册。若在 register() 阶段同步解析，
     *   class_exists() 必然为 false（命名空间尚未注册），全部命令会被判为 invalid
     *   而静默跳过（实测：wisecms 14 条命令全丢）。因此这里与插件初始化一样，
     *   通过 Console::starting() 延迟，并保证回调注册顺序在 bootEnabledPlugins 之后。
     *
     * 命令声明支持两种写法：
     *   映射：['wisecms:quality' => QualityRescoreCommand::class]
     *   列表：[QualityRescoreCommand::class, QualityRescoreCommand::class, ...]
     */
    protected function registerPluginConsoleCommands(): void
    {
        \think\Console::starting(function (\think\Console $console): void {
            $this->doRegisterPluginConsoleCommands($console);
        });
    }

    /**
     * 实际的插件命令注册实现（由 Console::start() 阶段调用）。
     *
     * @param \think\Console $console 由启动回调传入的当前 Console 实例
     *                                （不可改用 $this->app->console —— 那是容器解析，
     *                                可能与正在启动的实例不是同一个，命令会注册到别处）
     */
    protected function doRegisterPluginConsoleCommands(\think\Console $console): void
    {
        $pluginsPath = plugin_path();
        if (!is_dir($pluginsPath)) {
            return;
        }

        $pluginDirs = glob($pluginsPath . '*', GLOB_ONLYDIR);
        if (empty($pluginDirs)) {
            return;
        }

        foreach ($pluginDirs as $pluginDir) {
            $configFile = $pluginDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'console.php';

            if (!is_file($configFile)) {
                continue;
            }

            try {
                $config = include $configFile;
                $config = is_array($config) ? $config : null;

                if ($config === null || empty($config['commands']) || !is_array($config['commands'])) {
                    continue;
                }

                $pluginIdent = basename($pluginDir);

                $commands = [];
                $invalid  = [];

                foreach ($config['commands'] as $name => $class) {
                    if (!is_string($class) || $class === '') {
                        $invalid[] = is_scalar($class) ? (string) $class : get_debug_type($class);
                        continue;
                    }

                    if (!class_exists($class) || !is_subclass_of($class, \think\console\Command::class)) {
                        $invalid[] = $class;
                        continue;
                    }

                    if (is_string($name) && $name !== '') {
                        $commands[$pluginIdent . ':' . preg_replace(
                            '/^' . preg_quote($pluginIdent, '/') . ':/',
                            '',
                            $name
                        )] = $class;

                        continue;
                    }

                    $commands[] = $class;
                }

                if ($invalid !== []) {
                    $this->logPluginWarning(sprintf(
                        '[PluginServiceProvider] 插件 %s 的命令声明已跳过（类不存在或非 think\console\Command 子类）：%s',
                        basename($pluginDir),
                        implode('、', $invalid)
                    ));
                }

                if ($commands === []) {
                    continue;
                }

                $console->addCommands($commands);
            } catch (\Throwable $e) {
                $this->logPluginError(sprintf(
                    '[PluginServiceProvider] 注册插件命令失败: %s —— %s: %s @ %s:%d',
                    basename($pluginDir),
                    get_class($e),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ), basename($pluginDir));
            }
        }
    }

    /**
     * 插件日志「安全写」封装 —— 日志系统不可用时静默降级。
     *
     * 本类在 register() 阶段执行，此时 app 的 log 服务可能尚未完成 boot
     * （`$app->log` 依赖 LogService 的 boot 链），直接调用有抛错风险；日志失败
     * 不应反过来影响插件加载。
     */
    protected function logPluginWarning(string $message): void
    {
        try {
            ContextualLogger::warning($message);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 插件错误日志「安全写」封装（含插件名 context）
     */
    protected function logPluginError(string $message, string $plugin = ''): void
    {
        try {
            ContextualLogger::error($message, $plugin === '' ? [] : ['plugin' => $plugin]);
        } catch (\Throwable $e) {
        }
    }

}
