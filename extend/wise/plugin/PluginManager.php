<?php

declare(strict_types=1);

namespace wise\plugin;


use wise\support\context\ContextualLogger;
use think\App;
use think\facade\Cache;
use think\facade\Db;
use app\common\slot\SlotManager;
use wise\plugin\scanner\PluginScanner;
use wise\plugin\scanner\PluginMetadata;
use wise\plugin\hook_dispatcher\HookDispatcher;
use wise\plugin\config\PluginConfig;
use wise\plugin\config\PluginConfigSchema;
use wise\plugin\dependency\DependencyChecker;
use wise\plugin\dependency\PackageDependencyChecker;
use wise\plugin\exception\PluginConfigException;
use wise\plugin\exception\PluginNotFoundException;
use wise\plugin\exception\PluginDependencyException;
use wise\plugin\exception\PluginException;
use wise\plugin\exception\PluginInstallException;
use wise\plugin\adapter\MenuRegistrar;
use wise\plugin\adapter\PermissionRegistrar;
use wise\plugin\adapter\AssetManager;
use wise\plugin\adapter\MigrationRunner;
use wise\plugin\adapter\RouteLoader;
use wise\plugin\adapter\NamespaceRegistrar;

/**
 * PluginManager 是管理插件生命周期的核心服务。
 *
 * 职责：
 * - 插件完整生命周期：install / uninstall / enable / disable
 * - 框架集成：菜单注册、权限注册、静态资源、数据库迁移、回调事件
 * - 插件查询：getPlugin / getAllPlugins / isInstalled / isEnabled
 * - 服务提供者初始化：initServiceProvider
 *
 * 委托：
 * - 状态管理委托给 PluginStateManager
 * - 扫描委托给 PluginScanner
 * - 依赖检查委托给 DependencyChecker
 * - 钩子分发委托给 HookDispatcher
 * - 配置持久化委托给 PluginConfig
 * - 框架集成委托给 Adapter 层（MenuRegistrar/PermissionRegistrar/AssetManager/...）
 *
 * 性能优化：
 * - 请求级内存缓存
 * - Adapter 懒加载
 */
