<?php

declare(strict_types=1);

namespace wise\plugin;

use think\App;
use wise\plugin\config\PluginSettings;
use wise\plugin\exception\PluginException;
use wise\plugin\exception\PluginNotFoundException;
use wise\plugin\lifecycle\PluginLifecycle;
use wise\plugin\lifecycle\PluginStateInspector;
use wise\plugin\listing\PluginListPresenter;
use wise\plugin\scanner\PluginMetadata;
use wise\plugin\scanner\PluginScanner;

/**
 * PluginManager 是插件子系统的**门面 + 注册表**。
 *
 * 职责（有意收窄）：
 * - 插件发现与请求级缓存（扫描结果 + 启用状态合并）—— 缓存的唯一持有者；
 * - 插件查询：getPlugin / getAllPlugins / isEnabled / resolveIdent；
 * - 服务提供者初始化：initServiceProvider / ensureNamespace；
 * - 库包依赖闸门：missingPackages / packageDependencyReport；
 * - 把生命周期 / 状态自检 / 列表展示 / 配置读写**委托**给 4 个职责类。
 *
 * 委托关系：
 * - {@see PluginLifecycle}       安装 / 卸载 / 启用 / 禁用 / 升级
 * - {@see PluginStateInspector}  安装状态自检、自愈、修复、清库
 * - {@see PluginListPresenter}   本地列表 / 单行 / 操作按钮 / 远程市场
 * - {@see PluginSettings}        配置 schema、读写、默认值播种
 *
 * 公开 API 与拆分前保持一致（仅移除了已废弃的 callHook / getPluginInstance /
 * 会员相关接线），因此所有调用方无需改动。
 */
class PluginManager extends PluginSupport
{
    /**
     * 插件状态：未安装（文件已就位，但数据库表尚未创建）。
     */
    public const STATE_NOT_INSTALLED = 'not_installed';

    /**
     * 插件状态：已安装但未启用。
     */
    public const STATE_INSTALLED = 'installed';

    /**
     * 插件状态：已安装且已启用。
     */
    public const STATE_ENABLED = 'enabled';

    /**
     * 插件状态：数据与状态不一致（需修复）。
     *
     * 两种场景（详见 PluginStateInspector::inspectInstallConflict()）：
     *   - residual：建表迁移声明过表、库里仍有残留，但状态说"未安装"
     *   - dropped ：状态说"已安装"，但声明的表一张都不在
     */
    public const STATE_INCONSISTENT = 'inconsistent';

    /**
     * 本地插件的默认分类（远程市场未匹配到时使用）。
     */
    public const LOCAL_CATEGORY = '本地';

    /**
     * 系统保留的插件标识。
     *
     * 这些词与应用目录（`src/app/{name}`）同名，作为插件标识会造成
     * 路由 / 视图 / 配置命名空间的歧义，因此禁止。
     *
     * 注意：`member` **不在**保留列表内 —— `src/app/member` 只是一个
     * 路由 + 中间件的壳（真正的实现在 `plugins/member`）。
     */
    protected const RESERVED_PLUGIN_IDENTS = [
        'admin', 'common', 'system', 'index', 'plugin', 'app', 'api',
    ];

    /**
     * 请求级扫描结果缓存。
     *
     * @var array<string, PluginMetadata>|null
     */
    protected ?array $cachedPlugins = null;

    /**
     * 请求级缓存：插件启用状态（插件名 => 是否启用）。
     *
     * 与 cachedPlugins（扫描文件结果）分离存储，避免把动态运行时状态
     * 固化到扫描缓存中。
     *
     * @var array<string, bool>|null
     */
    protected ?array $enabledState = null;

    /** @var PluginSettings|null */
    protected ?PluginSettings $settings = null;

    /** @var PluginStateInspector|null */
    protected ?PluginStateInspector $inspector = null;

    /** @var PluginLifecycle|null */
    protected ?PluginLifecycle $lifecycle = null;

    /** @var PluginListPresenter|null */
    protected ?PluginListPresenter $presenter = null;

    /**
     * @param App $app ThinkPHP 应用实例
     */
    public function __construct(App $app)
    {
        parent::__construct($app, $this);
    }


    /**
     * 验证插件名称以防止路径遍历并确保使用安全字符。
     *
     * @throws PluginException 如果名称包含无效字符
     */
    public static function validatePluginName(string $name): void
    {
        if (!self::checkPluginIdent($name)) {
            throw new PluginException(sprintf(
                '无效的插件名称 "%s"。插件标识须以字母或数字开头，仅含字母、数字、下划线和连字符（2~50 字符），且不能使用系统保留词（%s）。',
                $name,
                implode('、', self::RESERVED_PLUGIN_IDENTS)
            ));
        }
    }

