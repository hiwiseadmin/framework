<?php

declare(strict_types=1);

namespace wise\plugin;

use think\App;
use wise\plugin\adapter\AssetManager;
use wise\plugin\capability\CapabilityResolver;
use wise\plugin\adapter\MenuRegistrar;
use wise\plugin\adapter\MigrationRunner;
use wise\plugin\adapter\NamespaceRegistrar;
use wise\plugin\adapter\PermissionRegistrar;
use wise\plugin\adapter\RouteLoader;
use wise\plugin\config\PluginConfig;
use wise\plugin\dependency\DependencyChecker;
use wise\plugin\dependency\PackageDependencyChecker;

/**
 * 插件子系统的共享底座。
 *
 * 只承担一件事：把 App / PluginManager / Adapter 懒加载 / 配置与状态这些
 * **基础设施**集中到一处，供 PluginManager 与它的 4 个职责类复用，
 * 避免每个类各自 new 一遍 Adapter 或各自持有一份数据库状态。
 *
 * 注意：
 * - 本类**不持有**插件扫描缓存 —— 缓存唯一归属 PluginManager，子类通过
 *   `$this->manager->getAllPlugins()` 读取，保证"安装/卸载后清缓存"只有一个源头；
 * - 子类不得在自身保存与插件状态相关的缓存字段（否则会出现两份真相）。
 */
abstract class PluginSupport
{
    /** @var MenuRegistrar|null */
    protected ?MenuRegistrar $menuRegistrar = null;

    /** @var PermissionRegistrar|null */
    protected ?PermissionRegistrar $permissionRegistrar = null;

    /** @var AssetManager|null */
    protected ?AssetManager $assetManager = null;

    /** @var MigrationRunner|null */
    protected ?MigrationRunner $migrationRunner = null;

    /** @var RouteLoader|null */
    protected ?RouteLoader $routeLoader = null;

    /** @var NamespaceRegistrar|null */
    protected ?NamespaceRegistrar $namespaceRegistrar = null;

    /** @var PluginStateManager|null */
    protected ?PluginStateManager $stateManager = null;

    /** @var PackageDependencyChecker|null */
    protected ?PackageDependencyChecker $packageDependencies = null;

    /** @var CapabilityResolver|null */
    protected ?CapabilityResolver $capabilityResolver = null;

    /**
     * @param App           $app     应用实例
     * @param PluginManager $manager 插件管理器（同时是插件注册表/缓存的唯一持有者）
     */
    public function __construct(protected App $app, protected PluginManager $manager)
    {
    }


    protected function getConfig(): PluginConfig
    {
        return $this->app->get(PluginContainerKeys::CONFIG);
    }

    protected function getDependencyChecker(): DependencyChecker
    {
        return $this->app->get(PluginContainerKeys::DEPENDENCY_CHECKER);
    }

    protected function getStateManager(): PluginStateManager
    {
        return $this->stateManager ??= new PluginStateManager($this->getConfig());
    }

    /**
     * 库包依赖检查器（进程内复用，避免重复读取 installed.json）
     */
    protected function packageDependencyChecker(): PackageDependencyChecker
    {
        return $this->packageDependencies ??= new PackageDependencyChecker();
    }

    /**
     * 能力协商解析器（进程内复用，自带解析结果缓存）。
     *
     * 依赖 PluginScanner 由 PluginServiceProvider 绑定的容器单例
     * （PluginContainerKeys::SCANNER）提供。
     */
    protected function getCapabilityResolver(): CapabilityResolver
    {
        return $this->capabilityResolver ??= new CapabilityResolver(
            $this->app->get(PluginContainerKeys::SCANNER)
        );
    }


    public function getMenuRegistrar(): MenuRegistrar
    {
        return $this->menuRegistrar ??= new MenuRegistrar();
    }

    public function getPermissionRegistrar(): PermissionRegistrar
    {
        return $this->permissionRegistrar ??= new PermissionRegistrar();
    }

    public function getAssetManager(): AssetManager
    {
        return $this->assetManager ??= new AssetManager();
    }

    public function getMigrationRunner(): MigrationRunner
    {
        return $this->migrationRunner ??= new MigrationRunner($this->app);
    }

    public function getRouteLoader(): RouteLoader
    {
        return $this->routeLoader ??= new RouteLoader();
    }

    public function getNamespaceRegistrar(): NamespaceRegistrar
    {
        return $this->namespaceRegistrar ??= new NamespaceRegistrar();
    }


    /**
     * 插件目录根路径（配置 plugin.plugin_dir 优先）。
     */
    protected function getPluginDirectory(): string
    {
        $config = $this->app->config->get('plugin', []);

        return $config['plugin_dir'] ?? $this->app->getRootPath() . 'plugins';
    }
}