class PluginManager
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
     * 两种场景（详见 inspectInstallConflict()）：
     *   - residual：建表迁移声明过表、库里仍有残留，但状态说"未安装"
     *   - dropped ：状态说"已安装"，但声明的表一张都不在
     * 这类状态必须显式暴露，不能混进"已安装/未安装"里 —— 否则用户点安装会撞
     * 1050「表已存在」，点启用又会遇到表不存在的运行期错误。
     */
    public const STATE_INCONSISTENT = 'inconsistent';

    /**
     * 本地插件的默认分类。
     *
     * 分类体系由服务器端远程市场定义，本地插件不携带分类；
     * 本地插件列表在同步远程数据后仍无法匹配到远程记录时，统一归入此分类。
     */
    public const LOCAL_CATEGORY = '本地';

    /**
     * ThinkPHP 应用实例。
     *
     * @var App
     */
    protected App $app;

    /**
     * 插件状态管理器。
     *
     * @var PluginStateManager|null
     */
    protected ?PluginStateManager $stateManager = null;

    /**
     * 库包依赖检查器（插件 ↔ composer 库包，见 manifest.packages）
     *
     * @var PackageDependencyChecker|null
     */
    protected ?PackageDependencyChecker $packageDependencies = null;

    /**
     * 请求级扫描结果缓存。
     *
     * @var array<string, PluginMetadata>|null
     */
    protected ?array $cachedPlugins = null;

    /**
     * 请求级缓存：已执行过数据库迁移的插件标识列表。
     *
     * @var string[]|null
     */
    protected ?array $migratedPlugins = null;

    /**
     * 请求级去重：本次请求内已完成"安装状态自愈"回写的插件标识。
     *
     * isInstalled() 走迁移记录兜底时会把 __state.installed 补写回去（S-1）。
     * 该兜底在列表渲染等热路径上会被反复调用，故用此集合保证每个插件只写一次。
     *
     * @var array<string, bool>
     */
    protected array $healedInstalledState = [];

    /**
     * 请求级缓存：插件启用状态（插件名 => 是否启用）。
     *
     * 与 cachedPlugins（扫描文件结果）分离存储，避免把动态运行时状态
     * 固化到扫描缓存中。每次 getCachedPlugins() 都会合并最新启用状态，
     * 保证同一请求内状态一致且最新。
     *
     * @var array<string, bool>|null
     */
    protected ?array $enabledState = null;

    // ==================== Adapter 懒加载属性 ====================

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

    /** @var array<string, Plugin> 插件主类实例缓存 */
    protected array $pluginInstances = [];

    /** @var string 插件目录根路径 */
    protected string $pluginPath;

    /**
     * 创建一个新的 PluginManager 实例。
     *
     * @param App $app ThinkPHP 应用实例
     */
    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 验证插件名称以防止路径遍历并确保使用安全字符。
     *
     * 插件名称只能由字母数字字符、下划线和连字符组成。
     * 这可以防止通过类似 "../../etc/passwd" 的名称进行目录遍历攻击。
     *
     * @param string $name 要验证的插件名称
     * @return void
     * @throws PluginException 如果名称包含无效字符
     */
    public static function validatePluginName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $name)) {
            throw new PluginException(sprintf(
                '无效的插件名称 "%s"。插件名称必须以字母或数字开头，且只能包含字母、数字、下划线和连字符。',
                $name
            ));
        }
    }

    // ==================== 缓存管理 ====================

    /**
     * 获取带缓存的所有插件列表。
     *
     * 首次调用时扫描并缓存插件元数据（文件级结果），后续调用直接返回缓存。
     * 写入操作（install/uninstall/enable/disable）后自动清除缓存。
     *
     * 注意：插件的启用（enabled）状态是动态运行时数据，每次调用都会从
     * 数据库合并最新值（带请求级缓存），而不是只在校验构建缓存时合并一次。
     * 否则若插件目录/状态在请求早期或服务启动阶段（此时数据库可能尚未
     * 就绪，PluginStateManager::isEnabled 会捕获异常并返回 false）被缓存，
     * 整个请求都会读到过期的 enabled=false，导致「插件未启用」误判。
     *
     * @return PluginMetadata[] 按插件名称索引
     */
    protected function getCachedPlugins(): array
    {
        if ($this->cachedPlugins === null) {
            $this->cachedPlugins = $this->getScanner()->scan();
        }

        // 启用状态为动态运行时数据，每次调用都从数据库刷新（请求级缓存去重）。
        $this->refreshEnabledState();

        return $this->cachedPlugins;
    }

    /**
     * 合并插件的启用状态（请求级缓存）。
     *
     * 从数据库读取各插件的 enabled 状态并写回 PluginMetadata。
     * 使用 $this->enabledState 在同一请求内去重，避免重复查库。
     *
     * 关键点：服务启动阶段（register/boot）数据库尚未就绪，
     * PluginStateManager::isEnabled() 会捕获异常并返回 false。
     * 此时不能把「false」固化进请求级缓存，否则整个请求都会读到
     * 过期的 enabled=false，导致已启用插件被误判为「未启用」。
     * 因此读取失败时保持 enabledState 为 null，下次调用重新尝试。
     *
     * @return void
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
            // 数据库未就绪（服务 register/boot 阶段）：不缓存状态，
            // 下次调用重新读取。同时保留扫描到的默认 enabled（false），
            // 不做任何固化，避免把「数据库不可用→false」误记为真实状态。
            $this->enabledState = null;
            return;
        }

        $this->applyEnabledState($states);
    }

    /**
     * 将启用状态写回 PluginMetadata 缓存。
     *
     * @param array<string, bool> $states 插件名 => 是否启用
     * @return void
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
     * 清除请求级缓存。
     */
    protected function clearCache(): void
    {
        $this->cachedPlugins = null;
        $this->enabledState = null;
    }

    // ==================== 生命周期管理 ====================

    /**
     * 安装插件（完整框架集成版）。
     *
     * 流程：
     *   1. 依赖检查
     *   2. 复制静态资源
     *   3. 执行数据库迁移
     *   4. 执行数据填充（database/seeds）
     *   5. 注册菜单 + 权限（事务）
     *   6. 注册前端插槽 + 播种配置默认值（幂等，失败不阻断）
     *   7. 初始化服务提供者
     *   8. 持久化安装/启用状态
     *   9. 分发 plugin.installed 钩子
     *  10. 调用插件生命周期回调 Plugin::install()（可选，见下）
     *
     * 失败回滚：
     *   - 迁移失败 → 清理静态资源
     *   - 填充失败 → 回滚迁移 + 清理静态资源
     *   - 菜单/权限注册失败 → 回滚迁移 + 清理静态资源
     *   - Plugin::install() 返回 false → **按承诺回滚整个安装流程**
     *     （迁移 / 菜单 / 权限 / 插槽 / 配置 / 状态 / 静态资源全部还原，
     *      见 rollbackInstall()；不留半安装状态）
     *
     * @param string $name 插件名称
     * @return PluginMetadata 安装后的插件元数据
     * @throws PluginNotFoundException|PluginDependencyException|PluginInstallException
     */
    public function install(string $name): PluginMetadata
    {
        static::validatePluginName($name);
        $metadata = $this->getPlugin($name);

        // 1. 依赖检查
        $this->getDependencyChecker()->checkDependencies($name);

        // 1b. 会员必需功能前置校验（manifest.json 的 member.features_require，P2-6）
        //     刻意放在**任何副作用之前**：此时未建表、未注册菜单、未复制资源，
        //     校验不通过直接抛出即可，不需要回滚，也不会留下"半安装"状态。
        $this->assertMemberFeatureRequirements($name, $metadata);

        // 2. 复制静态资源
        $this->getAssetManager()->copyAssets($name);

        // 3. 执行数据库迁移
        $migrateResult = $this->getMigrationRunner()->migrate($name);
        if (!$migrateResult['success']) {
            $this->getAssetManager()->removeAssets($name);
            throw new PluginInstallException($this->describeMigrationFailure($name, $migrateResult['message']));
        }

        // 4. 执行数据填充
        $seedResult = $this->getMigrationRunner()->seed($name);
        if (!$seedResult['success']) {
            $this->getMigrationRunner()->rollback($name, 0);
            $this->getAssetManager()->removeAssets($name);
            throw new PluginInstallException("插件数据填充失败：{$seedResult['message']}");
        }

        // 5. 注册菜单和权限（事务保护）
        //    register() 默认**保留**已存在菜单的字段（尊重运营改动），只补建/恢复；
        //    需要以 manifest 为准重置时用 `php think plugin:menu-migrate --force`（§8 G5）。
        try {
            Db::startTrans();
            $this->getMenuRegistrar()->register($metadata);
            $this->getPermissionRegistrar()->register($metadata);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            // 框架注册失败：回滚迁移 + 清理静态资源
            $this->getMigrationRunner()->rollback($name, 0);
            $this->getAssetManager()->removeAssets($name);

            throw new PluginInstallException("插件框架注册失败：" . $e->getMessage());
        }

        // 5b. 注册前端插槽内容
        $slots = $metadata->getSlots();
        if (!empty($slots)) {
            try {
                SlotManager::registerFromManifest($name, $slots);
            } catch (\Throwable $e) {
                // 插槽注册失败不阻断安装，仅记录日志
                ContextualLogger::warning("插件 [{$name}] 插槽注册失败：" . $e->getMessage());
            }
        }

        // 5c. 播种配置默认值（幂等：仅补齐缺失键）
        //     放在 initServiceProvider 之前，确保插件服务启动时已能读到完整配置。
        //     播种依赖 plugin_config 表，未执行 migrate:run 的站点会失败，
        //     但那只影响"配置可用性"而非"插件可用性"，故降级为警告不阻断安装。
        try {
            $this->seedDefaultConfig($name);
        } catch (\Throwable $e) {
            ContextualLogger::warning("插件 [{$name}] 配置默认值播种失败：" . $e->getMessage());
        }

        // 5d. 落地会员场景预设（manifest.json 的 member 段，P2-4）
        //     与 5b 插槽注册同为"可选集成"：失败只告警，绝不阻断安装。
        $this->applyMemberScenePreset($name, $metadata);

        // 6. 初始化服务提供者（注册路由/服务）；必须在持久化状态之前执行，
        //    若服务提供者类不存在或命名空间不合法会抛出异常，避免安装后状态为"已启用"却不可用
        $this->initServiceProvider($name);

        // 7. 持久化安装状态 + 启用状态（写入缓存/数据库）
        $this->getStateManager()->setInstalled($name, true);
        $metadata->setEnabled(true);
        $this->getStateManager()->setEnabled($name, true);

        // 8. 分发钩子
        $this->getHookDispatcher()->dispatch('plugin.installed', $metadata->toArray());

        // 9. 生命周期回调（调用 Plugin::install()，可选 —— 插件无 Plugin.php 时跳过）
        //    返回 false 表示插件自己判定"不应完成安装"，此时必须真正回滚：
        //    本步处在流程末尾，迁移/填充/菜单/权限/配置/状态/静态资源都已落下，
        //    只抛异常不清理会留下"表已建、菜单已注册、状态=已启用，但界面报安装失败"的半安装状态。
        $pluginInstance = $this->getPluginInstance($name);
        if ($pluginInstance !== null) {
            if (!$pluginInstance->install()) {
                $this->rollbackInstall($name, $metadata);
                throw new PluginInstallException(sprintf('插件 "%s" 安装回调返回失败，已回滚全部安装痕迹', $name));
            }
        }

        // 10. 清除缓存
        $this->clearCache();
        $this->migratedPlugins = null;

        return $metadata;
    }

    /**
     * 回滚一次失败的安装（仅由 Plugin::install() 返回 false 时调用）。
     *
     * 与 uninstall() 的区别（有意为之）：
     *   - 不触发卸载类钩子（plugin.uninstalling / plugin.uninstalled）—— 安装未成立过；
     *   - 不调用插件回调，不删除插件目录 —— 用户还要在界面上看到它、重试安装；
     *   - 任何一步失败都只记日志、继续往下清 —— 回滚必须尽量清干净，不能半途中断。
     *
     * 清理顺序与 install() 相反：迁移 → 菜单/权限 → 插槽 → 配置/状态 → 静态资源。
     *
     * @param string $name     插件名称
     * @param PluginMetadata $metadata 插件元数据（用于复位运行时启用标记）
     * @return void
     */
    protected function rollbackInstall(string $name, PluginMetadata $metadata): void
    {
        // 1. 回滚数据库迁移（连带清掉 seeds 灌入的初始数据）
        try {
            $result = $this->getMigrationRunner()->rollback($name, 0);
            if (!$result['success']) {
                ContextualLogger::warning(
                    "插件 [{$name}] 安装回滚：迁移回滚未彻底完成 —— " . $result['message']
                );
            }
        } catch (\Throwable $e) {
            ContextualLogger::warning("插件 [{$name}] 安装回滚：迁移回滚失败：" . $e->getMessage());
        }

        // 2. 注销菜单 + 权限（含角色绑定）
        try {
            $this->getMenuRegistrar()->unregister($name);
            $this->getPermissionRegistrar()->unregister($name);
        } catch (\Throwable $e) {
            ContextualLogger::warning("插件 [{$name}] 安装回滚：菜单/权限注销失败：" . $e->getMessage());
        }

        // 3. 注销前端插槽
        try {
            SlotManager::unregisterFromManifest($name);
        } catch (\Throwable $e) {
            ContextualLogger::warning("插件 [{$name}] 安装回滚：插槽注销失败：" . $e->getMessage());
        }

        // 3b. 回退会员场景预设（P2-4）
        //     安装未成立 → 用**显式清理**（$removeScene = true）。
        //     安全边界：只有"本次由本插件创建"的场景才会被删除（服务内以 __created 标记判定），
        //     运营自建、仅被本插件补过配置的场景一律保留。
        $this->revertMemberScenePreset($name, true);

        // 4. 清除安装/启用状态 + 安装时播种的配置默认值
        try {
            $metadata->setEnabled(false);
            $this->getStateManager()->clearState($name);
            $this->getConfig()->clear($name);
        } catch (\Throwable $e) {
            ContextualLogger::warning("插件 [{$name}] 安装回滚：状态/配置清理失败：" . $e->getMessage());
        }

        // 5. 删除已复制到 public/ 的静态资源
        try {
            $this->getAssetManager()->removeAssets($name);
        } catch (\Throwable $e) {
            ContextualLogger::warning("插件 [{$name}] 安装回滚：静态资源清理失败：" . $e->getMessage());
        }

        // 6. 清缓存 + 复位迁移标记（下次安装重新走一遍迁移）
        $this->clearCache();
        $this->migratedPlugins = null;
    }

    /**
     * 落地插件声明的会员场景预设（manifest.json 的 member 段，P2-4）
     *
     * 接线口径与 {@see SlotManager::registerFromManifest()} 完全一致：
     * **失败只告警，绝不阻断安装** —— 会员场景属"可选集成"，
     * 它的问题不应让插件装不上（问题经日志可见，P2-5 起还会在后台展示来源）。
     *
     * wise-member 未安装时静默跳过（框架不反向依赖该包）。
     *
     * @param PluginMetadata $metadata 插件元数据（`member` 段由此读取）
     */
    protected function applyMemberScenePreset(string $name, PluginMetadata $metadata): void
    {
        $service = $this->makeScenePresetService();

        if ($service === null) {
            return;
        }

        try {
            $profile = (array) $metadata->getMemberProfile();
        } catch (\Throwable $e) {
            return;
        }

        if ($profile === []) {
            return;
        }

        try {
            $result = (array) $service->applyFromPlugin($name, $profile);
        } catch (\Throwable $e) {
            // 越权写入等被服务拦截：留痕但不阻断安装
            ContextualLogger::error(sprintf('插件 [%s] 会员场景预设落地失败：%s', $name, $e->getMessage()));

            return;
        }

        foreach ((array) ($result['errors'] ?? []) as $problem) {
            ContextualLogger::warning(sprintf('插件 [%s] 会员场景预设未落地：%s', $name, $problem));
        }

        foreach ((array) ($result['warnings'] ?? []) as $warning) {
            ContextualLogger::warning(sprintf('插件 [%s] 会员场景预设提示：%s', $name, $warning));
        }

        if (!empty($result['written']) || !empty($result['created'])) {
            ContextualLogger::info(sprintf(
                '插件 [%s] 会员场景预设已落地：场景=%s，创建=%s，写入键=%d',
                $name,
                (string) ($result['scene'] ?? '-'),
                !empty($result['created']) ? '是' : '否',
                count((array) ($result['written'] ?? []))
            ));
        }
    }

    /**
     * 回退插件声明的会员场景预设（P2-4）
     *
     * @param bool $removeScene true = 显式清理（仅删除**本插件创建**的场景）；
     *                          false = 卸载默认口径：保留场景与配置，仅标记归属为"已卸载"
     */
    protected function revertMemberScenePreset(string $name, bool $removeScene = false): void
    {
        $service = $this->makeScenePresetService();

        if ($service === null) {
            return;
        }

        try {
            $result = (array) $service->revertFromPlugin($name, $removeScene);
        } catch (\Throwable $e) {
            ContextualLogger::warning(sprintf('插件 [%s] 会员场景预设回退异常：%s', $name, $e->getMessage()));

            return;
        }

        foreach ((array) ($result['warnings'] ?? []) as $warning) {
            ContextualLogger::warning(sprintf('插件 [%s] 会员场景预设回退提示：%s', $name, $warning));
        }

        foreach ((array) ($result['errors'] ?? []) as $problem) {
            ContextualLogger::warning(sprintf('插件 [%s] 会员场景预设回退失败：%s', $name, $problem));
        }

        if (!empty($result['kept']) || !empty($result['removed'])) {
            ContextualLogger::info(sprintf(
                '插件 [%s] 会员场景预设已回退：保留 %d 个场景，清理 %d 个',
                $name,
                count((array) ($result['kept'] ?? [])),
                count((array) ($result['removed'] ?? []))
            ));
        }
    }

    /**
     * 会员场景预设服务（wise-member 未安装时返回 null）
     */
    protected function makeScenePresetService(): ?object
    {
        $class = 'wise\\member\\service\\ScenePresetService';

        try {
            if (class_exists($class)) {
                return app($class);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * 校验插件声明的必需会员功能（manifest.json 的 `member.features_require`，P2-6）
     *
     * 分级处置（详见 {@see \wise\member\service\FeatureRequirementChecker}）：
     *  - **阻断**：必需功能对应的官方入口在能力市场被停用 —— 插件装上必然跑不通，
     *    且不是运营随手可改的开关 → 抛异常，中止安装/启用（调用点已确保无副作用）；
     *  - **警告**：必需功能在目标场景的开关为关闭 —— 运营一处配置即可解决 →
     *    不阻断，但以 **error 级**日志显著提示（项目日志配置保留 error/info，warning 会被丢弃）。
     *
     * wise-member 未安装、未声明 `features_require`、或校验器自身异常时一律**静默放行**
     * （可选集成的通用口径：校验能力的问题不应让插件装不上）。
     *
     * @throws PluginInstallException 存在阻断项时
     */
    protected function assertMemberFeatureRequirements(string $name, PluginMetadata $metadata): void
    {
        $checker = $this->makeFeatureRequirementChecker();

        if ($checker === null) {
            return;
        }

        $require = [];
        $scene   = 'default';

        try {
            $profile = (array) $metadata->getMemberProfile();
            $require = (array) ($profile['features_require'] ?? []);
            $scene   = (string) (($profile['scene_preset']['code'] ?? '') ?: 'default');

            if ($require === []) {
                return;
            }

            $result = (array) $checker->check($require, $scene);
        } catch (\Throwable $e) {
            ContextualLogger::warning(sprintf(
                '插件 [%s] 会员必需功能校验未能执行（已跳过，不阻断安装）：%s',
                $name,
                $e->getMessage()
            ));

            return;
        }

        foreach ((array) ($result['warnings'] ?? []) as $warning) {
            ContextualLogger::error(sprintf('插件 [%s] 会员必需功能提示：%s', $name, $warning));
        }

        $blocking = (array) ($result['blocking'] ?? []);

        if ($blocking !== []) {
            throw new PluginInstallException(sprintf(
                "插件「%s」缺少必需的会员功能，已中止本次操作：\n- %s\n请先到「能力市场」启用对应功能，再重试。",
                $name,
                implode("\n- ", $blocking)
            ));
        }
    }

    /**
     * 会员必需功能校验器（wise-member 未安装时返回 null）
     */
    protected function makeFeatureRequirementChecker(): ?object
    {
        $class = 'wise\\member\\service\\FeatureRequirementChecker';

        try {
            if (class_exists($class)) {
                return app($class);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * 为"迁移失败"补充可操作的排查指引。
     *
     * 典型场景：上一次卸载没清掉数据表（插件没有迁移记录 —— 表由全局
     * `php think migrate:run` 建立，或 ws_plugin_migrations 里没有本插件记录），
     * 再次安装时 `plugin:migrate:run` 认为"无记录 = 未执行"，重跑 up() 建表，
     * MySQL 抛 1050 "Table ... already exists"。
     *
     * 裸 SQL 报错看不出该做什么，这里补上确切的清理命令，避免用户只能手工删表。
     *
     * @param string $name    插件名称
     * @param string $message 迁移命令的原始错误信息
     * @return string 面向用户的错误信息
     */
    protected function describeMigrationFailure(string $name, string $message): string
    {
        $text = "插件迁移失败：{$message}";

        if (preg_match('/already exists|42S01|1050/i', $message) === 1) {
            $text .= sprintf(
                '。该插件的部分数据表已存在，但插件没有对应的迁移记录'
                . '（多为上次卸载未清理干净，或曾用全局 `php think migrate:run` 建表，记录落在全局 migrations 表）。'
                . '可先执行 `php think plugin:migrate:rollback -p %s --all` 清理残留表，再重新安装。',
                $name
            );
        }

        return $text;
    }

    /**
     * 卸载插件（完整框架集成版）。
     *
     * 流程：
     *   1. 验证 + 反向依赖检查
     *   2. 分发卸载前钩子
     *   3. 调用 onUninstall 回调
     *   4. 回滚数据库迁移（按迁移文件全量回滚，不依赖迁移记录；见 MigrationRunner::rollback）
     *   5. 删除菜单 + 权限（含角色绑定）
     *   6. 清除状态 + 配置
     *   7. 删除静态资源
     *   8. 删除插件目录
     *   9. 分发卸载后钩子
     *  10. 清除缓存
     *
     * @param string $name     插件名称
     * @param bool   $removeFiles 是否同时删除插件文件（默认 true）
     * @return array{residual_tables: string[], fully_cleaned: bool, message: string}
     *         状态与配置**总是**被清除（卸载语义）；failed 的只是数据表删除 ——
     *         fully_cleaned=false 表示表有残留，且残留表名已登记到
     *         __state.residual_tables，列表会显示「数据残留·需修复」并给出「重装」入口。
     * @throws PluginNotFoundException|PluginDependencyException
     */
    public function uninstall(string $name, bool $removeFiles = true): array
    {
        static::validatePluginName($name);
        $metadata = $this->getPlugin($name);

        // 1. 检查反向依赖
        $dependents = $this->getDependencyChecker()->resolveDependents($name);
        if (!empty($dependents)) {
            throw new PluginDependencyException(
                sprintf('无法卸载插件 "%s"：以下插件依赖它：%s', $name, implode(', ', $dependents)),
                $dependents
            );
        }

        // 2. 分发卸载前钩子（允许监听器做清理工作）
        $this->getHookDispatcher()->dispatch('plugin.uninstalling', $metadata->toArray());

        // 3. 生命周期回调（调用 Plugin::uninstall()，失败仅日志）
        $pluginInstance = $this->getPluginInstance($name);
        if ($pluginInstance !== null) {
            try {
                $pluginInstance->uninstall();
            } catch (\Throwable $e) {
                ContextualLogger::warning("插件 {$name} 卸载回调异常（已继续执行）：" . $e->getMessage());
            }
        }

        // 4. 回滚数据库迁移（target=0：按迁移文件全量回滚，不依赖迁移记录）
        //
        //    不能静默失败：表残留的后果是"再次安装"重跑建表迁移撞
        //    "Table ... already exists"，而卸载界面此时显示的是"卸载成功"，
        //    用户不会去查库，只会以为插件系统坏了。故失败/残留一律留痕。
        $rollbackResult = $this->getMigrationRunner()->rollback($name, 0);

        // 落地校验：Rollback 命令内部的早退（无记录 / 断点 / 目标版本不存在）不会抛异常，
        // 只有"本插件声明的建表迁移对应的表还在不在"才是可信结论。
        $residualTables = $this->getMigrationRunner()->remainingTables($name);
        $rollbackClean  = $rollbackResult['success'] && $residualTables === [];

        if (!$rollbackClean) {
            ContextualLogger::error(sprintf(
                '插件 [%s] 卸载：数据库迁移回滚未彻底完成，残留 %d 张数据表 —— %s',
                $name,
                count($residualTables),
                $rollbackResult['message']
            ));
        }

        // 5. 删除菜单 + 权限
        //
        //    ⚠️ 两者的删除语义是**软删**（写 delete_time），不是物理删除：
        //      - 菜单：`MenuItemSync::uninstall()`，重装时 `withTrashed()` 自动 restore；
        //      - 权限：`PermissionRegistrar::unregister()` → 逐条 `$permission->delete()`，
        //        `PermissionService::registerPermission()` 重装时会 `withTrashed()+restore()`。
        //    因此**卸载后直接 `select ... where plugin='xxx'` 仍能看到这些行**——那是设计
        //    如此（保留可恢复性），不是"没清"。要看有没有清，必须带 `delete_time` 判断。
        //
        //    ⚠️ 返回值必须收（2026-10-13 加固）：此前这里忽略返回值，于是"权限/菜单
        //    清理失败"会被界面上的「卸载成功」掩盖，运营只能翻库才发现残留。
        $menuCleaned = $this->getMenuRegistrar()->unregister($name);
        $permCleaned = $this->getPermissionRegistrar()->unregister($name);

        if (!$menuCleaned || !$permCleaned) {
            ContextualLogger::error(sprintf(
                '插件 [%s] 卸载：菜单/权限清理未全部成功（菜单=%s，权限=%s），'
                . '请复核 ws_menus / ws_auth_permissions 中 plugin=\'%s\' 的行',
                $name,
                $menuCleaned ? 'ok' : 'FAIL',
                $permCleaned ? 'ok' : 'FAIL',
                $name
            ));
        }

        // 5b. 删除插槽内容
        try {
            SlotManager::unregisterFromManifest($name);
        } catch (\Throwable $e) {
            ContextualLogger::warning("插件 [{$name}] 插槽注销失败：" . $e->getMessage());
        }

        // 5c. 回退会员场景预设（P2-4）：**默认保留**场景与配置，仅把归属标记为"已卸载"。
        //     理由：场景可能已被运营投入使用，静默删除是破坏性操作；需要清理时由运营显式触发。
        $this->revertMemberScenePreset($name);

        // 6+7. 清除状态与配置。
        //
        //   卸载的语义就是"插件已移除"：菜单/权限/静态资源都已清掉，状态回到"未安装"。
        //   表没清干净时**不再**把状态留着假装"已安装"（那会让冲突检测失灵、界面也没有修复入口），
        //   而是把**残留表名登记下来**（__state.residual_tables）：
        //   列表据此明确显示「数据残留·需修复」并给出「重装」按钮，语义清晰且可操作。
        //
        //   注意顺序：clear() 会删掉该插件的全部行（含状态键），必须先 clear 再写登记。
        $metadata->setEnabled(false);
        $this->getStateManager()->clearState($name);
        $this->getConfig()->clear($name);

        if (!$rollbackClean) {
            $this->getStateManager()->setResidualTables($name, $residualTables);

            ContextualLogger::warning(sprintf(
                '插件 [%s] 卸载：残留 %d 张数据表，已登记为「数据残留·需修复」，可在插件列表用「重装」清理',
                $name,
                count($residualTables)
            ));
        }

        // 8. 删除静态资源
        $this->getAssetManager()->removeAssets($name);

        // 9. 删除插件目录
        if ($removeFiles) {
            $pluginDir = $this->getPluginDirectory();
            $targetPath = $pluginDir . DIRECTORY_SEPARATOR . $name;

            if (is_dir($targetPath)) {
                $this->removeDirectory($targetPath);
            }
        }

        // 10. 分发卸载后钩子
        $this->getHookDispatcher()->dispatch('plugin.uninstalled', $metadata->toArray());

        // 11. 刷新扫描器缓存 + 清除本地缓存
        $this->getScanner()->refreshCache();
        $this->clearCache();
        $this->migratedPlugins      = null;
        $this->healedInstalledState = [];

        return [
            'residual_tables' => $residualTables,
            'fully_cleaned'   => $rollbackClean,
            // 清理结果（供 CLI / 后台把"到底清没清"讲清楚；菜单/权限为软删，可恢复）
            'cleanup'         => [
                'menus_ok'       => $menuCleaned,
                'permissions_ok' => $permCleaned,
            ],
            'message'         => $this->buildUninstallMessage($name, $rollbackClean, $residualTables, $menuCleaned, $permCleaned),
        ];
    }

    /**
     * 组装卸载结果文案（表残留 / 菜单权限清理 两类异常都要说清）
     *
     * @param array<int, string> $residualTables
     */
    private function buildUninstallMessage(
        string $name,
        bool $rollbackClean,
        array $residualTables,
        bool $menuCleaned,
        bool $permCleaned
    ): string {
        $message = $rollbackClean
            ? sprintf('插件 [%s] 已卸载，数据表与状态均已清理', $name)
            : sprintf(
                '插件 [%s] 已卸载，但有 %d 张数据表未清理干净：%s',
                $name,
                count($residualTables),
                implode('、', $residualTables)
            );

        if (!$menuCleaned || !$permCleaned) {
            $message .= sprintf(
                '；另有清理未完成项：%s%s',
                $menuCleaned ? '' : '菜单',
                $permCleaned ? '' : ($menuCleaned ? '权限' : '、权限')
            );
        }

        return $message . '（菜单与权限为软删，重装同名插件时会自动恢复）';
    }

    /**
     * 启用插件（纯状态位切换，不触碰数据库结构）。
     *
     * 职责边界（重要）：
     *   建表 / 灌初始数据 由 install()、upgrade() 负责；
     *   删表 由 uninstall() 负责；
     *   enable() / disable() 只翻转 enabled 状态位并做框架集成（菜单/权限/服务提供者）。
     *
     * 之所以不让 enable() 隐式安装：开关是"轻量、可反复拨动"的操作，
     * 若 OFF→ON 会建表灌数据、而 ON→OFF 又不删表，语义严重不对称，
     * 且用户只是拨一下开关就产生了不可逆的建表行为。
     *
     * 流程：
     *   0. 未安装 → 直接拒绝，提示先执行"安装"
     *   1. 依赖检查
     *   2. 复制静态资源（确保完整）
     *   3. 调用 onEnable 回调（失败则阻止启用）
     *   4. 注册菜单和权限（幂等）
     *   5. 初始化服务提供者
     *   6. 持久化状态 + 分发钩子 + 清缓存
     *
     * @param string $name 插件名称
     * @return void
     * @throws PluginNotFoundException|PluginDependencyException|PluginInstallException
     */
    public function enable(string $name): void
    {
        static::validatePluginName($name);

        // 0. 未安装（数据库表还没建）不允许直接启用，必须由"安装"动作显式建表
        if (!$this->isInstalled($name)) {
            throw new PluginInstallException(
                "插件「{$name}」尚未安装，请先点击「安装」创建所需的数据表，再启用"
            );
        }

        $metadata = $this->getPlugin($name);

        // 1. 依赖检查
        $this->getDependencyChecker()->checkDependencies($name);

        // 1b. 会员必需功能前置校验（P2-6）——与 install() 同口径，同样放在副作用之前。
        //     启用是高频动作，故校验必须在状态位翻转前完成（否则会出现"已启用但跑不通"）。
        $this->assertMemberFeatureRequirements($name, $metadata);

        // 2. 确保静态资源完整（禁用期间可能被手动删除）
        $this->getAssetManager()->copyAssets($name);

        // 3. 生命周期回调（调用 Plugin::enable()，失败阻止启用）
        $pluginInstance = $this->getPluginInstance($name);
        if ($pluginInstance !== null) {
            if (!$pluginInstance->enable()) {
                throw new PluginException(sprintf('插件 "%s" 启用回调返回失败，插件未被启用', $name));
            }
        }

        // 4. 注册菜单和权限（幂等，可重复执行）。
        //    若插件在安装后被清理过菜单数据（或旧版本未注册），启用时补全，
        //    保证"启用状态"与"菜单/权限数据"一致。
        //    register() 默认**保留**已存在菜单的字段：启用是高频动作，绝不能重置运营的
        //    改名/排序/停用（§8 G5）；需要重置时用 `plugin:menu-migrate --force`。
        try {
            Db::startTrans();
            $this->getMenuRegistrar()->register($metadata);
            $this->getPermissionRegistrar()->register($metadata);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            ContextualLogger::error(sprintf(
                '插件 "%s" 启用时菜单/权限注册失败: %s',
                $name,
                $e->getMessage()
            ));
            throw $e;
        }

        // 5. 初始化服务提供者（注册路由/服务）；必须在持久化状态之前执行，
        //    若服务提供者类不存在或命名空间不合法会抛出异常，此时不应把状态写成"已启用"
        $this->initServiceProvider($name);

        // 6. 持久化启用状态（写入缓存/数据库）
        $metadata->setEnabled(true);
        $this->getStateManager()->setEnabled($name, true);

        // 7. 分发钩子
        $this->getHookDispatcher()->dispatch('plugin.enabled', $metadata->toArray());

        $this->clearCache();
    }

    /**
     * 禁用插件（完整框架集成版）。
     *
     * 设计原则：先落库再回调——确保即使 onDisable 出错，插件也能被禁用。
     *
     * 流程：
     *   1. 持久化禁用状态（先落库，确保操作必定成功）
     *   2. 调用 onDisable 回调（失败仅日志，不阻断）
     *   3. 分发钩子
     *   4. 清除缓存
     *
     * @param string $name 插件名称
     * @return void
     * @throws PluginNotFoundException
     */
    public function disable(string $name): void
    {
        static::validatePluginName($name);
        $metadata = $this->getPlugin($name);

        // 1. 先持久化禁用状态（确保禁用操作始终成功）
        $metadata->setEnabled(false);
        $this->getStateManager()->setEnabled($name, false);

        // 2. 生命周期回调（调用 Plugin::disable()，失败仅日志）
        $pluginInstance = $this->getPluginInstance($name);
        if ($pluginInstance !== null) {
            try {
                $pluginInstance->disable();
            } catch (\Throwable $e) {
                ContextualLogger::warning("插件 {$name} 禁用回调异常（已继续执行）：" . $e->getMessage());
            }
        }

        // 3. 分发钩子
        $this->getHookDispatcher()->dispatch('plugin.disabled', $metadata->toArray());

        $this->clearCache();
    }

    // ==================== 在线/本地列表 ====================

    /**
     * 获取在线插件列表（远程市场）。
     *
     * @param array $query 查询参数（page/limit/catid/keyword）
     * @return array
     */
    public function getOnlinePluginList(array $query = []): array
    {
        $config = $this->app->config->get('plugin', []);
        $remoteUrl = $config['list_url'] ?? '';

        if (empty($remoteUrl)) {
            return [];
        }

        $query = array_merge(['page' => 1, 'limit' => 50, 'catid' => 0, 'keyword' => ''], $query);
        $queryString = http_build_query($query);
        $url = $remoteUrl . (str_contains($remoteUrl, '?') ? '&' : '?') . $queryString;

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'header' => "Accept: application/json\r\n",
                ],
            ]);
            $json = file_get_contents($url, false, $context);

            if ($json === false) {
                return [];
            }

            $remotePlugins = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }

            // 市场接口统一采用项目单码约定：0 = 成功
            if (!isset($remotePlugins['code']) || (int) $remotePlugins['code'] !== 0 || !isset($remotePlugins['data']['list'])) {
                return [];
            }

            return [
                'category' => $remotePlugins['data']['category'] ?? [],
                'list'     => $remotePlugins['data']['list'],
                'total'    => $remotePlugins['data']['total'] ?? 0,
                'page'     => $remotePlugins['data']['page'] ?? 1,
                'limit'    => $remotePlugins['data']['limit'] ?? 50,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取本地插件列表。
     *
     * 分类由服务器端（远程插件市场）定义，本地插件本身不携带分类。
     * 本方法会同步远程数据信息：本地插件能在远程列表中找到（ident 相同）
     * 时采用远程分类；纯本地安装的插件（远程无记录）统一归入「本地」分类。
     *
     * 支持与远程列表一致的查询参数，供插件中心「本地插件」模式的分类查询：
     *   - catid：按分类过滤。远程分类用分类名、纯本地插件用「本地」；catid=0/空 表示全部。
     *   - keyword：按名称/描述/作者模糊匹配。
     *
     * 返回的 category 为分类聚合（{id, name}），与远程列表结构一致，
     * 前端可共用同一套 tab 生成逻辑。
     *
     * @param array $query 查询参数（page/limit 对本地列表无意义，忽略）
     * @return array{list: array, total: int, page: int, limit: int, category: array}
     */
    public function getLocalPluginList(array $query = []): array
    {
        $catid   = (string) ($query['catid'] ?? '');
        $keyword = trim((string) ($query['keyword'] ?? ''));

        // 同步远程分类：本地插件 → 远程分类名 的映射（远程无记录时为空映射）
        $remoteCats = $this->getRemoteCategoryIndex();

        $plugins = $this->getAllPlugins();
        $list    = [];
        $cats    = [];

        /** @var string $name **/
        foreach ($plugins as $name => $metadata) {
            // 关键字过滤：名称 / 描述 / 作者
            if ($keyword !== '') {
                $haystack = implode(' ', [
                    $metadata->getDisplayName(),
                    $metadata->getDescription(),
                    $metadata->getAuthor(),
                ]);
                if (stripos($haystack, $keyword) === false) {
                    continue;
                }
            }

            // 分类判定：远程匹配用远程分类名，否则归入「本地」
            $category = $remoteCats[$name] ?? self::LOCAL_CATEGORY;

            // 分类过滤：catid>0 时仅保留分类匹配的插件
            if ($catid !== '' && $catid !== '0') {
                if ($category !== $catid) {
                    continue;
                }
            }

            // 行构建统一走 buildLocalRow()：与 Plugin::getList 的单行模式（row_ident）
            // 共用同一份结构，避免"列表行"与"安装后局部刷新的单行"两处各自维护而漂移。
            $row = $this->buildLocalRow($metadata, $category);
            $list[] = $row;

            // 聚合分类（去重，保持出现顺序）
            $rowCategory = $row['category'];
            if (!isset($cats[$rowCategory])) {
                $cats[$rowCategory] = ['id' => $rowCategory, 'name' => $rowCategory];
            }
        }

        return [
            'total'    => count($list),
            'list'     => $list,
            'page'     => 1,
            'limit'    => max(count($list), 1),
            'category' => array_values($cats),
        ];
    }

    /**
     * 构建单个本地插件的列表行数据。
     *
     * 结构必须与 getLocalPluginList() 的列表项完全一致 —— 前端「安装/卸载后
     * 只刷新该行」依赖本方法返回的行数据原地 updateRow，因此两处共用同一实现
     * （单行出口见 Plugin::getList 的 row_ident 模式）。
     *
     * @param PluginMetadata $metadata 插件元数据
     * @param string         $category 分类名；空则按「远程分类索引」推导（纯本地插件回落「本地」）
     * @return array
     */
    public function buildLocalRow(PluginMetadata $metadata, string $category = ''): array
    {
        $ident = $metadata->getIdent();

        if ($category === '') {
            $category = $this->getRemoteCategoryIndex()[$ident] ?? self::LOCAL_CATEGORY;
        }

        // 安装一致性自检：把"状态键 / 迁移记录 / 实际数据表"三处事实一次算清。
        // 既用于推导 state，也随行下发给前端 —— 冲突时弹"重装"引导弹窗（P1）。
        $inspect = $this->inspectInstallConflict($ident);
        $state   = $this->resolveState($ident, $inspect);

        $actions = $this->buildActions($metadata, $inspect['installed']);
        if ($inspect['severity'] !== 'ok' && !in_array('reinstall', $actions, true)) {
            $actions[] = 'reinstall';
        }

        // 库包依赖诊断（一次算清，避免重复遍历）：
        // 硬依赖缺失 → 该插件不会被加载（见 PluginLoader / initServiceProvider）
        $missingPackages = $this->missingPackages($metadata);
        $packageReport   = $this->packageDependencyReport($metadata);

        return [
            'name'        => $metadata->getDisplayName(),
            'ident'       => $ident,
            'version'     => $metadata->getVersion(),
            'author'      => $metadata->getAuthor(),
            'description' => $metadata->getDescription(),
            'category'    => $category,
            // status 仅表达 enabled 开关；未安装时强制为 0（开关处于关闭态且被前端禁用）
            'status'           => $state === self::STATE_ENABLED ? 1 : 0,
            // 不一致态同样视为"不可启用"：残留表/表丢失时启用只会带来运行期错误，
            // 应先走「重装」把数据与状态对齐
            'installed'        => $state === self::STATE_INCONSISTENT
                ? 0
                : ($inspect['installed'] ? 1 : 0),
            'state'            => $state,
            // 一致性自检结果：供前端渲染「数据残留·需修复」徽标与重装弹窗
            'severity'         => $inspect['severity'],
            'conflict'         => $inspect['conflict'] ? 1 : 0,
            'state_key'        => $inspect['state_key'] ? 1 : 0,
            'migration_rows'   => $inspect['migration_rows'],
            'declared_tables'  => $inspect['declared_tables'],
            'residual_tables'  => $inspect['residual_tables'],
            // 库包依赖诊断：把缺失项与"被阻断"标记一并暴露，让后台能显示
            // 「依赖缺失·未加载」，而不是让用户困惑于"已启用却没效果"。
            'missing_packages' => array_values(array_map(
                static fn (array $item): string => $item['package'],
                $missingPackages
            )),
            'blocked'      => $missingPackages !== [],
            'block_reason' => implode('；', array_map(
                static fn (array $item): string => $item['message'],
                $packageReport
            )),
            'develop'     => $metadata->isDevelop(),
            'index_url'   => '/plugin/' . $ident,
            'download'    => 0,
            'actions'     => $actions,
        ];
    }

    /**
     * 获取单个插件的列表行数据（供安装/卸载后按行局部刷新）。
     *
     * 找不到插件（标识非法或目录已不存在）时返回 null，由调用方转成业务错误。
     *
     * @param string $nameOrIdent 插件标识 / 目录名 / 标题
     * @return array|null
     */
    public function getPluginRow(string $nameOrIdent): ?array
    {
        if ($nameOrIdent === '') {
            return null;
        }

        $plugins  = $this->getCachedPlugins();
        $metadata = $plugins[$nameOrIdent] ?? $plugins[$this->resolveIdent($nameOrIdent)] ?? null;

        if (!$metadata instanceof PluginMetadata) {
            return null;
        }

        return $this->buildLocalRow($metadata);
    }

    /**
     * 获取「本地插件 ident → 远程分类名」的映射。
     *
     * 分类体系归属服务器端远程市场，本地插件不携带分类。同步策略：
     *   - 拉取远程插件列表（含分类），建立 ident → 分类名 的映射
     *   - 纯本地插件（远程无记录）不进入映射，调用方回落到「本地」分类
     *
     * 结果带 60 秒缓存，避免每次刷新本地列表都触发远程请求。
     * 远程未配置 / 不可达 / 返回异常时静默返回空映射（全部插件归「本地」）。
     *
     * @return array<string, string> [插件ident => 分类名]
     */
    protected function getRemoteCategoryIndex(): array
    {
        $cacheKey = 'plugin_remote_category_index';

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            // 缓存层异常不阻断，继续实时拉取
        }

        $map = [];

        try {
            // 拉取远程列表（catid=0 取全部分类），只消费分类映射，不落列表
            $remote = $this->getOnlinePluginList([
                'page'    => 1,
                'limit'   => 200,
                'catid'   => 0,
                'keyword' => '',
            ]);

            // 远程分类 id → 分类名
            $catNames = [];
            foreach (($remote['category'] ?? []) as $cat) {
                if (!isset($cat['id'])) {
                    continue;
                }
                $catNames[(string) $cat['id']] = (string) ($cat['name'] ?? $cat['id']);
            }

            // 远程列表项 ident → 分类名（兼容 catid / category_id 两种字段名）
            foreach (($remote['list'] ?? []) as $item) {
                $ident = (string) ($item['ident'] ?? ($item['name'] ?? ''));
                if ($ident === '') {
                    continue;
                }

                $catid = (string) ($item['catid'] ?? ($item['category_id'] ?? ''));
                if ($catid === '') {
                    continue;
                }

                $map[$ident] = $catNames[$catid] ?? $catid;
            }
        } catch (\Throwable $e) {
            // 远程不可达：返回空映射，全部本地插件归「本地」分类
        }

        if ($map !== []) {
            try {
                Cache::set($cacheKey, $map, 60);
            } catch (\Throwable $e) {
                // 缓存写失败可忽略
            }
        }

        return $map;
    }

    /**
     * 构建插件当前可用操作列表。
     *
     * 规则（由 installed + enabled 两个独立维度决定）：
     * - 未安装（not_installed）      → ['install']
     *                                  开关禁用，因为建表必须由"安装"显式触发
     * - 已安装（installed / enabled）→ ['uninstall']
     *                                  有 config schema 时加 'config'
     *                                  远程版本更高时加 'upgrade'
     *
     * 注意：'uninstall' 与启用状态无关，已安装即可卸载（卸载会回滚迁移删表）；
     *      启用/禁用不在此体现，由 status 开关承担。
     *
     * @param PluginMetadata $metadata   插件元数据
     * @param bool           $installed  是否已安装（已跑迁移）
     * @param string         $newVersion 远程最新版本（空表示未知，不显示升级）
     * @return string[]
     */
    public function buildActions(PluginMetadata $metadata, bool $installed, string $newVersion = ''): array
    {
        // 未安装：唯一出路是"安装"，此时不允许卸载/配置/升级
        if (!$installed) {
            return ['install'];
        }

        $actions = ['uninstall'];

        // 有配置 schema 声明 → 支持"配置"（manifest 的 config 字段）
        if (!empty($metadata->getConfigSchema())) {
            $actions[] = 'config';
        }

        // 远程已知最新版本且高于当前版本 → 支持"升级"
        if ($newVersion !== '' && version_compare($newVersion, $metadata->getVersion(), '>')) {
            $actions[] = 'upgrade';
        }

        return $actions;
    }

    // ==================== 升级 ====================

    /**
     * 升级插件。
     *
     * 流程：
     *   1. 备份旧版本目录
     *   2. 下载/解压新版本
     *   3. 复制静态资源
     *   4. 执行数据库迁移
     *   5. 执行数据填充
     *   6. 清理备份
     *   7. 用新版 manifest 重新注册菜单与权限（幂等；弥补「升级后新菜单不出现」，§8 G1）
     *
     * @param string $name        插件名称
     * @param string $newVersion  新版本号
     * @param string $packageUrl  安装包下载地址（空则假设文件已就绪）
     * @return PluginMetadata 升级后的插件元数据
     * @throws PluginNotFoundException|PluginInstallException
     */
    public function upgrade(string $name, string $newVersion, string $packageUrl = ''): PluginMetadata
    {
        static::validatePluginName($name);
        $metadata = $this->getPlugin($name);
        $currentVersion = $metadata->getVersion();

        // 版本号检查
        if (version_compare($newVersion, $currentVersion, '<=')) {
            throw new PluginInstallException(
                "新版本（{$newVersion}）不高于当前版本（{$currentVersion}），无需升级"
            );
        }

        $pluginDir = $this->getPluginDirectory();
        $targetDir = $pluginDir . DIRECTORY_SEPARATOR . $name;
        $backupDir = $pluginDir . DIRECTORY_SEPARATOR . $name . '_backup_' . date('YmdHis');

        // 1. 备份
        if (is_dir($targetDir)) {
            $this->copyDirectory($targetDir, $backupDir);
        }

        try {
            // 2. 下载并解压
            if (!empty($packageUrl)) {
                $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . '_' . $newVersion . '.zip';
                $this->downloadFile($packageUrl, $tmpZip);

                $zip = new \ZipArchive();
                if ($zip->open($tmpZip) !== true) {
                    throw new PluginInstallException("安装包解压失败");
                }

                // 先清空目标目录（保留备份）
                if (is_dir($targetDir)) {
                    $this->removeDirectory($targetDir);
                }
                $zip->extractTo($pluginDir);
                $zip->close();
                @unlink($tmpZip);

                // 兼容：ZIP 内有一层子目录
                $subDirs = glob($pluginDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
                if (!empty($subDirs)) {
                    $firstSub = basename($subDirs[0]);
                    if ($firstSub !== $name) {
                        // 检查是否为插件子目录
                        $subManifest = $subDirs[0] . DIRECTORY_SEPARATOR . 'manifest.json';
                        if (is_file($subManifest)) {
                            if (is_dir($targetDir)) {
                                $this->removeDirectory($targetDir);
                            }
                            rename($subDirs[0], $targetDir);
                        }
                    }
                }
            }

            // 3. 复制静态资源
            $this->getAssetManager()->copyAssets($name);

            // 4. 执行数据库迁移
            $migrateResult = $this->getMigrationRunner()->migrate($name);
            if (!$migrateResult['success']) {
                throw new PluginInstallException("升级迁移失败：" . $migrateResult['message']);
            }

            // 5. 执行数据填充
            $this->getMigrationRunner()->seed($name);

            // 5b. 补齐新版本新增的配置项默认值（幂等，不覆盖已有值）
            //     必须早于下方 refreshCache()，否则读到的仍是旧 manifest 的 schema
            try {
                $this->seedDefaultConfig($name);
            } catch (\Throwable $e) {
                ContextualLogger::warning("插件 [{$name}] 配置默认值补齐失败：" . $e->getMessage());
            }

            // 6. 清理备份
            if (is_dir($backupDir)) {
                $this->removeDirectory($backupDir);
            }

            // 刷新缓存
            $this->getScanner()->refreshCache();
            $this->clearCache();

            // 7. 用**新** manifest 重新注册菜单与权限（幂等），并清理已删除的旧菜单
            //
            // 在线升级后新版本新增的菜单，如果不同步到数据库就不会出现，而管理员不会
            // 想到去「禁用→启用」（§8 G1）。要点：
            //   - 必须放在上面刷新缓存**之后**：否则 getPlugin() 读到的是旧 manifest 的 menus；
            //   - register() 默认**保留**已存在菜单的字段（§8 G5），因此不会冲掉运营在后台
            //     改过的 name/sort/status；
            //   - 之后再 prune()：新 manifest 已不再声明的旧菜单（作者删掉的页面）**软删**清理，
            //     否则会永久残留、越升级越多（§8.10）。软删可恢复 —— 重新声明即恢复同一行，
            //     连同 permission_id 与角色授权；根容器与仍被声明引用的父目录不受影响。
            //   - 失败只告警不抛出：菜单没同步不该让整个升级失败，可用
            //     `php think plugin:menu-migrate` 补同步（`--prune` 补清理）。
            try {
                $metadata = $this->getPlugin($name);

                Db::startTrans();
                $this->getMenuRegistrar()->register($metadata);
                $this->getPermissionRegistrar()->register($metadata);
                $pruned = $this->getMenuRegistrar()->prune($metadata);
                Db::commit();

                if (!empty($pruned)) {
                    ContextualLogger::info(sprintf(
                        '插件 "%s" 升级后清理了 %d 项 manifest 已不再声明的菜单（软删，可恢复）',
                        $name,
                        count($pruned)
                    ));
                }
            } catch (\Throwable $e) {
                Db::rollback();
                ContextualLogger::warning(sprintf(
                    '插件 "%s" 升级后菜单/权限重注册失败（可用 `php think plugin:menu-migrate` 补同步）：%s',
                    $name,
                    $e->getMessage()
                ));
            }

            return $this->getPlugin($name);

        } catch (\Throwable $e) {
            // 回滚：恢复备份
            if (is_dir($backupDir)) {
                if (is_dir($targetDir)) {
                    $this->removeDirectory($targetDir);
                }
                rename($backupDir, $targetDir);
                $this->getAssetManager()->copyAssets($name);
            }
            throw new PluginInstallException("插件升级失败：" . $e->getMessage());
        }
    }

    // ==================== 标识验证 ====================

    /**
     * 检查插件标识是否符合规范（兼容旧系统）。
     *
     * 规则：
     *   - 仅含字母、数字、下划线
     *   - 长度 3~32
     *   - 不与系统保留词冲突
     *
     * @param string $pluginIdent 插件标识
     * @return bool
     */
    public static function checkPluginIdent(string $pluginIdent): bool
    {
        return preg_match('/^[a-zA-Z0-9_]+$/', $pluginIdent)
            && strlen($pluginIdent) >= 3
            && strlen($pluginIdent) <= 32
            && !in_array($pluginIdent, ['admin', 'common', 'system', 'index', 'plugin', 'app', 'api', 'member']);
    }

    /**
     * 向后兼容别名。
     * @deprecated 请使用 {@see checkPluginIdent()}
     */
    public static function validatePluginIdent(string $pluginIdent): bool
    {
        return static::checkPluginIdent($pluginIdent);
    }

    // ==================== 配置管理 ====================

    /**
     * 获取插件的配置项定义（已归一化）。
     *
     * 来源优先级：
     *   1. 插件 Plugin 类覆写的 getConfig()（允许运行时动态提供 schema）
     *   2. manifest.json 的 config 字段
     *
     * @param string $name 插件名称（目录名）
     * @return array<string, array> 归一化 schema，无配置声明时返回空数组
     * @throws PluginNotFoundException 插件不存在时
     */
    public function getPluginConfigSchema(string $name): array
    {
        $metadata = $this->getPlugin($name);
        $raw      = $metadata->getConfigSchema();

        // 插件类可覆写 getConfig() 动态提供 schema（Plugin::getConfig() 默认即返回
        // manifest 的 config）。仅当覆写结果非空时才覆盖 manifest 声明 ——
        // 否则 metadata 注入失败、或子类误返回空数组时，manifest 声明会被静默清空。
        $instance = $this->getPluginInstance($name);
        if ($instance !== null && method_exists($instance, 'getConfig')) {
            $override = (array) $instance->getConfig();
            if ($override !== []) {
                $raw = $override;
            }
        }

        return PluginConfigSchema::normalize($raw);
    }

    /**
     * 获取插件配置页的展示元数据（标签页 / 分区的中文标题与顺序）
     *
     * 来源为 manifest 顶层 `config_tabs` / `config_groups`（值支持 `{title,sort}` 或字符串简写），
     * 渲染器 {@see PluginConfigForm::render()} 用它覆盖内置标题表 —— 插件自定义标签页/分区的
     * 中文名与排序不必改框架代码。
     *
     * @param string $name 插件名称（目录名）
     * @return array{tabs: array<string, mixed>, groups: array<string, mixed>}
     * @throws PluginNotFoundException 插件不存在时
     */
    public function getPluginConfigMeta(string $name): array
    {
        $metadata = $this->getPlugin($name);

        return [
            'tabs'   => $metadata->getConfigTabs(),
            'groups' => $metadata->getConfigGroups(),
        ];
    }

    /**
     * 获取插件的生效配置值（schema 默认值 ⊕ 已存储值）。
     *
     * 返回的键集始终等于 schema 的键集，即使从未保存过配置，
     * 调用方也能拿到 manifest 声明的默认值。
     *
     * @param string $name 插件名称
     * @return array<string, mixed>
     */
    public function getPluginConfig(string $name): array
    {
        return $this->getConfig()->values($name, $this->getPluginConfigSchema($name));
    }

    /**
     * 保存插件配置（白名单 + 类型校验）。
     *
     * 安全约束：
     *   - 仅接受 schema 中声明过的键，其余一律丢弃并记录日志
     *   - 拒绝 '__' 前缀的框架内部状态键，杜绝伪造 __state.installed / __state.enabled
     *   - 插件未声明任何 config 却尝试写入时抛出异常
     *
     * @param string $name   插件名称
     * @param array  $config 待保存的配置（键必须是 schema 中声明的）
     * @return array{saved: array, dropped: string[]} 实际保存的键值与被丢弃的键
     * @throws PluginConfigException schema 为空却尝试写入时
     */
    public function setPluginConfig(string $name, array $config): array
    {
        $schema = $this->getPluginConfigSchema($name);

        if ($schema === []) {
            if ($config !== []) {
                throw new PluginConfigException(sprintf(
                    '插件 "%s" 未声明任何配置项（manifest.json 的 config 字段为空），拒绝写入配置',
                    $name
                ));
            }

            return ['saved' => [], 'dropped' => []];
        }

        $saved   = [];
        $dropped = [];

        foreach ($config as $key => $value) {
            $key = (string) $key;

            if (PluginConfigSchema::isReserved($key) || !isset($schema[$key])) {
                $dropped[] = $key;
                continue;
            }

            $saved[$key] = PluginConfigSchema::validate($schema[$key], $value);
        }

        if ($dropped !== []) {
            ContextualLogger::warning(sprintf(
                '插件 "%s" 配置保存时丢弃了 %d 个未声明的键：%s',
                $name,
                count($dropped),
                implode(', ', $dropped)
            ));
        }

        if ($saved !== []) {
            $this->getConfig()->setMany($name, $saved);
        }

        return ['saved' => $saved, 'dropped' => $dropped];
    }

    /**
     * 播种插件配置的默认值（幂等）。
     *
     * 仅补齐「schema 中声明了、但库中还没有」的键，
     * 绝不覆盖用户已保存的值 —— 保证升级新增配置项时用户设置不丢失。
     *
     * 存储 key 使用插件目录名，与 PluginStateManager 保持一致
     * （manifest 的 ident 可能与目录名不同，不可用作存储 key）。
     *
     * @param string $name   插件名称（目录名）
     * @param array  $schema 已归一化的 schema；为空时自动从 manifest 读取
     * @return string[] 本次实际播种的键名
     */
    public function seedDefaultConfig(string $name, array $schema = []): array
    {
        $schema = $schema ?: $this->getPluginConfigSchema($name);
        if ($schema === []) {
            return [];
        }

        $defaults = PluginConfigSchema::defaults($schema);
        $stored   = $this->getConfig()->all($name);

        $seeded = [];
        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $stored)) {
                continue;
            }

            $this->getConfig()->set($name . '.' . (string) $key, $value);
            $seeded[] = (string) $key;
        }

        if ($seeded !== []) {
            $this->getConfig()->flushCache($name);
            ContextualLogger::info(sprintf(
                '插件 "%s" 已播种 %d 个配置默认值：%s',
                $name,
                count($seeded),
                implode(', ', $seeded)
            ));
        }

        return $seeded;
    }

    // ==================== 内部工具方法 ====================

    /**
     * 调用插件的指定钩子方法（兼容旧系统）。
     *
     * @param string $name   插件名称
     * @param string $hook   钩子/方法名称
     * @param array  $params 传入参数
     * @return mixed 钩子返回值，插件不存在时返回 null
     */
    public function callHook(string $name, string $hook, array $params = []): mixed
    {
        $pluginClass = 'plugin\\' . $name . '\\Plugin';
        $pluginDir = $this->getPluginDirectory();
        $pluginFile = $pluginDir . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'Plugin.php';

        if (!class_exists($pluginClass)) {
            if (!file_exists($pluginFile)) {
                return null;
            }
            include_once $pluginFile;
            if (!class_exists($pluginClass)) {
                return null;
            }
        }

        if (!method_exists($pluginClass, $hook)) {
            return null;
        }

        try {
            $plugin = new $pluginClass();
            return call_user_func_array([$plugin, $hook], $params);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 下载远程文件到本地路径。
     */
    protected function downloadFile(string $url, string $savePath): void
    {
        $dir = dirname($savePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $context = stream_context_create([
            'http' => ['timeout' => 120],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            throw new PluginInstallException("下载文件失败：{$url}");
        }

        if (file_put_contents($savePath, $content) === false) {
            throw new PluginInstallException("写入文件失败：{$savePath}");
        }
    }

    /**
     * 递归复制目录。
     */
    protected function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($source)) {
            return;
        }
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $sourcePrefix = rtrim($source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($sourcePrefix));
            $target = $dest . DIRECTORY_SEPARATOR . $relativePath;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                if (!copy($item->getPathname(), $target)) {
                    throw new \RuntimeException(sprintf(
                        '复制文件失败: %s → %s',
                        $item->getPathname(),
                        $target
                    ));
                }
            }
        }
    }

    // ==================== 查询方法 ====================

    /**
     * 获取指定插件的元数据。
     *
     * @param string $name 插件名称
     * @return PluginMetadata
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
     * 检查插件是否已安装（即迁移/填充等框架集成是否已执行）。
     *
     * 判定顺序：
     *   1. 插件目录未扫描到 → 未安装
     *   2. 有显式安装记录（plugin_config.__state.installed）→ 已安装
     *   3. 兼容历史数据：无迁移文件 → 视为已安装；有迁移文件但无任何迁移记录 → 未安装
     *
     * @param string $name 插件名称
     * @param bool   $heal 走到迁移记录兜底分支时是否补写状态键（S-1 自愈）。
     *                     供 inspectInstallConflict() 传 false —— 自检要看到"当前未修复的事实"，
     *                     若自检本身会顺手修好，就无法再暴露分裂态。
     * @return bool
     */
    public function isInstalled(string $name, bool $heal = true): bool
    {
        static::validatePluginName($name);

        if (!isset($this->getCachedPlugins()[$name])) {
            return false;
        }

        if ($this->getStateManager()->isInstalled($name)) {
            return true;
        }

        // 兼容历史安装：该版本之前没有持久化安装状态，用迁移记录反推。
        // 注意：无迁移文件的插件无法反推 —— 全新系统下 demo / wiseeditor 等
        // 无迁移文件的插件会被误判为「已安装」（没有数据库痕迹却显示已安装，
        // 且状态停在"已安装未启用"），因此此处必须返回未安装；
        // 这类插件安装后由 install() 写入 __state.installed 显式记录（上方分支已覆盖）。
        if (!$this->getMigrationRunner()->hasMigrations($name)) {
            return false;
        }

        if ($this->migratedPlugins === null) {
            $this->migratedPlugins = $this->getMigrationRunner()->getMigratedPlugins();
        }

        if (!in_array($name, $this->migratedPlugins, true)) {
            return false;
        }

        // 【S-1 自愈】走到这里说明"迁移记录说装过，但状态键丢了"。
        // 若不补写，同一份事实会被两条链路读出两个结论：走兜底的地方判"已安装"，
        // 只看状态键的地方判"未安装"（历史 bug 正是列表说未安装、安装说已安装）。
        // 补写后状态键成为唯一真相，兜底分支下次自然走不到。
        if ($heal) {
            $this->healInstalledState($name);
        }

        return true;
    }

    /**
     * 兜底判定命中时，把安装状态补写回状态键（S-1 自愈）。
     *
     * 自愈写入失败（只读库 / plugin_config 表缺失）不影响本次判定结果，仅记日志。
     *
     * @param string $name 插件名称
     * @return void
     */
    protected function healInstalledState(string $name): void
    {
        if (isset($this->healedInstalledState[$name])) {
            return;
        }
        $this->healedInstalledState[$name] = true;

        try {
            $this->getStateManager()->setInstalled($name, true);
            ContextualLogger::info(sprintf(
                '插件 [%s] 安装状态自愈：检测到迁移记录但缺少 __state.installed，已补写为 true',
                $name
            ));
        } catch (\Throwable $e) {
            ContextualLogger::warning(sprintf('插件 [%s] 安装状态自愈失败：%s', $name, $e->getMessage()));
        }
    }

    /**
     * 获取插件当前所处的生命周期状态。
     *
     * 四态互斥：
     *   - PluginManager::STATE_NOT_INSTALLED 未安装（文件在，库没建）→ 只能"安装"
     *   - PluginManager::STATE_INSTALLED     已安装未启用          → 可启用/配置/升级/卸载
     *   - PluginManager::STATE_ENABLED       已安装已启用          → 可禁用/配置/升级/卸载
     *   - PluginManager::STATE_INCONSISTENT  数据与状态不一致      → 只能"重装"（见 inspectInstallConflict）
     *
     * @param string $name 插件名称
     * @return string 四态常量之一
     */
    public function getPluginState(string $name): string
    {
        return $this->resolveState($name, $this->inspectInstallConflict($name));
    }

    /**
     * 由自检结果推导生命周期状态（getPluginState 与列表共用，避免两处口径漂移）。
     *
     * @param string $name    插件名称
     * @param array  $inspect inspectInstallConflict() 的返回值
     * @return string 状态常量之一
     */
    protected function resolveState(string $name, array $inspect): string
    {
        // 一致性优先：残留表 / 表丢失必须显式暴露，不能混进"已安装" ——
        // 否则界面给出"已安装·未启用"，用户点启用才发现表根本不在。
        if (($inspect['severity'] ?? 'ok') !== 'ok') {
            return self::STATE_INCONSISTENT;
        }

        if (empty($inspect['installed'])) {
            return self::STATE_NOT_INSTALLED;
        }

        return $this->isEnabled($name) ? self::STATE_ENABLED : self::STATE_INSTALLED;
    }

    /**
     * 安装一致性自检：把三处事实（状态键 / 迁移记录 / 实际数据表）摆在一起（S-3）。
     *
     * 【为什么需要】"是否已安装"目前由两条链路拼出来（状态键优先、迁移记录兜底），
     * 而"插件声明的数据表是否真的存在"这一最硬的证据**不参与任何判定**。卸载时迁移
     * 回滚失败只记日志、状态照样清空，于是必然产出"表还在、状态没了"的分裂态：
     * 界面显示"未安装"，点安装却报"数据表已存在"。本方法把这种状态显式暴露出来，
     * 交给运营在界面上选择"仅修复状态（保留数据）"或"清库重装"。
     *
     * 注意：本方法内部用 isInstalled($name, false) 取"未修复前的事实"，
     * 不能在自检里触发 S-1 自愈，否则自检永远看不到冲突。
     *
     * @param string $name 插件名称
     * @return array{
     *   installed: bool, state_key: bool, migration_rows: int,
     *   declared_tables: int, residual_tables: string[],
     *   conflict: bool, severity: 'ok'|'residual'|'dropped'
     * }
     */
    public function inspectInstallConflict(string $name): array
    {
        static::validatePluginName($name);

        $runner   = $this->getMigrationRunner();
        $declared = $runner->tableNames($name);
        $recorded = $this->getStateManager()->getResidualTables($name);
        $detected = $runner->remainingTables($name);

        // 残留表 = 卸载时登记下来的 ∪ 当前实测仍在的。
        // 两者取并集的原因：登记项在插件目录被删后仍然有效（实测探不到），
        // 而实测能发现"没登记但确实还在"的历史残留。
        $residual = array_values(array_unique(array_merge($recorded, $detected)));

        $severity = $this->installConflictSeverity($name);

        return [
            'installed'       => $this->isInstalled($name, false),
            'state_key'       => $this->getStateManager()->isInstalled($name),
            'migration_rows'  => $runner->migrationCount($name),
            // 目录被删时 tableNames() 为空，用登记项兜底，避免弹窗显示"19 / 0 张"
            'declared_tables' => count($declared) ?: count($recorded),
            'recorded_tables' => $recorded,
            'residual_tables' => $residual,
            'conflict'        => $severity !== 'ok',
            'severity'        => $severity,
        ];
    }

    /**
     * 安装一致性冲突级别（自检与列表共用的判定核心）。
     *
     * 语义：
     *   ok       三处自洽（表在且已安装 / 无表且未安装）
     *   residual 建表迁移声明过表、库中仍有残留，但状态说"未安装" → 卸载/回滚清了一半
     *   dropped  状态说"已安装"，但声明的表一张都不在 → 表被手工删除或回滚越界
     *
     * @param string $name 插件名称
     * @return string ok|residual|dropped
     */
    protected function installConflictSeverity(string $name): string
    {
        $runner = $this->getMigrationRunner();

        // ① 最可靠的证据：卸载当时就把残留表名登记下来了。
        //    不能只靠"状态键缺失"来推断 —— 卸载可能已把状态清掉（正常路径），
        //    也可能因为各种原因状态还在；而登记项只在"确实没清干净"时才写，
        //    且插件目录被删（CLI 卸载默认删目录）后仍认得出这批表。
        if ($this->getStateManager()->getResidualTables($name) !== []) {
            return 'residual';
        }

        $stateKey = $this->getStateManager()->isInstalled($name);

        $declared = $runner->tableNames($name);
        $residual = $runner->remainingTables($name);

        // ② 兼容历史数据：没有登记项，但表确实还在、状态说"未安装"
        if ($residual !== [] && !$stateKey) {
            return 'residual';
        }

        if ($residual === [] && $stateKey && $declared !== []) {
            return 'dropped';
        }

        return 'ok';
    }

    /**
     * 【P1 repair 模式】仅修复安装状态：**保留数据表**，把状态与框架集成补齐。
     *
     * 适用场景：表结构完好，但安装状态键丢失（历史卸载/回滚不彻底），
     * 界面显示"未安装"、点安装必然撞 1050「Table ... already exists」。
     * 本方法**不跑迁移、不跑 seed、不 drop 表、不删业务配置**，因而是安全操作。
     *
     * @param string $name 插件名称
     * @return array{mode: string, residual_tables: string[], state: string}
     * @throws PluginNotFoundException|PluginInstallException
     */
    public function repairInstallState(string $name): array
    {
        static::validatePluginName($name);
        $metadata = $this->getPlugin($name);

        $runner   = $this->getMigrationRunner();
        $declared = $runner->tableNames($name);
        $recorded = $this->getStateManager()->getResidualTables($name);
        $residual = array_values(array_unique(array_merge($recorded, $runner->remainingTables($name))));

        // 表一张都不在 → "仅修复状态"会把插件标成已安装却没有任何表，属有害操作，直接拒绝
        if ($declared !== [] && $residual === []) {
            throw new PluginInstallException(sprintf(
                '插件 "%s" 声明的数据表一张都不存在，"仅修复状态"不适用，请改用「清库重装」重建数据表',
                $name
            ));
        }

        // 1. 补写安装状态。enabled 保持原值：缺失时视为未启用，由用户显式启用，
        //    避免"修复状态"这个动作顺带把插件打开。
        $this->getStateManager()->setInstalled($name, true);

        // 1b. 残留登记作废：这些表已被"认领"回来（状态=已安装 ∧ 表存在 → 自洽），
        //     不清掉的话列表会一直停在「数据残留·需修复」。
        $this->getStateManager()->setResidualTables($name, []);

        // 2. 菜单 / 权限幂等注册（register() 保留运营已有的改名与排序）
        try {
            Db::startTrans();
            $this->getMenuRegistrar()->register($metadata);
            $this->getPermissionRegistrar()->register($metadata);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw new PluginInstallException('修复安装状态时菜单/权限注册失败：' . $e->getMessage());
        }

        // 3. 配置默认值幂等补齐（仅补缺失键，不动已存值）
        try {
            $this->seedDefaultConfig($name);
        } catch (\Throwable $e) {
            ContextualLogger::warning("插件 [{$name}] 修复安装状态：配置默认值播种失败：" . $e->getMessage());
        }

        // 4. 服务提供者（注册路由/服务），与 install() 第 6 步同口径
        $this->initServiceProvider($name);

        // 5. 清缓存
        $this->clearCache();
        $this->migratedPlugins      = null;
        $this->healedInstalledState = [];

        ContextualLogger::info(sprintf(
            '插件 [%s] 已仅修复安装状态（保留 %d 张残留数据表）',
            $name,
            count($residual)
        ));

        return [
            'mode'            => 'repair',
            'residual_tables' => $residual,
            'state'           => $this->getPluginState($name),
        ];
    }

    /**
     * 【P1 force 模式第一步】清库：回滚并删除该插件的全部数据表与迁移记录。
     *
     * 只做"清空到未安装"，**不动插件目录与静态资源**，以便紧接着调用 install() 重建。
     * 之所以要顺带删迁移记录：`--all` 走文件法回滚，记录表里可能仍留有本插件记录，
     * 不清掉的话重装时 `plugin:migrate:run` 会认为"已执行"而跳过 up()，表不会被建回来。
     *
     * @param string $name 插件名称
     * @return array{residual_tables: string[], cleared: bool, message: string}
     */
    public function purgeResidual(string $name): array
    {
        static::validatePluginName($name);

        $runner   = $this->getMigrationRunner();
        $rollback = $runner->rollback($name, 0);
        $removed  = $runner->clearMigrationRecords($name);

        // 待清理的表 = 卸载时登记的 ∪ 当前实测仍在的（登记项在目录被删后依然有效）
        $recorded = $this->getStateManager()->getResidualTables($name);
        $residual = array_values(array_unique(array_merge($recorded, $runner->remainingTables($name))));

        // 兜底物理删除：插件迁移的 down() 未必真能删表。Phinx 的 `Table::drop()`
        // 只把 DropTable 动作入队，必须 `->drop()->save()` 才执行；仓内 25 个迁移文件
        // 漏了这个 save()，rollback 会"报成功但表全留在库里"。
        // 「清库重装」的语义就是"清干净"，因此对残留表直接 DROP，不让用户卡死。
        $hardDropped = [];
        if ($residual !== []) {
            ContextualLogger::warning(sprintf(
                '插件 [%s] 清库重装：回滚后仍残留 %d 张数据表（多为迁移 down() 漏写 ->save()），转为直接删除：%s',
                $name,
                count($residual),
                implode(', ', $residual)
            ));

            $hardDropped = $runner->dropTables($residual);
            $residual    = $runner->remainingTables($name);
        }

        // 登记同步：清干净就删掉登记项；仍有残留就更新为最新清单，
        // 让列表状态与库中事实始终一致（不残留过期的表名）。
        $this->getStateManager()->setResidualTables($name, $residual);

        if ($residual !== []) {
            ContextualLogger::error(sprintf(
                '插件 [%s] 清库重装：仍有 %d 张数据表无法删除：%s',
                $name,
                count($residual),
                implode(', ', $residual)
            ));
        }

        $this->migratedPlugins = null;

        return [
            'residual_tables' => $residual,
            'cleared'         => $residual === [],
            'hard_dropped'    => $hardDropped,
            'message'         => $residual === []
                ? sprintf(
                    '已清理插件 [%s] 的数据表（迁移记录 %d 条%s）',
                    $name,
                    $removed,
                    $hardDropped === [] ? '' : sprintf('，兜底直删 %d 张表', count($hardDropped))
                )
                : $rollback['message'],
        ];
    }

    /**
     * 将插件标识或显示名（manifest 的 title，可能是中文）解析为插件标识。
     *
     * 前端列表渲染时展示的是显示名，而生命周期操作需要的是目录名/标识，
     * 该方法用于消除两者之间的差异。无法匹配时原样返回。
     *
     * @param string $nameOrTitle 插件标识或显示名
     * @return string 插件标识（找不到时返回入参）
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
     * 检查插件是否已启用。
     *
     * @param string $name 插件名称
     * @return bool
     */
    public function isEnabled(string $name): bool
    {
        static::validatePluginName($name);

        $plugins = $this->getCachedPlugins();

        if (!isset($plugins[$name])) {
            return false;
        }

        return $this->getStateManager()->isEnabled($name);
    }

    /**
     * 缺失的库包依赖（仅**硬依赖**，返回非空即表示该插件不应被加载）
     *
     * manifest.packages 声明的 composer 库包（如 `wiseadmin/wise-member`）未安装时，
     * 插件可能连继承的基类、中间件类都加载不了 —— 由此判定。
     *
     * @param  PluginMetadata $metadata
     * @return array<string, array{package:string, constraint:string, required:bool, version:?string, message:string}>
     */
    public function missingPackages(PluginMetadata $metadata): array
    {
        return $this->packageDependencyChecker()->missingRequired($metadata);
    }

    /**
     * 库包依赖诊断（含软依赖，供插件列表 / CLI 展示）
     *
     * @param  PluginMetadata $metadata
     * @return array<string, array{package:string, constraint:string, required:bool, version:?string, message:string}>
     */
    public function packageDependencyReport(PluginMetadata $metadata): array
    {
        return $this->packageDependencyChecker()->missing($metadata);
    }

    /**
     * 库包依赖检查器（进程内复用，避免重复读取 installed.json）
     */
    protected function packageDependencyChecker(): PackageDependencyChecker
    {
        return $this->packageDependencies ??= new PackageDependencyChecker();
    }

    // ==================== 服务提供者初始化 ====================

    /**
     * 初始化插件的服务提供者。
     *
     * 所有插件继承 WiseBootServiceProvider（think\Service），通过 TP 原生
     * register/bootService 完成完整初始化。
     *
     * @param string $name 插件名称
     * @return void
     */
    public function initServiceProvider(string $name): void
    {
        static::validatePluginName($name);

        $plugins = $this->getCachedPlugins();

        if (!isset($plugins[$name])) {
            return;
        }

        $metadata = $plugins[$name];

        // 库包依赖硬闸门（兜底）：HTTP 加载器 / CLI 命令注册 / API 与插件按需分发
        // 四条入口最终都会走到这里。插件声明**硬依赖**（manifest.packages）但库包未安装时，
        // 它继承的基类、中间件类都不存在 —— 直接不加载并记日志，避免致命错误；库包装回后
        // 无需人工干预，下次请求自动恢复。
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
                // 日志不可用，忽略
            }

            return;
        }

        $serviceProviderClass = $metadata->getServiceProvider();

        // 先注册插件 PSR-4 命名空间，再尝试加载服务提供者类。
        // 否则在"禁用 → 启用"流程中，插件的命名空间从未注册过（composer 不映射 plugin\*），
        // class_exists 会返回 false，导致报"服务提供者类不存在"。
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
     * 使用场景：
     *   请求进入 PluginDispatch 之前，就可能有逻辑需要 class_exists() 探测插件控制器。
     *   典型是 app\plugin\middleware\PluginAdminAuth —— 它要判定控制器是否继承
     *   plugin\BaseController，以决定是否施加插件后台鉴权。
     *   而插件 boot（含命名空间注册）发生在 PluginDispatch 阶段，此时尚未注册：
     *     a) class_exists() 必然返回 false，判定失败 → 鉴权被整体绕过；
     *     b) 这次失败还会被 Composer ClassLoader 记入 $missingClasses 负缓存，
     *        addPsr4() 并不清除它，使得随后 initServiceProvider() 注册命名空间后
     *        依然探测不到类 → PluginDispatch 抛「Plugin controller not exists」403。
     *
     * 与 initServiceProvider() 的区别：本方法只注册命名空间，不加载 config /
     * common.php / 事件 / 路由 / 中间件，因此在请求中期调用不会产生
     * 「路由、中间件半路生效」之类的副作用，可安全用于前置判定。
     *
     * @param string $name 插件名称
     * @return void
     */
    public function ensureNamespace(string $name): void
    {
        static::validatePluginName($name);

        $plugins = $this->getCachedPlugins();

        if (!isset($plugins[$name])) {
            return;
        }

        $pluginDir   = $this->getPluginDirectory() . DIRECTORY_SEPARATOR . $name;
        $registrar   = $this->getNamespaceRegistrar();

        // register() 内部会在 addPsr4() 后清空该前缀的负缓存，保证注册后立即可探测。
        $registrar->register($name, $pluginDir);
        $registrar->registerVendor($pluginDir, $plugins[$name]->getVendor());
    }

    // ==================== 核心服务获取 ====================

    protected function getScanner(): PluginScanner
    {
        return $this->app->get(PluginContainerKeys::SCANNER);
    }

    protected function getHookDispatcher(): HookDispatcher
    {
        return $this->app->get(PluginContainerKeys::HOOK_DISPATCHER);
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
        if ($this->stateManager === null) {
            $this->stateManager = new PluginStateManager($this->getConfig());
        }
        return $this->stateManager;
    }

    // ==================== Adapter 获取（懒加载） ====================

    /**
     * 获取菜单注册器。
     */
    public function getMenuRegistrar(): MenuRegistrar
    {
        if ($this->menuRegistrar === null) {
            $this->menuRegistrar = new MenuRegistrar();
        }
        return $this->menuRegistrar;
    }

    /**
     * 获取权限注册器。
     */
    public function getPermissionRegistrar(): PermissionRegistrar
    {
        if ($this->permissionRegistrar === null) {
            $this->permissionRegistrar = new PermissionRegistrar();
        }
        return $this->permissionRegistrar;
    }

    /**
     * 获取静态资源管理器。
     */
    public function getAssetManager(): AssetManager
    {
        if ($this->assetManager === null) {
            $this->assetManager = new AssetManager();
        }
        return $this->assetManager;
    }

    /**
     * 获取数据库迁移执行器。
     */
    public function getMigrationRunner(): MigrationRunner
    {
        if ($this->migrationRunner === null) {
            $this->migrationRunner = new MigrationRunner($this->app);
        }
        return $this->migrationRunner;
    }

    /**
     * 获取路由加载器。
     */
    public function getRouteLoader(): RouteLoader
    {
        if ($this->routeLoader === null) {
            $this->routeLoader = new RouteLoader();
        }
        return $this->routeLoader;
    }

    /**
     * 获取命名空间注册器。
     */
    public function getNamespaceRegistrar(): NamespaceRegistrar
    {
        if ($this->namespaceRegistrar === null) {
            $this->namespaceRegistrar = new NamespaceRegistrar();
        }
        return $this->namespaceRegistrar;
    }

    /**
     * 获取插件 Plugin 主类实例。
     *
     * 加载 plugins/{name}/Plugin.php，注入 PluginMetadata 后返回实例。
     * 实例按名称缓存（请求级）。
     *
     * @param string $name 插件名称
     * @return Plugin|null 返回 Plugin 实例，无 Plugin.php 时返回 null
     */
    protected function getPluginInstance(string $name): ?Plugin
    {
        if (isset($this->pluginInstances[$name])) {
            return $this->pluginInstances[$name];
        }

        $pluginClass = "plugin\\{$name}\\Plugin";
        $pluginDir = $this->getPluginDirectory();
        $pluginFile = $pluginDir . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'Plugin.php';

        if (!class_exists($pluginClass)) {
            if (!file_exists($pluginFile)) {
                $this->pluginInstances[$name] = null;
                return null;
            }
            include_once $pluginFile;
            if (!class_exists($pluginClass)) {
                $this->pluginInstances[$name] = null;
                return null;
            }
        }

        /** @var Plugin $instance */
        $instance = new $pluginClass();

        try {
            $metadata = $this->getPlugin($name);
            $instance->setMetadata($metadata);
        } catch (\Throwable $e) {
            // 元数据获取失败不影响插件实例化
        }

        $this->pluginInstances[$name] = $instance;
        return $instance;
    }

    // ==================== 清单文件管理 ====================

    /**
     * 更新插件的清单文件（manifest.json）。
     *
     * @deprecated 当前未被调用，保留供未来版本管理功能使用。
     */
    protected function updatePluginManifest(string $name, PluginMetadata $metadata): void
    {
        $pluginDir = $this->getPluginDirectory();
        $manifestPath = $pluginDir . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'manifest.json';

        if (!file_exists($manifestPath)) {
            return;
        }

        $json = json_encode($metadata->toManifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        $handle = fopen($manifestPath, 'c+b');
        if ($handle === false) {
            return;
        }

        if (flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            fwrite($handle, $json);
            fflush($handle);
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        $this->getScanner()->refreshCache();
    }

    // ==================== 工具方法 ====================

    /**
     * 获取插件目录路径。
     */
    protected function getPluginDirectory(): string
    {
        $config = $this->app->config->get('plugin', []);
        return $config['plugin_dir'] ?? $this->app->getRootPath() . 'plugins';
    }

    /**
     * 递归删除目录。
     */
    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        $errors = [];
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                if (!rmdir($path)) {
                    $errors[] = "Failed to remove directory: {$path}";
                }
            } else {
                if (!unlink($path)) {
                    $errors[] = "Failed to remove file: {$path}";
                }
            }
        }

        if (!rmdir($dir)) {
            $errors[] = "Failed to remove root directory: {$dir}";
        }

        if (!empty($errors)) {
            throw new PluginException(sprintf(
                'Failed to fully remove plugin directory "%s": %s',
                $dir,
                implode('; ', $errors)
            ));
        }
    }
}