    /**
     * 检查插件标识是否符合规范。
     *
     * **这是插件标识判定的唯一实现**：Web 后台与 CLI 都必须走这里 ——
     * 历史上前者用本方法、后者用 validatePluginName()，两套规则不一致，
     * 导致「命令行能装、后台报标识不符合规范」的分裂。
     *
     * 规则：字母或数字开头；仅含字母/数字/下划线/连字符；长度 2~50；不与保留词冲突。
     */
    public static function checkPluginIdent(string $pluginIdent): bool
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{1,49}$/', $pluginIdent) !== 1) {
            return false;
        }

        return !in_array(strtolower($pluginIdent), self::RESERVED_PLUGIN_IDENTS, true);
    }


    /**
     * 获取带缓存的所有插件列表。
     *
     * 首次调用时扫描并缓存插件元数据（文件级结果）；启用状态是动态运行时数据，
     * 每次调用都从数据库合并最新值（请求级缓存去重），否则服务启动阶段
     * （数据库尚未就绪）读到的 false 会被整个请求沿用。
     *
     * @return PluginMetadata[] 按插件名称索引
     */
    protected function getCachedPlugins(): array
    {
        $this->cachedPlugins ??= $this->getScanner()->scan();

        $this->refreshEnabledState();

        return $this->cachedPlugins;
    }

    /**
     * 合并插件的启用状态（请求级缓存）。
     *
     * 读取失败（DB 未就绪）时保持 enabledState 为 null，下次调用重新尝试 ——
     * 不能把「数据库不可用→false」固化进缓存。
     */
    protected function refreshEnabledState(): void
    {
        if ($this->enabledState !== null) {
            $this->applyEnabledState($this->enabledState);
            return;
        }

        try {
            $config = $this->getConfig();
            $states = [];
            foreach ($this->cachedPlugins as $name => $metadata) {
                $states[$name] = $config->get($name . '.__state.enabled', false) === true;
            }
            $this->enabledState = $states;
        } catch (\Throwable $e) {
            $this->enabledState = null;
            return;
        }

        $this->applyEnabledState($states);
    }

    /**
     * 将启用状态写回 PluginMetadata 缓存。
     *
     * @param array<string, bool> $states 插件名 => 是否启用
     */
    protected function applyEnabledState(array $states): void
    {
        foreach ($states as $name => $enabled) {
            if (isset($this->cachedPlugins[$name])) {
                $this->cachedPlugins[$name]->setEnabled($enabled);
            }
        }
    }

    /**
     * 清除请求级缓存（原先的 protected clearCache()，改为 public 供职责类调用）。
     */
    public function clearPluginCache(): void
    {
        $this->cachedPlugins = null;
        $this->enabledState = null;
    }

    /**
     * 强制刷新扫描器缓存（装/卸/升级后调用）。
     */
    public function refreshScannerCache(): void
    {
        $this->getScanner()->refreshCache();
    }

    /**
     * 诊断扫描缓存与磁盘清单指纹的一致性（只读，供 `plugin:dev` 使用）。
     *
     * 委托给 {@see PluginScanner::cacheDrift()}：缓存键、结构版本与指纹算法都封在
     * 扫描器内部，外部不得自行读缓存 —— 否则一改缓存结构就会各读各的。
     *
     * @return array{enabled:bool,state:string,current:array<string,string>,cached:array<string,string>|null,stale_plugins:array<string,string>}
     */
    public function scannerCacheDrift(): array
    {
        return $this->getScanner()->cacheDrift();
    }

    protected function getScanner(): PluginScanner
    {
        return $this->app->get(PluginContainerKeys::SCANNER);
    }


    /**
     * 获取指定插件的元数据。
     *
     * @throws PluginNotFoundException 如果未找到插件
     */
    public function getPlugin(string $name): PluginMetadata
    {
        static::validatePluginName($name);

        $plugins = $this->getCachedPlugins();

        if (!isset($plugins[$name])) {
            throw new PluginNotFoundException($name);
        }

        return $plugins[$name];
    }

    /**
     * 获取所有已发现的插件（含运行时启用状态）。
     *
     * @return PluginMetadata[] 按插件名称索引
     */
    public function getAllPlugins(): array
    {
        return $this->getCachedPlugins();
    }

    /**
     * 检查插件是否已启用。
     */
    public function isEnabled(string $name): bool
    {
        static::validatePluginName($name);

        if (!isset($this->getCachedPlugins()[$name])) {
            return false;
        }

        return $this->getStateManager()->isEnabled($name);
    }

    /**
     * 将插件标识或显示名（manifest 的 title，可能是中文）解析为插件标识。
     *
     * 前端列表渲染展示的是显示名，而生命周期操作需要的是目录名/标识。
     * 无法匹配时原样返回。
     */
    public function resolveIdent(string $nameOrTitle): string
    {
        $plugins = $this->getCachedPlugins();

        if ($nameOrTitle !== '' && isset($plugins[$nameOrTitle])) {
            return $nameOrTitle;
        }

        foreach ($plugins as $ident => $metadata) {
            if ($nameOrTitle === $metadata->getIdent()
                || $nameOrTitle === $metadata->getDisplayName()
                || $nameOrTitle === $metadata->getName()
            ) {
                return (string) $ident;
            }
        }

        return $nameOrTitle;
    }


    /**
     * 缺失的库包依赖（仅**硬依赖**，返回非空即表示该插件不应被加载）。
     *
     * @return array<string, array{package:string, constraint:string, required:bool, version:?string, message:string}>
     */
    public function missingPackages(PluginMetadata $metadata): array
    {
        return $this->packageDependencyChecker()->missingRequired($metadata);
    }

    /**
     * 库包依赖诊断（含软依赖，供插件列表 / CLI 展示）。
     *
     * @return array<string, array{package:string, constraint:string, required:bool, version:?string, message:string}>
     */
    public function packageDependencyReport(PluginMetadata $metadata): array
    {
        return $this->packageDependencyChecker()->missing($metadata);
    }


    /**
     * 初始化插件的服务提供者。
     *
     * 所有插件继承 WiseBootServiceProvider（think\Service），通过 TP 原生
     * register/bootService 完成完整初始化。
     */
    public function initServiceProvider(string $name): void
    {
        static::validatePluginName($name);

        $plugins = $this->getCachedPlugins();

        if (!isset($plugins[$name])) {
            return;
        }

        $metadata = $plugins[$name];

        $missingPackages = $this->missingPackages($metadata);

        if ($missingPackages !== []) {
            try {
                $this->app->log->warning(sprintf(
                    '插件 "%s" 依赖的库包未安装，已跳过加载：%s',
                    $name,
                    implode('、', array_map(
                        static fn (array $item): string => $item['message'],
                        $missingPackages
                    ))
                ));
            } catch (\Throwable $e) {
            }

            return;
        }

        $serviceProviderClass = $metadata->getServiceProvider();

        $pluginDir = $this->getPluginDirectory() . DIRECTORY_SEPARATOR . $name;
        $this->getNamespaceRegistrar()->register($name, $pluginDir);
        $this->getNamespaceRegistrar()->registerVendor($pluginDir, $metadata->getVendor());

        if ($serviceProviderClass === '') {
            throw new PluginException(sprintf(
                'Plugin "%s" has no service provider defined.',
                $name
            ));
        }

        if (!class_exists($serviceProviderClass)) {
            throw new PluginException(sprintf(
                'Plugin "%s" service provider class "%s" not found.',
                $name,
                $serviceProviderClass
            ));
        }

        $expectedPrefix = 'plugin\\' . $name . '\\';
        if (!str_starts_with($serviceProviderClass, $expectedPrefix)) {
            throw new PluginException(sprintf(
                'Plugin "%s" service provider "%s" is not in the expected namespace "%s".',
                $name,
                $serviceProviderClass,
                $expectedPrefix
            ));
        }

        $serviceProvider = new $serviceProviderClass($this->app);

        if (!$serviceProvider instanceof WiseBootServiceProvider) {
            throw new PluginException(sprintf(
                'Plugin "%s" service provider "%s" must extend WiseBootServiceProvider.',
                $name,
                $serviceProviderClass
            ));
        }

        $this->app->register($serviceProvider);
        $this->app->bootService($serviceProvider);
    }

    /**
     * 确保指定插件的 PSR-4 命名空间已注册（幂等；**不**触发插件完整 boot）。
     *
     * 用于请求中期需要 class_exists() 探测插件类的场景（如 PluginAdminAuth）。
     * 只注册命名空间，不加载 config / common.php / 事件 / 路由 / 中间件。
     */
    public function ensureNamespace(string $name): void
    {
        static::validatePluginName($name);

        $plugins = $this->getCachedPlugins();

        if (!isset($plugins[$name])) {
            return;
        }

        $pluginDir = $this->getPluginDirectory() . DIRECTORY_SEPARATOR . $name;
        $registrar = $this->getNamespaceRegistrar();

        $registrar->register($name, $pluginDir);
        $registrar->registerVendor($pluginDir, $plugins[$name]->getVendor());
    }


    /**
     * 安装插件。
     *
     * @param bool $withDemo     是否写入演示数据（仅对 manifest `seed: demo` 生效；
     *                           `seed: required` 恒执行，`seed: none` 永不执行）
     * @param bool $ignoreCompat 是否忽略 manifest `compatible_with` 声明（应急用）
     *
     * @see PluginLifecycle::install()
     */
    public function install(string $name, bool $withDemo = false, bool $ignoreCompat = false): PluginMetadata
    {
        return $this->lifecycle()->install($name, $withDemo, $ignoreCompat);
    }

    /**
     * @see PluginLifecycle::uninstall()
     */
    public function uninstall(string $name, bool $removeFiles = false, bool $purge = false): array
    {
        return $this->lifecycle()->uninstall($name, $removeFiles, $purge);
    }

    /**
     * @see PluginLifecycle::enable()
     */
    public function enable(string $name): void
    {
        $this->lifecycle()->enable($name);
    }

    /**
     * @see PluginLifecycle::disable()
     */
    public function disable(string $name): void
    {
        $this->lifecycle()->disable($name);
    }

    /**
     * @see PluginLifecycle::upgrade()
     */
    public function upgrade(
        string $name,
        string $newVersion,
        string $packageUrl = '',
        bool $ignoreCompat = false
    ): PluginMetadata {
        return $this->lifecycle()->upgrade($name, $newVersion, $packageUrl, $ignoreCompat);
    }


    /**
     * @see PluginStateInspector::isInstalled()
     */
    public function isInstalled(string $name, bool $heal = true): bool
    {
        return $this->inspector()->isInstalled($name, $heal);
    }

    /**
     * @see PluginStateInspector::getPluginState()
     */
    public function getPluginState(string $name): string
    {
        return $this->inspector()->getPluginState($name);
    }

    /**
     * @see PluginStateInspector::inspectInstallConflict()
     */
    public function inspectInstallConflict(string $name): array
    {
        return $this->inspector()->inspectInstallConflict($name);
    }

    /**
     * @see PluginStateInspector::repairInstallState()
     */
    public function repairInstallState(string $name): array
    {
        return $this->inspector()->repairInstallState($name);
    }

    /**
     * @see PluginStateInspector::purgeResidual()
     */
    public function purgeResidual(string $name): array
    {
        return $this->inspector()->purgeResidual($name);
    }


    /**
     * @see PluginListPresenter::getLocalPluginList()
     */
    public function getLocalPluginList(array $query = []): array
    {
        return $this->presenter()->getLocalPluginList($query);
    }

    /**
     * @see PluginListPresenter::buildLocalRow()
     */
    public function buildLocalRow(PluginMetadata $metadata, string $category = ''): array
    {
        return $this->presenter()->buildLocalRow($metadata, $category);
    }

    /**
     * @see PluginListPresenter::getPluginRow()
     */
    public function getPluginRow(string $nameOrIdent): ?array
    {
        return $this->presenter()->getPluginRow($nameOrIdent);
    }

    /**
     * @see PluginListPresenter::buildActions()
     */
    public function buildActions(PluginMetadata $metadata, bool $installed, string $newVersion = ''): array
    {
        return $this->presenter()->buildActions($metadata, $installed, $newVersion);
    }

    /**
     * @see PluginListPresenter::getOnlinePluginList()
     */
    public function getOnlinePluginList(array $query = []): array
    {
        return $this->presenter()->getOnlinePluginList($query);
    }


    /**
     * @see PluginSettings::getPluginConfigSchema()
     */
    public function getPluginConfigSchema(string $name): array
    {
        return $this->settings()->getPluginConfigSchema($name);
    }

    /**
     * @see PluginSettings::getPluginConfigMeta()
     */
    public function getPluginConfigMeta(string $name): array
    {
        return $this->settings()->getPluginConfigMeta($name);
    }

    /**
     * @see PluginSettings::getPluginConfig()
     */
    public function getPluginConfig(string $name): array
    {
        return $this->settings()->getPluginConfig($name);
    }

    /**
     * @see PluginSettings::setPluginConfig()
     */
    public function setPluginConfig(string $name, array $config): array
    {
        return $this->settings()->setPluginConfig($name, $config);
    }

    /**
     * @see PluginSettings::seedDefaultConfig()
     */
    public function seedDefaultConfig(string $name, array $schema = []): array
    {
        return $this->settings()->seedDefaultConfig($name, $schema);
    }


    protected function settings(): PluginSettings
    {
        return $this->settings ??= new PluginSettings($this->app, $this);
    }

    protected function inspector(): PluginStateInspector
    {
        return $this->inspector ??= new PluginStateInspector($this->app, $this, $this->settings());
    }

    protected function lifecycle(): PluginLifecycle
    {
        return $this->lifecycle ??= new PluginLifecycle($this->app, $this, $this->inspector(), $this->settings());
    }

    protected function presenter(): PluginListPresenter
    {
        return $this->presenter ??= new PluginListPresenter($this->app, $this, $this->inspector());
    }
}
