# Wise 插件管理系统

> 基于 ThinkPHP 8 的分层、可扩展企业级插件生态系统

---

## 目录

- [1. 概述](#1-概述)
- [2. 架构设计](#2-架构设计)
  - [2.1 整体架构图](#21-整体架构图)
  - [2.2 核心分层](#22-核心分层)
  - [2.3 目录结构](#23-目录结构)
- [3. 插件加载全链路](#3-插件加载全链路)
  - [3.1 请求时加载（PluginLoader 中间件）](#31-请求时加载pluginloader-中间件)
  - [3.2 CLI 时加载（PluginServiceProvider）](#32-cli-时加载pluginserviceprovider)
  - [3.3 服务提供者继承体系](#33-服务提供者继承体系)
- [4. 核心组件](#4-核心组件)
  - [4.1 PluginManager — 生命周期管理器](#41-pluginmanager--生命周期管理器)
  - [4.2 PluginServiceProvider — 服务注册](#42-pluginserviceprovider--服务注册)
  - [4.3 PluginScanner — 插件发现](#43-pluginscanner--插件发现)
  - [4.4 PluginMetadata — 元数据值对象](#44-pluginmetadata--元数据值对象)
  - [4.5 PluginStateManager — 状态管理](#45-pluginstatemanager--状态管理)
  - [4.6 DependencyChecker — 依赖检查](#46-dependencychecker--依赖检查)
  - [4.7 生命周期事件（ThinkPHP Event）](#47-生命周期事件thinkphp-event)
  - [4.8 PluginConfig — 配置管理](#48-pluginconfig--配置管理)
  - [4.9 PluginLoader — 请求中间件](#49-pluginloader--请求中间件)
  - [4.10 CapabilityResolver — 能力协商](#410-capabilityresolver--能力协商)
- [5. Adapter 层（框架集成）](#5-adapter-层框架集成)
  - [5.1 MenuRegistrar](#51-menuregistrar)
  - [5.2 PermissionRegistrar](#52-permissionregistrar)
  - [5.3 AssetManager](#53-assetmanager)
  - [5.4 MigrationRunner](#54-migrationrunner)
  - [5.5 RouteLoader](#55-routeloader)
  - [5.6 NamespaceRegistrar](#56-namespaceregistrar)
- [6. 插件市场](#6-插件市场)
- [7. 标准 Composer 包接入（无桥接）](#7-标准-composer-包接入无桥接)
- [8. CLI 命令](#8-cli-命令)
- [9. 异常体系](#9-异常体系)
- [10. 插件清单格式（manifest.json v2）](#10-插件清单格式manifestjson-v2)
- [11. 配置说明](#11-配置说明)
- [12. 插件生命周期流程](#12-插件生命周期流程)
  - [12.1 安装流程](#121-安装流程)
  - [12.2 卸载流程](#122-卸载流程)
  - [12.3 启用流程](#123-启用流程)
  - [12.4 禁用流程](#124-禁用流程)
  - [12.5 升级流程](#125-升级流程)
- [13. 安全设计](#13-安全设计)
- [14. 性能优化](#14-性能优化)
- [15. 事件系统](#15-事件系统)
- [16. 使用示例](#16-使用示例)

---

## 1. 概述

Wise 插件管理系统是一个功能完整的企业级 PHP 插件框架，为 ThinkPHP 8 应用提供：

- **完整生命周期管理**：安装、卸载、启用、禁用、升级
- **依赖管理**：语义化版本约束、循环依赖检测、反向依赖解析
- **框架深度集成**：菜单、权限、路由、静态资源、数据库迁移、前端插槽
- **可插拔配置存储**：数据库驱动（默认）和文件驱动，可动态切换
- **生命周期事件**：安装/卸载/启停统一走 ThinkPHP 原生 Event（插件在自身 `event.php` 监听）
- **插件市场**：支持本地市场和远程 HTTP API 市场
- **标准 Composer 包接入**：包内以 `extra.think`（services/config/publish）声明，可选 ServiceProvider，**不复制到 `plugins/`**（见 `docs/wise-package-thin-spec.md`）
- **无 `Plugin.php`**：插件不再有"主类"。建表/初始数据用 `database/migrations` + `database/seeds`，外部副作用用 `event.php` 监听生命周期事件
- **清单格式**：统一使用 `manifest.json`（唯一清单文件）。[详见第 10 节](#10-插件清单格式)
- **能力协商**：三层协商机制确保插件运行时能力需求被满足

---

## 2. 架构设计

### 2.1 整体架构图

```
┌─────────────────────────────────────────────────────────────────┐
│                        应用层 (Application)                       │
│  HTTP 请求 → PluginLoader 中间件 → 业务控制器                      │
└──────────────────────────────┬──────────────────────────────────┘
                               │
┌──────────────────────────────┴──────────────────────────────────┐
│                 PluginServiceProvider (容器注册)                   │
│  ┌─────────┐ ┌──────────┐ ┌────────────┐ ┌───────────────────┐  │
│  │ Scanner │ │  Config  │ │ Dependency │ │ ThinkPHP Event     │  │
│  └─────────┘ └──────────┘ │  Checker   │ └───────────────────┘  │
│                           └────────────┘                        │
└──────────────────────────────┬──────────────────────────────────┘
                               │
┌──────────────────────────────┴──────────────────────────────────┐
│               PluginManager (核心编排器)                           │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │   install() / uninstall() / enable() / disable() /       │   │
│  │   upgrade() / getPlugin() / getAllPlugins()              │   │
│  └──────────────────────────────────────────────────────────┘   │
│                              │                                    │
│  ┌───────────┬───────────────┼───────────────┬───────────┐      │
│  │  Adapter  │    Config     │   Market      │ Capability│      │
│  │   层      │    管理        │   市场         │   协商     │      │
│  └───────────┴───────────────┴───────────────┴───────────┘      │
└──────────────────────────────────────────────────────────────────┘
```

### 2.2 核心分层

| 层次 | 职责 | 关键组件 |
|------|------|----------|
| **核心层** | 生命周期编排、状态管理 | `PluginManager`, `PluginStateManager`, `PluginContainerKeys` |
| **发现层** | 扫描插件目录、解析元数据 | `PluginScanner`, `PluginMetadata` |
| **依赖层** | 依赖检查、循环检测、版本约束 | `DependencyChecker` |
| **事件层** | 生命周期通知（安装/卸载/启停） | ThinkPHP `Event` + 插件侧 `event.php` |
| **配置层** | 可插拔配置存储 | `PluginConfig`, `DatabaseConfigDriver`, `FileConfigDriver` |
| **适配层** | 框架集成（菜单/权限/路由/资源等） | 7 个 Adapter 类 |
| **市场层** | 插件发现和下载 | `LocalMarket`, `HttpMarket` |
| **Composer 包层** | 标准 Composer 包接入（`extra.think` + 可选 ServiceProvider，零复制） | 宿主 `service:discover` / `wise:vendor:publish` / `PackageRegistry` |
| **能力层** | 插件能力需求协商 | `CapabilityResolver`, `CapabilityResult` |
| **中间件层** | 请求时延迟初始化 | `PluginLoader` |
| **CLI 层** | 命令行管理工具 | 19 个命令类 |

### 2.3 目录结构

```
plugin/
├── adapter/                  # 框架集成适配层（7 个文件）
│   ├── AssetManager.php      # 静态资源管理
│   ├── MenuRegistrar.php     # 菜单注册
│   ├── MigrationRunner.php   # 数据库迁移执行器
│   ├── NamespaceRegistrar.php # PSR-4 命名空间注册
│   ├── PermissionRegistrar.php # 权限注册
│   └── RouteLoader.php       # 路由加载
│
├── capability/               # 插件能力协商系统
│   ├── CapabilityResolver.php # 能力解析器
│   └── CapabilityResult.php   # 能力解析结果
│
├── command/                  # CLI 命令（19 个命令类）
│   ├── PluginInstall.php     # 安装命令
│   ├── PluginUninstall.php   # 卸载命令
│   ├── PluginEnable.php      # 启用命令
│   ├── PluginDisable.php     # 禁用命令
│   ├── PluginScan.php        # 扫描命令

│   ├── PluginCreate.php      # 创建插件命令
│   ├── ConnectorCreate.php   # 创建连接器命令
│   ├── PluginCommand.php     # 命令抽象基类
│   ├── db/                   # 数据库适配器
│   ├── migrate/              # 迁移子命令（Run/Create/Rollback/Breakpoint/Status）
│   └── seed/                 # Seeder 子命令（Run/Create）
│
│
├── config/                   # 配置管理
│   ├── plugin.php            # 默认配置文件
│   ├── PluginConfig.php      # 配置存储（值）
│   ├── PluginSettings.php    # 配置 schema / 读写 / 默认值播种
│   ├── PluginConfigFacade.php # Facade 静态代理
│   └── driver/               # 配置驱动
│       ├── DatabaseConfigDriver.php
│       └── FileConfigDriver.php
│
├── contract/                 # 接口契约
│   ├── PluginServiceProviderInterface.php
│   ├── PluginConfigDriverInterface.php
│   └── MarketDriverInterface.php
│
├── database/                 # 数据库迁移
│   └── migrations/
│       └── 20260630154501_create_plugin_config_table.php
│
├── dependency/               # 依赖管理
│   └── DependencyChecker.php
│
├── exception/                # 异常类（6 个）
├── lifecycle/                # 生命周期与状态（2 个）
│   ├── PluginLifecycle.php   # 安装/卸载/启用/禁用/升级
│   └── PluginStateInspector.php # 状态自检、自愈、修复、清库
│
├── listing/                  # 列表/市场展示（1 个）
│   └── PluginListPresenter.php # 本地列表、单行、操作按钮、远程市场
│
├── market/                   # 插件市场（3 个）
├── middleware/               # HTTP 中间件
│   └── PluginLoader.php
├── model/                    # 数据模型
│   └── PluginConfigModel.php
├── scanner/                  # 插件发现
│   ├── PluginMetadata.php
│   └── PluginScanner.php
├── traits/                   # 预留 traits 目录
│
├── PluginContainerKeys.php        # 容器键常量
├── PluginManager.php              # 门面 + 注册表（扫描缓存/查询/SP 初始化）
├── PluginSupport.php              # 子类共享底座（Adapter/状态/配置注入）
├── PluginServiceProvider.php      # 主服务提供者
├── PluginStateManager.php         # 运行时状态管理
└── WiseBootServiceProvider.php    # 统一启动基类
```

---

## 3. 插件加载全链路

插件从"目录中存在一个文件夹"到"运行时提供服务"的完整加载路径涉及多个组件的协作。理解这个链路对于排查插件不生效、格式不兼容等问题至关重要。

### 3.1 请求时加载（PluginLoader 中间件）

`PluginLoader` 是 HTTP 请求进入插件系统的**唯一入口**，以 ThinkPHP 标准中间件模式挂在请求管道中，其位置在 `app/middleware.php` 中定义。

```
HTTP 请求到达
  │
  ▼
PluginLoader.handle()                        ← 首次请求时执行，后续请求跳过
  │
  ├── PluginManager::getAllPlugins()         ← 获取所有已发现的插件
  │     │
  │     └── PluginScanner::scan()            ← 扫描 plugins/ 目录
  │           │
  │           ├── 遍历 plugins/ 子目录
  │           ├── 正则校验目录名 (防路径遍历)
  │           ├── findManifestFile()          ← 查找 manifest.json（唯一清单）
  │           │
  │           ├── PluginMetadata::fromJson()   ← 解析 JSON
  │           │     └── fromArray()            ← 格式规范化
  │           │           ├── name            → 插件标识（= 目录名）
  │           │           ├── title           → 显示名
  │           │           ├── dependencies    → 插件间依赖
  │           │           ├── packages        → composer 库包依赖
  │           │           ├── service_provider → SP 类名
  │           │           └── ...其他声明字段
  │           │
  │           └── 缓存结果 (TTL 默认 3600s，按清单指纹自愈)
  │
  └── 遍历已启用插件 → initServiceProvider()
        │
        ├── new 格式: PluginServiceProviderInterface
        │     → $sp->register()
        │     → $sp->boot()
        │
        └── 旧格式: think\Service (BaseBootServiceProvider)
              → $app->register($sp)
              → $app->bootService($sp)
                    ├── copyStaticFiles (debug 模式)
                    ├── loadConfig (config/*.php)
                    ├── loadCommonFile (common.php)
                    ├── registerNamespace (PSR-4)
                    ├── registerVendor (vendor)
                    ├── registerCommands (CLI)
                    ├── loadEventFile (event.php)
                    ├── loadMiddleware (middleware.php)
                    ├── registerRoute (route/plugin.php 等)
                    ├── registerProviders (provider.php)
                    └── registerServices (service.php)
```

**关键设计决策**：

| 决策 | 说明 |
|------|------|
| **首次初始化** | `static::$booted` 标志确保 PHP-FPM 常驻进程中仅初始化一次 |
| **异常隔离** | 单个插件启动失败仅记录日志，不阻断其他插件和整个请求 |
| **延迟加载** | 插件的实际 ServiceProvider 仅在首次请求时实例化，而非应用启动时 |
| **两个兼容路径** | 支持新系统 `PluginServiceProviderInterface` 和旧系统 `think\Service` |

### 3.2 CLI 时加载（PluginServiceProvider）

在 CLI 模式下（如 `php think`），没有 HTTP 中间件管道，插件通过 `PluginServiceProvider::boot()` → `registerPluginConsoleCommands()` → `PluginManager::initServiceProvider()` 进行初始化。

```
CLI 命令执行
  │
  ▼
PluginServiceProvider::register()              ← 容器绑定
  │
  ▼
PluginServiceProvider::boot()                  ← 仅在 CLI 模式下
  │
  └── registerPluginConsoleCommands()
        │
        └── 遍历已启用插件 → initServiceProvider()
              (与 HTTP 路径相同的初始化逻辑)
```

> **注意**：CLI 模式下也注册了 15 个插件管理命令（`wise:plugin:install` 等），这些命令在 `PluginServiceProvider::boot()` 的 `runningInConsole()` 分支中注册。

### 3.3 服务提供者继承体系

插件的 ServiceProvider 是**唯一的运行时装配入口**（`manifest.service_provider` 指向它）：

```
think\Service                                [ThinkPHP 基础]
    │
    ├── WiseBootServiceProvider               [插件基类 ★ 唯一继承点]
    │     │  - 11 步 boot 链（配置/命名空间/事件/中间件/路由/DI/子服务）
    │     │  - getPluginIdent() 通过反射从命名空间推导标识
    │     │  - 进程级幂等（同一 worker 内只完整 boot 一次）
    │     │
    │     ├── Demo / ShortLink / WiseCMS / WiseDocs / WiseEditor / WorkflowDemo 的 BootServiceProvider
    │     │     (原 PluginHub 已迁为 wise-market **库包**，走 wise\support 的包装配链)
    │     │
    │     └── 插件三方可实现 PluginServiceProviderInterface（极少见：
    │           完全不需要 boot 链、只要一个纯容器注册点时）
    │
    └── PluginServiceProvider                 [框架主 SP]
          注册核心服务（Scanner / Manager / Config / Market）
```

**继承策略**：新插件一律继承 `WiseBootServiceProvider`。
（`BaseBootServiceProvider` 已随旧系统移除；如仍有存量插件继承它，需改为 `WiseBootServiceProvider`。）

---

### 4.1 PluginManager — 生命周期管理器

**文件**: `PluginManager.php`  
**命名空间**: `wise\plugin\PluginManager`

插件系统的核心编排器，所有插件生命周期操作统一由此调度。

**核心方法**:

| 方法 | 说明 |
|------|------|
| `install(string $name): PluginMetadata` | 完整安装流程（10 步），含失败回滚 |
| `uninstall(string $name, bool $removeFiles = false, bool $purge = false): array` | 完整卸载流程（11 步），含反向依赖检查；`$removeFiles=true` 时先把插件目录打包到根目录 `backup/{name}_{Ymd-His}.zip` 再删（备份失败不删）；`$purge=true` 时菜单/权限物理删除、残留表强制 DROP、清空迁移记录 |
| `enable(string $name): void` | 启用插件（7 步） |
| `disable(string $name): void` | 禁用插件（4 步，先落库再回调） |
| `upgrade(string $name, string $newVersion, string $packageUrl): PluginMetadata` | 升级插件（6 步），含自动回滚 |
| `getPlugin(string $name): PluginMetadata` | 获取单个插件元数据 |
| `getAllPlugins(): array` | 获取所有已发现插件 |
| `isInstalled(string $name): bool` | 检查是否已安装 |
| `isEnabled(string $name): bool` | 检查是否已启用 |
| `getLocalPluginList(): array` | 获取本地插件列表 |
| `getOnlinePluginList(array $query): array` | 获取远程市场插件列表 |
| `getPluginConfig(string $name): array` | 获取插件配置 |
| `setPluginConfig(string $name, array $config): void` | 设置插件配置 |
| `initServiceProvider(string $name): void` | 初始化插件服务提供者 |
| `validatePluginName(string $name): void` | 验证插件名称（防路径遍历） |

**拆分后的职责分布**（PluginManager 本身已收窄为"门面 + 注册表"）：

| 类 | 职责 | 承接的方法 |
|---|---|---|
| `PluginManager` | 扫描缓存、查询、SP 初始化、库包闸门、对外门面 | `getPlugin` / `getAllPlugins` / `isEnabled` / `resolveIdent` / `initServiceProvider` / `ensureNamespace` / `missingPackages` |
| `lifecycle/PluginLifecycle` | 安装/卸载/启停/升级 | `install` / `uninstall` / `enable` / `disable` / `upgrade` |
| `lifecycle/PluginStateInspector` | 状态自检、自愈、修复、清库 | `isInstalled` / `getPluginState` / `inspectInstallConflict` / `repairInstallState` / `purgeResidual` |
| `listing/PluginListPresenter` | 本地列表、单行、操作按钮、远程市场 | `getLocalPluginList` / `buildLocalRow` / `getPluginRow` / `buildActions` / `getOnlinePluginList` |
| `config/PluginSettings` | 配置 schema、读写、默认值播种 | `getPluginConfigSchema` / `getPluginConfig` / `setPluginConfig` / `seedDefaultConfig` |

> 说明：`PluginManager` 保留全部 public 方法签名并委托给上述 4 个类，
> 因此后台控制器、CLI 命令、wise-market 等调用方**无需改动**。

**设计特点**:
- 委托模式：生命周期 / 状态 / 列表 / 配置各自独立成类，Manager 只做编排与注册表
- 请求级内存缓存：`getCachedPlugins()` 首次扫描后缓存，写入操作后由 `clearPluginCache()` 清除（缓存的唯一归属）
- Adapter 懒加载：`MenuRegistrar`、`AssetManager` 等按需实例化（集中在 `PluginSupport`）
- 失败回滚机制：安装时迁移失败自动清理资源，升级失败自动恢复备份

---

### 4.2 PluginServiceProvider — 服务注册

**文件**: `PluginServiceProvider.php`  
**命名空间**: `wise\plugin\PluginServiceProvider`

ThinkPHP 主服务提供者，在应用启动时将核心服务绑定到容器。

**绑定的服务**（全部为单例）:

| 容器键 | 服务 | 说明 |
|--------|------|------|
| `plugin.scanner` | `PluginScanner` | 插件扫描器 |
| `plugin.dependency_checker` | `DependencyChecker` | 依赖检查器 |
| `plugin.manager` | `PluginManager` | 核心管理器 |
| `plugin.config.driver` | `DatabaseConfigDriver`/`FileConfigDriver` | 配置驱动（根据配置选择） |
| `plugin.config` | `PluginConfig` | 配置管理器 |
| `plugin.market` | `LocalMarket`/`HttpMarket` | 市场驱动（根据配置选择） |

**事件机制**: 生命周期事件（`plugin.installed` / `plugin.uninstalling` / `plugin.uninstalled` / `plugin.enabled` / `plugin.disabled`）由 `PluginManager` 通过 ThinkPHP `Event::trigger()` 触发，插件在自身 `event.php` 的 `listen` 中注册监听器。框架侧不再维护独立的事件总线。

---

### 4.3 PluginScanner — 插件发现

**文件**: `scanner/PluginScanner.php`  
**命名空间**: `wise\plugin\scanner\PluginScanner`

**核心方法**:

| 方法 | 说明 |
|------|------|
| `scan(): array` | 扫描所有插件，支持缓存（TTL 默认 3600s） |
| `getPluginMetadata(string $name): PluginMetadata` | 获取单个插件元数据 |
| `refreshCache(): void` | 清除扫描缓存 |
| `isValidPlugin(string $dir): bool` | 检查目录是否为有效插件 |
| `validateAndLoadPlugin(string $dir): ?PluginMetadata` | 验证并加载插件（一次 I/O） |

**缓存自愈（v2 起）**：缓存条目携带**清单指纹**（各 `manifest.json` 的 `mtime:size`），
读取时校验，任一 manifest 被增删改即自动失效重扫 —— 因此手改 manifest、`git` 切分支
**无需**手动刷缓存；`plugin:scan --refresh` 仍保留为强制刷新入口。

**清单文件优先级**: `manifest.json` → `manifest.json`

**安全措施**: 插件目录名正则校验 `/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/`，防止路径遍历攻击。

---

### 4.4 PluginMetadata — 元数据值对象

**文件**: `scanner/PluginMetadata.php`  
**命名空间**: `wise\plugin\scanner\PluginMetadata`

**字段列表**（核心字段）:

| 字段 | 类型 | 说明 |
|------|------|------|
| `name` | string | 插件名称/标识 |
| `ident` | string | 插件标识（manifest.json 兼容） |
| `displayName` | string | 显示名称 |
| `version` | string | 版本号 |
| `description` | string | 描述 |
| `author` | string | 作者 |
| `dependencies` | array | 依赖关系（name => version constraint） |
| `packages` | array | 库包依赖（composer 包名 => `{constraint, required}`，见 [10.13](#1013-packages-库包依赖声明)） |
| `serviceProvider` | string | 服务提供者类名 |
| `enabled` | bool | 是否已启用 |
| `icon` | array | 启动台图标声明（manifest 顶层 `icon`，归一化为 `{type,value,color}`） |
| `menus` | array | 菜单定义 |
| `permissions` | array | 权限定义 |
| `develop` | bool | 是否开发模式 |
| `seed` | string | 数据填充策略（manifest 顶层 `seed`：`required` / `demo` / `none`；由 `SeedPolicy` 消费） |
| `slots` | array | 前端插槽声明 |
| `vendor` | array | 第三方命名空间（manifest 键 `vendor`） |
| `publish` | array<string,string> | 额外静态资源发布映射（manifest 顶层 `publish`，相对路径原样保留；由 `AssetManager` 消费） |
| `capabilities` | array | 能力协商声明（manifest 顶层 `capabilities`，`requires`/`provides` 两键；由 `CapabilityResolver` 消费） |
| `configSchema` | array | 配置项 schema |

**工厂方法**:
- `fromJson(string $json): static` — 从 JSON 字符串创建
- `fromArray(array $data): static` — 从数组创建（兼容两种清单格式）

**序列化方法**:
- `toArray(): array` — 完整数组（含运行时状态）
- `toManifest(): array` — 仅清单字段（写回文件时使用）

**不可变模式**: `withEnabled(bool $enabled): static` 返回新副本，原始实例不变。

---

### 4.5 PluginStateManager — 状态管理

**文件**: `PluginStateManager.php`  
**命名空间**: `wise\plugin\PluginStateManager`

负责插件 enabled 状态的读取、写入和清除。状态通过 `PluginConfig` 持久化，不污染 `manifest.json` 源码文件。

| 方法 | 说明 |
|------|------|
| `isEnabled(string $name): bool` | 检查是否启用 |
| `setEnabled(string $name, bool $enabled): void` | 设置启用状态 |
| `clearState(string $name): void` | 清除运行时状态 |
| `getEnabledNames(array $plugins): array` | 获取所有已启用插件名称 |

**容错设计**: 当数据库不可达时（如 console 环境未配置 DB），`isEnabled()` 返回 `false`，让框架继续正常启动。

---

### 4.6 DependencyChecker — 依赖检查

**文件**: `dependency/DependencyChecker.php`  
**命名空间**: `wise\plugin\dependency\DependencyChecker`

| 方法 | 说明 |
|------|------|
| `checkDependencies(string $name): bool` | 检查依赖是否安装且版本满足 |
| `checkCircularDependency(array $names, array $visited): bool` | 使用 DFS 检测循环依赖 |
| `resolveDependents(string $name): array` | 解析反向依赖链 |
| `satisfiesVersion(string $required, string $actual): bool` | 检查版本约束是否满足 |

**版本约束支持**:
- 比较运算符：`>=`, `<=`, `>`, `<`, `!=`, `=`
- 语义化版本范围：`^1.0.0`（兼容向上到下一个主版本）、`~1.2.3`（只允许补丁版本变化）
- 裸版本号：精确匹配
- 优先使用 `composer/semver` 库（如已安装）

---

### 4.7 生命周期事件（ThinkPHP Event）

**触发方**: `lifecycle/PluginLifecycle`（`Event::trigger()`）  
**监听方**: 插件自身的 `event.php`

框架侧**没有**独立的钩子调度器。5 个生命周期事件直接走 ThinkPHP 原生 `Event`：

| 事件名 | 触发时机 | 可否阻止 |
|--------|----------|:---:|
| `plugin.installed` | 安装完成（状态已落库） | 否（仅通知） |
| `plugin.uninstalling` | 卸载开始前 | 否（异常只记日志） |
| `plugin.uninstalled` | 卸载完成 | 否 |
| `plugin.enabled` | 启用完成 | 否 |
| `plugin.disabled` | 禁用完成（状态已落库） | 否 |

**负载**：`PluginMetadata::toArray()`（以位置参数传入，监听器签名 `handle(array $payload)`）。

```php
// plugins/my-plugin/event.php
return [
    'listen' => [
        'plugin.installed' => [\plugin\myplugin\listener\OnInstalled::class],
    ],
];
```

> 历史说明：早期实现有 `hook_dispatcher/`（优先级 + 链式 payload + stopPropagation），
> 但全仓 0 个监听器，且 `stopPropagation()` 因监听器拿不到事件对象而不可达，已删除。
> 需要"多监听器按顺序改写载荷"的场景，请用事件对象 + 在监听器内自行编排。

---

### 4.8 PluginConfig — 配置管理

**文件**: `config/PluginConfig.php`  
**命名空间**: `wise\plugin\config\PluginConfig`

支持点号键格式的可插拔配置管理器。

| 方法 | 说明 |
|------|------|
| `get(string $key, mixed $default): mixed` | 获取配置值（如 `plugin_name.key`） |
| `set(string $key, mixed $value): void` | 设置配置值 |
| `delete(string $key): void` | 删除配置值 |
| `all(string $pluginName): array` | 获取插件所有配置 |
| `getDriver(): PluginConfigDriverInterface` | 获取配置驱动 |
| `setDriver(PluginConfigDriverInterface $driver): void` | 设置配置驱动 |

**配置驱动**:

| 驱动 | 说明 |
|------|------|
| `DatabaseConfigDriver` | 数据库驱动（默认），通过 `PluginConfigModel` ORM 操作 `plugin_config` 表 |
| `FileConfigDriver` | 文件驱动，`{plugin}.json` 存储，使用 `LOCK_EX` 文件锁，损坏文件自动备份 |

---

### 4.9 PluginLoader — 请求中间件

**文件**: `middleware/PluginLoader.php`  
**命名空间**: `wise\plugin\middleware\PluginLoader`

在首次 HTTP 请求时初始化所有已启用插件的 ServiceProvider。

**关键特性**:
- **首次初始化**: `static::$booted` 确保只初始化一次（PHP-FPM 常驻进程复用）
- **异常隔离**: 单个插件初始化失败不阻断其他插件和整个请求
- **错误日志**: 失败信息记录到应用日志
- **重置支持**: `resetBootState()` 供管理命令或测试用例调用

**中间件注册**（在 `app/middleware.php` 中）:

```php
return [
    \wise\plugin\middleware\PluginLoader::class,
    // ... 其他中间件
];
```

---

### 4.10 CapabilityResolver — 能力协商

**文件**: `capability/CapabilityResolver.php`  
**命名空间**: `wise\plugin\capability\CapabilityResolver`

在插件**安装/启用**时检查其能力需求是否被满足。与 `ExtPoint`（wise-support 包）配合使用，
由 `PluginLifecycle` 在依赖检查（`DependencyChecker`）之后自动调用——两者互补：
依赖检查回答"别的插件在不在"，能力协商回答"运行时能力点有没有实现"。

**协商语义**:
1. **消费方声明**: 插件在 manifest.json 顶层声明 `capabilities.requires`（能力名 = ExtPoint 扩展点名）
2. **提供方注册**: 提供方插件在自己的服务提供者里 `ExtPoint::register(能力名, 实现名, 实现)`
3. **诊断建议**: 缺失时从已扫描插件的 `capabilities.provides.services` 与已定义扩展点中给出安装建议

**检查结果**:
- 必需能力（`required` 默认 `true`）缺失 → 抛 `PluginInstallException`，**阻断**安装/启用
- 可选能力（`required: false`）缺失 → 仅记 info 日志，不阻断
- 未声明 `capabilities` 的插件 → 直接通过（绝大多数插件的路径）

**manifest 声明示例**:

```json
"capabilities": {
    "requires": {
        "editor.rich": { "required": true, "mode": "any", "preferred": "tiptap" },
        "storage.oss": false
    },
    "provides": { "services": { "editor.rich": "TipTap 编辑器" } }
}
```

> 声明 `provides` 只用于缺失诊断建议，**不会自动注册**实现——提供方必须自己 `ExtPoint::register()`。

**能力选择模式**:
- `any`：任意一个实现（默认，可配合 `preferred` 指定首选）
- `all`：所有实现
- `active`：标记为 active 的实现，或 priority 最高的

**懒加载入口**: `PluginSupport::getCapabilityResolver()`（进程内复用，自带解析结果缓存，
scanner 来自容器单例 `PluginContainerKeys::SCANNER`）。

---

## 5. Adapter 层（框架集成）

### 5.1 MenuRegistrar

将插件菜单定义写入框架菜单系统。复用旧系统 `Menu::createMenusWithPermissions()` 方法，通过 `MenuPermissionBridge` 自动同步菜单和权限。

```php
$registrar = new MenuRegistrar();
$registrar->register($metadata);          // 注册（默认保留已存在菜单的字段：运营改名/排序/停用不被冲掉）
$registrar->register($metadata, true);    // --force 语义：以 manifest 为准覆盖已存在菜单
$registrar->prune($metadata);             // 清理 manifest 已不再声明的菜单（软删可恢复；务必在 register() 之后）
$registrar->prune($metadata, true);       // 同上但只预览（dry-run，不落库）
$registrar->unregister($pluginIdent);     // 卸载：删除该插件全部菜单（软删除，权限行保留）
```

> `register()` **从不删除**：插件作者把菜单从 manifest 拿掉后，需要 `prune()` 才会清理（升级链路已默认调用）。
> 清理受三条守卫保护：插件根容器、仍被声明子树引用的父目录、以及手工建的菜单都不动；
> 软删可恢复 —— 重新声明该节点即恢复同一行（连同权限与角色授权）。
> 差集实现由 `wise\support\menu\MenuTreePruner` 提供，与库包链路 `menu:sync --prune` 共用。

### 5.2 PermissionRegistrar

注册独立权限（非菜单派生），卸载时清理 `auth_role_permission` 关联。

```php
$registrar = new PermissionRegistrar();
$registrar->register($metadata);     // 注册
$registrar->unregister($pluginIdent); // 删除
```

### 5.3 AssetManager

将 `plugins/{ident}/statics/` 复制到 `public/statics/plugins/{ident}/`；并处理 manifest
顶层 `publish` 声明的额外发布映射（由 `PluginMetadata::getPublish()` 提供，本类只做
相对路径 → 绝对路径解析与越界目标过滤）。

```php
$manager = new AssetManager();
$manager->copyAssets($name);         // 复制（statics/ + publish 声明，整目录覆盖）
$manager->syncAssets($name);         // 增量同步（只复制 size/mtime 有差异的文件，开发期用）
$manager->syncAssets($name, true);   // 只统计差异，不写盘
$manager->removeAssets($name);       // 删除（statics/ + publish 目标一并清理）
```

`copyAssets()` 与 `syncAssets()` 的分工：前者是安装/启用/升级语义（保证产物与源码一致），
后者是 `plugin:dev --assets` 语义（幂等、只补差异，复制后用 `touch()` 对齐 mtime）。

### 5.4 MigrationRunner

直接实例化迁移命令类执行数据库迁移，消除子进程开销。

```php
$runner = new MigrationRunner();
$result = $runner->migrate($name);   // 执行迁移
$result = $runner->rollback($name, 0); // 回滚迁移
$result = $runner->seed($name);      // 执行数据填充
```

> `seed()` 只负责**执行**；**要不要执行**由 {@see \wise\plugin\lifecycle\SeedPolicy} 决定
> （manifest `seed` 声明 + `--with-demo`）。业务代码直接调用 `seed()` 会绕过「demo 默认跳过」语义，
> 只应在迁移/运维脚本里显式使用。

### 5.5 RouteLoader

按请求类型加载路由文件：
- API 请求 → `route/api.php`
- 普通请求 → `route/index.php`
- 兜底 → `route/plugin.php`

### 5.6 NamespaceRegistrar

将 `plugin\{ident}\` 和第三方命名空间注册到 Composer ClassLoader。

---

## 6. 插件市场

支持两种市场驱动：

| 驱动 | 类 | 说明 |
|------|-----|------|
| 本地市场 | `LocalMarket` | 扫描本地已安装插件作为市场数据源 |
| HTTP 市场 | `HttpMarket` | 通过 Guzzle HTTP 客户端与远程 API 交互 |

**HttpMarket API 端点**:
- `GET /plugins/search?query=` — 搜索插件
- `GET /plugins/{name}` — 获取插件详情
- `GET /plugins/{name}/download?version=` — 下载插件包

**认证**: 支持 Bearer Token 认证（`Authorization: Bearer {api_key}`）

---

## 7. 标准 Composer 包接入（无桥接）

**结论**：本项目**不再提供** `composer_bridge`（把 Composer 包装进 `plugins/` 的那套）。一个包要么是**插件**（zip → `plugins/`，走 `PluginManager` 生命周期），要么是**标准 Composer 包**（装进 `vendor/`，通过 `extra.think` + 可选 ServiceProvider 接入）。两者不互相转换。

**标准 Composer 包的接入契约（要点）**：

| 声明 | 作用 |
|---|---|
| `extra.think.services` | ServiceProvider 自动发现（TP8 `service:discover`） |
| `extra.think.config` | 默认配置发布（`vendor:publish`） |
| `extra.think.publish` | 包内 `public/` → `public/statics/plugins/{ident}`（`wise:vendor:publish`） |
| `extra.think.menus` | 可选：包根 `menu.php` 声明后台菜单（MenuItemProvider 的数组化） |
| ServiceProvider 的 `registerMigrations()` | 迁移目录注册（默认 `{包根}/database/migrations`） |

- **不复制、不软链**：包永远在 `vendor/`（或 path 仓库源目录），框架只引用。
- **卸载清理**：`composer remove` → `PackageHook`（`pre/post-package-uninstall`）→ `wise:package:cleanup`，依据 `.wise/package_registry.json` 快照。
- **对外指南**：`docs/wise-composer-package-guide.md`（面向包作者，含最小样例 `docs/examples/`）。
- **内部决策记录**：`docs/wise-package-thin-spec.md`。

---

## 8. CLI 命令

### 插件管理命令

| 命令 | 说明 |
|------|------|
| `php think wise:plugin:install <name> [--with-demo] [--ignore-compat]` | 安装插件；`--with-demo` 额外写入演示数据（仅对 `seed: "demo"` 生效）；`--ignore-compat` 跳过 `compatible_with` 拦截（应急） |
| `php think wise:plugin:uninstall <name> [--purge] [--remove-files]` | 卸载插件（`--purge` 无痕清理，`--remove-files` 删除安装目录） |
| `php think wise:plugin:upgrade <name> <version> [--package-url=] [--ignore-compat]` | 升级插件（`compatible_with` 按新包 manifest 校验） |
| `php think wise:plugin:reinstall <name> [--force]` | 重装插件（默认修复状态，--force 清库重装） |
| `php think wise:plugin:enable <name>` | 启用插件 |
| `php think wise:plugin:disable <name>` | 禁用插件 |
| `php think wise:plugin:scan` | 扫描插件列表 |
| `php think wise:plugin:create <name>` | 创建插件骨架 |
| `php think wise:plugin:connector:create <name>` | 创建连接器 |

### 本地预检命令（纯静态：不落库、不联网）

| 命令 | 说明 |
|------|------|
| `php think plugin:audit --plugin=<ident>` | 审计已安装插件目录（可逗号分隔多个） |
| `php think plugin:audit --path=<dir\|zip>` | 审计任意目录或待上传 zip |
| `php think plugin:audit --all` | 审计 `plugins/` 下全部插件 |
| `php think plugin:audit --json` | 机器可读输出（stdout 只有 JSON；等价于 `--format=json`） |
| `php think plugin:audit --strict` | `warn` 也视为失败（CI 门禁） |
| `php think plugin:audit --show-rules` | 打印本地规则清单 |
| `php think plugin:audit --ruleset=<file\|url> [--ruleset-sha256=]` | 使用外部规则库（仅覆盖 `code.regex.*`，强制按 warn 处理） |

边界：只覆盖**结构 / 清单 / 低敏正则提示**；`error`（结构、清单）阻断，`warn`（代码正则）不阻断；
AST 深度扫描、后门判定与人工审核全部在服务端（`wise-market`）。退出码：`0` 无阻断问题 / `1` 命中 / `2` 参数或读取错误。

**规则库是 JSON 快照，不是 PHP 常量**（P1-6）：

- 随包文件：`extend/wise/plugin/audit/rules/local/bundled.json`（`version` / `rules` / `regex_rules`）；
- 运行时：`AuditRuleSet` 一次性读取并**校验**（id 命名空间、级别、重复、正则编译、regex 必须有元数据），
  结构错误直接抛异常 —— 规则库坏了不能输出「全绿」；
- 硬约束：`code.regex.*` 只能是 `warn`（JSON 改成 error 会被拒绝加载）；
- 构建期导出：`php scripts/export-plugin-audit-rules.php --from=<market-rules.json> --expect-version=<v>`；
  只校验（CI）：`php scripts/export-plugin-audit-rules.php --check`；
- rule id 常量仍在 `AuditRuleSet`（调用方与测试可按常量引用），但**文案与级别只认 JSON**。

### 开发期同步命令（`plugin:dev`）

**不是 watcher**：一条命令、显式触发、可预览、可重复；不监听文件、不驻留进程、不启 HTTP 服务。

| 命令 | 说明 | 写操作 |
|------|------|:--:|
| `php think plugin:dev` | 等同 `--status`：只读诊断四类漂移（静态资源 / 扫描缓存 / 迁移 / 菜单权限） | 否 |
| `php think plugin:dev --status` | 同上（显式写法） | 否 |
| `php think plugin:dev --assets` | 按 `size + mtime` 差异复制 `statics/` 与 `publish` 映射到 `public/` | 写文件 |
| `php think plugin:dev --refresh` | 清 `wise_plugin_scanner` 缓存 + 按 manifest 补建/恢复菜单与独立权限 | 写库 |
| `php think plugin:dev --migrate` | 列出待执行迁移，确认后执行（复用 `plugin:migrate:run` 链路） | 写库 |
| `php think plugin:dev --all` | 依次 `assets → refresh → migrate`，遇错即停，干净步骤自动跳过 | 同上 |
| `php think plugin:dev -p <ident,...>` | 只处理指定插件（默认全部） | — |
| `php think plugin:dev --dry-run` | 只报告将做什么，不写文件 / 不写库 | 否 |
| `php think plugin:dev --force` | 跳过确认（**非交互模式与 `--json` 必须显式使用**） | — |
| `php think plugin:dev --json` | 机器可读（stdout 只有 JSON） | — |

三条硬规则：默认只读；写操作先打印计划再确认（非交互缺 `--force` 直接退出 `2`）；
幂等（资源只复制有差异的文件、复制后 `touch` 对齐时间戳；菜单只补建/恢复、从不删除；
迁移跳过已执行项）。**不清理** opcache / 路由缓存（命令行与 FPM 是两套内存，请用 `php think clear`）。

退出码：`0` 无漂移或执行成功 / `1` 存在漂移（诊断、`--dry-run`）/ `2` 参数错误或执行失败。

### 打包发布命令（`plugin:publish`）

一条命令完成「做完 → 市场出现新版本」：本地预检（`plugin:audit` + `plugin:test` 串行）→ 打包 zip → 本地 SHA256 → 上传市场。

| 命令 | 说明 |
|------|------|
| `php think plugin:publish -p <ident>` | 自动判断：市场已有同标识插件则发新版本，否则首版投稿 |
| `php think plugin:publish -p <ident> --plugin-version=1.1.0 --changelog=@CHANGELOG.md` | 指定版本与更新说明（`--version` 是全局选项，勿用） |
| `php think plugin:publish -p <ident> --first` | 强制首版投稿（市场已存在同标识则报错） |
| `php think plugin:publish -p <ident> --update` | 强制发新版本（市场无此插件则报错） |
| `php think plugin:publish -p <ident> --plugin-id=12` | 直接指定市场插件 ID 发新版本（跳过查询） |
| `php think plugin:publish -p <ident> --dry-run` | 只预检 + 打包 + 算 sha256，不上传 |
| `php think plugin:publish -p <ident> --skip-preflight` | 跳过本地预检（不推荐，服务端仍会拦） |
| `php think plugin:publish -p <ident> --json --force` | CI：stdout 只有 JSON（`--json` 必须配 `--force`） |

框架兼容范围**只认 manifest 的 `compatible_with`**：`plugin:publish` 不再提供 `--min-framework`，
上传时会把这个声明随表单带上；服务端仍以**包内 manifest** 为准刷新记录
（表单值只在检测前占位，避免「表单写一套、包里写一套」）。

身份：会员 JWT，优先级 `--token` > env `PLUGIN_PUBLISH_TOKEN` > `config('plugin.publish_token')`；
**不接受账号密码、不落盘、不进日志与 JSON**。服务端端点：
`POST {market}/api/market/member/submit`（首版）、`POST {market}/api/market/member/version`（新版本），
`plugin_id` 由 `GET {market}/api/market/member/myPlugins` 解析。

包结构：`{ident}/manifest.json`（wrapped，本地安装器与服务端校验双向兼容）。
默认排除 `.git/.svn/.idea/.vscode/node_modules/runtime/dist/tests/.env*` 以及 `*.log` / `*.map` / `*.tmp` / `*.swp`
（`--skip` 追加；按**文件名/目录名**匹配，任意层级生效，支持 `*` `?` `[abc]` glob 且大小写不敏感 —— 路径式规则如 `runtime/cache` 不生效）。
包体上限默认 10MB（与服务端 `wise-market.max_size` 对齐，可用 `--max-size` 调整）。

退出码：`0` 成功 / `1` 预检未通过或用户取消（未上传）/ `2` 参数错误、超限、网络或服务端拒绝。
失败时 zip 一律保留，可到后台上传兜底；**不自动重试**（避免重复版本）。

### 契约检查命令（`plugin:test`：`unit` / `integration`）

模式可用 `--mode=unit|integration` 指定，也可用 `--unit` / `--integration` 简写（两者等价，简写优先；同时出现视为参数错误）。
文档、脚本与 CI 推荐统一用简写，`--mode` 保留以兼容既有脚本。

两种模式互不替代：

| 模式 | 覆盖 | 依赖 | 用途 |
|:--|:--|:--|:--|
| `unit`（默认） | 静态契约 15 项 | 无 DB、无网络 | 提交前秒级自检 |
| `integration` | 真实装 → 停 → 启 → 卸：建表 / 菜单权限落库 / 迁移回滚 / 残留检查 / 资源清理 | **独立测试库** | 上架前把关 |

#### unit（静态）

**纯静态、零依赖、不连库、不联网、不执行包内代码**。

| 命令 | 说明 |
|------|------|
| `php think plugin:test -p <ident>` | 检查单个插件（unit，默认模式） |
| `php think plugin:test --unit -p <ident>` | 同上，显式声明 unit |
| `php think plugin:test --path=<dir\|zip>` | 检查任意目录或最终产物（推荐查 zip） |
| `php think plugin:test --all` | 检查 `plugins/` 下全部插件 |
| `php think plugin:test --all --strict` | `warn` 也视为失败（CI 门禁） |
| `php think plugin:test -p <ident> --json` | 机器可读（stdout 只有 JSON） |
| `php think plugin:test --show-cases` | 打印内置契约用例清单 |
| `php think plugin:test -p <ident> --caseset=<file> [--caseset-sha256=]` | 显式使用外部用例集（仅本地文件；版本不一致会提示） |

覆盖（与 `plugin:audit` 互补）：manifest 可解析、**PHP 语法**（`token_get_all(TOKEN_PARSE)`，不执行）、
namespace 前缀、`service_provider` 文件、菜单节点结构与 path 归属、权限 ident 唯一性、
迁移文件名/类名/`up|change`、Seeder 类名、`publish` 映射、`route/*.php` 的 `Class@action` 是否指向包内控制器。
**不判定**「方法是否真存在」（父类/trait 提供时会误报），也不判定业务正确性。

用例集：`src/extend/wise/plugin/contract/contract_cases.json`（随包冻结，15 项，`bundled-2026.11.1`）。
规则是「本地模式只决定检查哪些 case，**级别以本地为准**」——外部用例集不能把 `error` 降级为 `warn`；
服务端新增的 case 在旧客户端会落入 `skipped`（提示用例版本不一致），**不判失败**。

#### integration（真实生命周期）

| 命令 | 说明 |
|------|------|
| `php think plugin:test -p <ident> --integration --test-env=test` | 切到 `.env.test` 后跑装 → 停 → 启 → 卸（推荐；父进程不连库） |
| `php think plugin:test -p <ident> --integration` | 未配置测试环境时**直接在当前真实库**执行（会打印警告） |
| `... --integration --expect-db=<库名>` | 库名断言：必须与 `SELECT DATABASE()` 一致；**真实库 + `--force` 时必填** |
| `... --integration --purge` | 卸载走无痕模式（物理删菜单/权限、清迁移记录、DROP 残留表） |
| `... --integration --skip-unit` | 跳过前置静态检查（不推荐：坏包会进库） |
| `... --integration --dry-run` | 只打印目标库/环境/步骤/目标插件，**不写库、不需 `--force`**，退出码 `0` |
| `... --integration --force` | 跳过写库确认（非交互 / `--json` 必须显式使用）；真实库下还需 `--expect-db` |

目标库来源优先级：`--test-env=<name>` > `config(plugin.test_env)`（可用 `.env` 的 `PLUGIN_TEST_ENV`
或系统变量 `PLUGIN_TEST_ENV` 设置）> 当前 `.env`。

安全护栏：

1. **测试环境优先**：配置了 `.env.{name}` 时，父进程用 `PHP_ENV_NAME` 拉起**子进程**执行
   （框架在 bootstrap 期读 env，进程内改不生效）；父进程只透传输出与退出码；
   `proc_open` 不可用时**明确报错退出 `2`**，不静默降级；
2. **`.env.test` 必须是 `.env` 的完整副本**（只写 `DB_*` 会丢 `APP_*` 等配置）；
   该类文件已被 `.gitignore` 的 `.env.*` 覆盖，不会误提交；
3. **库守卫**：`--expect-db` 提供时必须与 `SELECT DATABASE()` 一致；
   **真实库 + `--force`（非交互）时必填** —— 只靠 `--force` 拦不住 CI/脚本误连生产；
   测试环境（`.env.{name}`）模式下可省略；
4. **显式确认**：交互确认默认「否」；非交互或 `--json` 下没有 `--force` 直接退出 `2`；
5. **先静态后集成**：默认先跑 `unit`，有 `error` 就不进库。

每步断言（看数据库既成事实，不看方法返回值）：

| 步骤 | 断言 |
|:--|:--|
| 前置 | 已安装则先无痕卸载，保证从「未安装」开始 |
| 安装 | 状态键 installed/enabled；迁移记录 > 0；声明建表全部存在；`ws_menus` / `ws_auth_permissions` 有行；`public/statics/plugins/{ident}` 已复制 |
| 停用 | enabled=false 且 installed 不变 |
| 启用 | enabled=true；菜单数量幂等（不越注册越多） |
| 卸载 | 残留表为空、`fully_cleaned=true`、菜单/权限/资源清理成功、状态复位、`--purge` 下迁移记录清零 |

任一步失败会**尝试无痕卸载把测试库恢复干净**，避免下次从"装了一半"开始（清理失败只记 warn）。

注意：`--all` 按目录名顺序执行，声明了 `dependencies` 的插件可能因依赖未按序安装而失败 ——
带依赖的插件建议按依赖顺序单独执行（失败信息会直接给出依赖缺失原因）。
默认卸载是「普通卸载」（菜单/权限软删），重装会自动恢复同一行，不影响后续运行。

退出码：`0` 无不一致 / `1` 存在 error（或 `--strict` 下的 warn）/ `2` 参数、读取、用例集或库守卫错误。
输出不使用「通过」字样：本地契约检查不构成上架依据。

### 模块抽取命令（`plugin:extract`）

对应方案 §2.3「交付反哺」：把交付项目里**已经写好**的通用模块搬进插件骨架（搬运 + 命名空间改写）。
「只有表、没有代码」的场景请用 `php think wise:crud --plugin=<ident> ...`，两者是互补关系。

```bash
php think plugin:create warehouse --minimal
php think plugin:extract -p warehouse --from=app/admin/controller/Warehouse.php
php think plugin:extract -p warehouse --from=app/admin/view/warehouse --strip=admin
php think plugin:extract -p warehouse --from=app/common/model/Warehouse.php --to=model/Warehouse.php
php think plugin:extract -p warehouse --from=... --dry-run --json
```

| 选项 | 说明 |
|------|------|
| `--plugin/-p` | 目标插件（须先用 `plugin:create` 建好骨架与 `manifest.json`） |
| `--from` | 源文件或目录，逗号分隔（相对应用根或绝对路径） |
| `--to` | **单文件**抽取时的目标路径（相对插件根），用于源码不在约定目录下的场景 |
| `--strip` | 目标路径需剥离的前导段（如 `public/statics/admin/js/x.js --strip=admin` → `statics/js/x.js`） |
| `--map-namespace` | 额外命名空间映射 `old=new`，逗号分隔（覆盖默认映射） |
| `--force/-f` | 覆盖插件内已存在的同名文件 |
| `--dry-run` / `--json` | 只预览 / 机器可读 |

路径段映射（取相对应用根路径中第一个命中段，其后原样保留）：
`controller` / `model` / `validate` / `service` / `logic` / `middleware` / `event` / `job` / `command` /
`view` / `statics` → 同名目录；`migrations` → `database/migrations`；`seeds` → `database/seeds`；`route` → `route`。

命名空间默认映射：`app\{admin,api,common}\controller` → `plugin\{ident}\controller`，
`app\common\{model,validate,service,logic,middleware,event,job,command}` → 对应插件目录。

三条硬规则：

1. **不猜边界**：必须显式 `--from`；无法推断目标目录就报错（不塞 `misc/`）；
2. **规划失败不写盘**：源不存在 / 目标冲突 / 无法映射任何一条 error 都整体中止，避免"搬了一半"；
3. **`--dry-run` 走同一条写盘代码路径**（只是不落盘），预览与真实结果不会漂移。

能力边界（有意为之）：

- **不引入 `php-parser`**：只改 `namespace` 与顶部 `use` 的前缀，用 `token_get_all()` 足够；
  字符串、注释、类内 trait `use`、闭包 `use`、函数体里的完全限定引用**一个字都不动**；
  函数体内的 `\app\...` 引用会作为 warning 列出，由作者人工确认；
- 未命中映射的 `app\...` 命名空间进入 warning（不静默保留成"看起来对、实际错"）；
- 不生成业务代码、不改 `manifest.json` 的菜单/权限（用 `plugin:menu-derive` 与后台维护）。

退出码：`0` 成功（或预览无问题）/ `1` 规划失败（未写盘）/ `2` 参数或写盘失败。

### 数据库迁移命令

| 命令 | 说明 |
|------|------|
| `php think plugin:migrate:run <plugin>` | 运行插件迁移 |
| `php think plugin:migrate:create <plugin> <name>` | 创建迁移文件 |
| `php think plugin:migrate:rollback <plugin> [--step=]` | 回滚迁移 |
| `php think plugin:migrate:status <plugin>` | 查看迁移状态 |
| `php think plugin:migrate:breakpoint <plugin>` | 设置断点 |

### 数据填充命令

| 命令 | 说明 |
|------|------|
| `php think plugin:seed:run <plugin>` | 运行 Seeder |
| `php think plugin:seed:create <plugin> <name>` | 创建 Seeder |

---

## 9. 异常体系

```
PluginException (基类，错误码 100)
├── PluginNotFoundException      (100) 插件未找到
├── PluginConfigException         (300) 配置异常
├── PluginInstallException        (400) 安装异常
├── PluginDependencyException     (200) 依赖异常
│   └── CircularDependencyException (201) 循环依赖
```

---

## 10. 插件清单格式（manifest.json v2）

插件统一使用 `manifest.json` 作为清单文件，采用 `snake_case` 命名规范，位于 `plugins/{插件目录}/manifest.json`。

> **JSON Schema**：`src/extend/wise/plugin/manifest.schema.json`。
> 在 manifest.json 首行加 `"$schema": "../../extend/wise/plugin/manifest.schema.json"`
> 即可获得编辑器字段补全与实时校验（未知字段允许，不会报错）。

本系统内置的 **demo（示例插件）** 即是一份完整、规范的清单实例，本文以其 `manifest.json` 为蓝本逐字段讲解每个属性的作用、结构与在框架中的消费点。

> 📌 **范例载体变更（2026-10）**：原范例 `pluginhub`（插件中心）已迁移为 **`wiseadmin/wise-market` 库包**
> —— 它属于「插件市场」这一**框架能力**（后台 `/admin/market/*`、公开接口 `/api/market/*`），不再是插件，
> 详见 `docs/pluginhub-迁包立项与任务清单.md`。本文范例改由仓库内长期保留的 `plugins/demo` 承接，
> §10 全部示例值均取自它**真实存在**的路由与能力。

> **提示**：字段按"作用"分为 4 组：
> ① 标识信息、② 运行时装配、③ 框架资源声明（菜单/权限/配置/插槽/第三方命名空间）。
> 曾经存在的 `hooks / editor_provider / editor_types / storage_drivers / payment_drivers / captcha_drivers / plugin_class / providers`
> 均属**已废弃的旧声明**（见 [10.10](#1010-已废弃字段说明)），新清单**不应再写入**。

---

### 10.1 实例：demo 的 manifest.json

> 文件位置：`src/plugins/demo/manifest.json`（下列字段即该文件的真实内容，可直接对照阅读）。

```jsonc
{
    // ① 标识信息
    "name": "demo",                          // 插件唯一标识（英文，目录名必须一致）
    "title": "示例插件",                       // 显示名称（中文）
    "version": "1.0.0",                      // 语义化版本号
    "description": "WiseAdmin 插件开发示例：后台页面 / JSON API / 会员前台页 / 会员中心入口 / 多语言 / 静态资源",
    "author": "wiseAdmin",

    // ② 依赖管理
    "dependencies": {},

    // ② 库包依赖：声明依赖某个 composer 库包（可选安装的 wise-* 包）
    //    简写字符串 = 硬依赖；{"constraint": "*", "required": false} = 软依赖
    //    对象形态声明**软依赖**：wise-attachment 缺失时只诊断提示，不阻断插件加载
    "packages": {
        "wiseadmin/wise-attachment": {
            "constraint": "*",
            "required": false
        }
    },

    // ② 运行时装配
    "service_provider": "plugin\\demo\\BootServiceProvider",   // 启动服务提供者类（可省略，见 10.4）

    // ③ 框架资源声明
    "develop":      true,    // 开发模式（demo 常驻开发态，便于示范）
    "menus":        [ /* 见 10.5 */ ],
    "permissions":  [ /* 见 10.6 */ ],
    "config":       { /* demo 声明了 enable 开关，见 10.7 */ },
    "slots":        {},

    // ④ 第三方命名空间（vendor）
    "vendor": {}
}
```

---

### 10.2 顶层字段速览

| 字段 | 类型 | 必填 | 分组 | 作用与消费点 |
|------|------|:---:|:---:|--------------|
| `name` | string | ✅ | 标识 | 插件**唯一英文标识**，须与目录名完全一致（`PluginManager::validatePluginName` 用正则校验防路径遍历）；也是命名空间推导、权限前缀、插槽键、配置域的前缀。 |
| `title` | string | — | 标识 | 插件**显示名**（通常中文）。不填则回退为 `name`。读入 `PluginMetadata::displayName`。 |
| `version` | string | ✅ | 标识 | **语义化版本号**（如 `1.1.0`），用于依赖约束比对与升级判断（`version_compare` / `DependencyChecker`）。 |
| `description` | string | — | 标识 | 插件简介，展示于插件列表/市场。 |
| `author` | string | — | 标识 | 插件作者。 |
| `dependencies` | object | — | 依赖 | 插件对其它插件的依赖：`{"插件名": "^1.0.0"}`。安装/启用前由 `DependencyChecker` 校验是否存在且版本满足。 |
| `packages` | object | — | 依赖 | 插件对 **composer 库包**的依赖：`{"wiseadmin/wise-attachment": "*"}`（简写 = 硬依赖）或 `{"wiseadmin/wise-attachment": {"constraint":"*","required":false}}`（软依赖）。**硬依赖缺失 → 插件不加载、也不能启用**；软依赖缺失只诊断提示。见 [10.13](#1013-packages-库包依赖声明)。 |
| `service_provider` | string | 建议 | 装配 | 插件启动服务提供者类全名。不填则自动推导为 `plugin\{name}\BootServiceProvider`。由 `PluginManager::initServiceProvider()` 实例化并走注册/boot 流程。见 [10.4](#104-service_provider运行时装配)。 |
| `develop` | bool | — | 装配 | 是否**开发模式**。`PluginManager::getLocalPluginList()` 会将其透出到插件列表（`develop` 项），用于区分正式/开发中插件。 |
| `seed` | string | — | 初始化 | `database/seeds/` 的**数据填充策略**（{@see \wise\plugin\lifecycle\SeedPolicy}）：`required`（缺省）= 基线数据，安装时执行、失败回滚并阻断；`demo` = 演示数据，默认**跳过**，`plugin:install --with-demo` 才执行、失败只告警；`none` = 永不执行。升级时只执行 `required`，演示数据不重复灌。 |
| `compatible_with` | string \| string[] | — | 兼容 | **框架兼容范围声明**（作者声明，不是实测结论）。语法同 Composer：`^1.0` / `~1.2` / `1.2.*` / `>=1.0 <2.0`；数组 = 任一满足。安装/升级前按当前框架版本判定，不满足则拒绝（`--ignore-compat` 可强制）。未声明 = 未知：**不拦截、不标红**（存量插件都走这条路）。判定实现见 `wise\plugin\compat\FrameworkCompatibility`，与市场侧 `VersionConstraint` 由 `scripts/verify-compat-parity.php` 锁定一致。 |
| `icon` | string/object | — | 资源 | 插件**启动台图标**（纯展示，不落库、不改菜单）。启动台渲染时直接从 manifest 读取；与 `menus[].icon`（导航图标）完全解耦。支持 bi 字体 / 图片 / emoji，可带背景色。缺省不显示任何图标内容（前端用默认 bi bi-plugin 占位）。见 [10.12](#1012-启动台图标-icon轻量声明)。 |
| `publish` | object | — | 资源 | **额外静态资源发布映射**：`{"源相对插件根": "目标相对应用根"}`（目标以 `public/` 开头）。由 `PluginMetadata::getPublish()` 解析、安装/启用时 `AssetManager::copyAssets()` 与 `statics/` 一并复制，卸载时一并清理。用于 `statics/` 之外、但需要公开访问的资源（如会员主题 `theme/{theme}/assets` → `public/statics/member/themes/{theme}`）。 |
| `capabilities` | object | — | 装配 | **能力协商声明**（安装/启用时由 `CapabilityResolver` 消费）。`requires`：`{能力名(ExtPoint 扩展点名): {required, mode, preferred, description} 或简写}`，必需能力缺失**阻断**安装/启用；`provides.services`：本插件可提供的能力（仅用于缺失诊断建议，提供方需自行 `ExtPoint::register()`）。见 [4.10](#410-capabilityresolver--能力协商)。 |
| `menus` | array | — | 资源 | 后台**菜单树定义**，安装时由 `MenuRegistrar` 写入菜单系统。见 [10.5](#105-menus-菜单定义)。 |
| `permissions` | array | — | 资源 | 独立**权限定义**，安装时由 `PermissionRegistrar` 注册。见 [10.6](#106-permissions-权限定义)。 |
| `config` | array/object | — | 资源 | **配置项 schema**，安装时播种默认值并生成后台配置表单。支持三种写法，见 [10.7](#107-config-配置项-schema)。 |
| `config_tabs` | object | — | 资源 | **配置页标签页**的中文标题与展示顺序（值可简写为字符串）。未声明时回退框架内置表，见 [10.7](#107-config-配置项-schema)。 |
| `config_groups` | object | — | 资源 | **配置页分区**（标签页内部）的中文标题与展示顺序。未声明时回退框架内置表，见 [10.7](#107-config-配置项-schema)。 |
| `slots` | object | — | 资源 | **前端插槽注入**，安装/卸载时经 `SlotManager::registerFromManifest()/unregisterFromManifest()` 注册内容。见 [10.8](#108-slots-前端插槽)。 |
| `vendor` | object/array | — | 其它 | **第三方 PSR-4 命名空间**（旧键名 `third_namespaces`）。由 `NamespaceRegistrar::registerVendor()` / `WiseBootServiceProvider` 注册到 Composer ClassLoader（如插件引入第三方库命名空间）。 |

> ⚠️ **已废弃、勿再写入的顶层键**：`hooks`、`plugin_class`、`providers`、`editor_provider`、`editor_types`、`storage_drivers`、`payment_drivers`、`captcha_drivers`。
> 原因与替代方案见 [10.10](#1010-已废弃字段说明)。

> **注**：`PluginMetadata::fromArray()`（`src/extend/wise/plugin/scanner/PluginMetadata.php`）是解析清单的**唯一入口**，其读取字段见上表与源码实现；`toManifest()` 负责把清单字段写回文件（不含运行时状态 `enabled`）。

---

### 10.3 解析兼容性说明

- 清单**实际只会被扫描器解析成 `PluginMetadata` 值对象**，运行时状态（如 `enabled`）由 `PluginStateManager` 独立持久化，**不回写污染 manifest.json 源文件**。
- 顶层键优先级说明：`title` 与 `config` 是 manifest 的原始键；内部序列化/缓存往返使用 `display_name` 与 `config_schema`。二者等价，`fromArray()` 均已兼容。
- 第三方命名空间键名：规范键为 `vendor`（v2）；`fromArray()` 与 `WiseBootServiceProvider` 仍**兼容旧键 `third_namespaces`**，存量清单无需立即迁移，但新插件一律使用 `vendor`。

---

### 10.4 `service_provider`（运行时装配）

demo 的对应类：

```php
// src/plugins/demo/BootServiceProvider.php
class BootServiceProvider extends \wise\plugin\WiseBootServiceProvider { ... }
```

| 字段 | demo 的值 | 实际消费逻辑 |
|------|---------------|-------------|
| `service_provider` | `plugin\demo\BootServiceProvider` | 由 `PluginManager::initServiceProvider()` 解析并实例化（类名还须满足命名空间合法性校验）。若缺省则自动拼 `plugin\{name}\BootServiceProvider`。 |

> ⚠️ **`Plugin.php` 约定已移除（2026-11）**：插件不再有"主类"，清单里也不再有 `plugin_class`。
> 原先由 `Plugin::install()/uninstall()/enable()/disable()` 承担的职责已分流：
>
> | 需求 | 正确做法 |
> |---|---|
> | 建表 / 初始数据 | `database/migrations` + `database/seeds`（安装/升级时自动执行） |
> | 安装/卸载后的外部副作用（注册 webhook、清理第三方资源） | 插件 `event.php` 监听 `plugin.installed` / `plugin.uninstalled` |
> | 启用/禁用时的联动 | 监听 `plugin.enabled` / `plugin.disabled`（**不可阻止**） |
>
> 影响：生命周期钩子不再能"阻断"安装或启用 —— 需要门禁时请用依赖声明
> （`dependencies` / `packages`）或迁移中的前置校验，把问题暴露在更早的阶段。
>
> 已删除的代码：`wise\plugin\Plugin`、`plugin\BasePlugin`、`PluginManager::getPluginInstance()`、
> `PluginManager::callHook()`、`plugin:create --with-plugin` 与 `Plugin.stub`。

> ⚠️ **已废弃的 `providers`**：清单中的 `providers` 键同样**不被框架读取**。DI 绑定的唯一事实来源是插件目录下物理文件 **`provider.php`**（由 `WiseBootServiceProvider::registerProviders()` 加载），故新清单不写 `providers`。

---

### 10.5 `menus`（菜单定义）

demo 同样只声明**一个顶级菜单**，其 `type` 为 `dir`（目录），`children` 下挂具体的 `menu`（页面）与 `button`（按钮/操作权限）菜单项 —— 这也是「单顶级插件挂到『插件扩展』」的典型形态：

```jsonc
"menus": [
    {
        "name": "示例插件",          // 顶级目录名
        "icon": "bi bi-box",        // 目录图标
        "path": "",                 // 目录无入口地址
        "component": "",            // 前端组件名（预留）
        "sort": 90,                 // 同级排序
        "type": "dir",              // 节点类型：dir | menu | button
        "status": 1,                // 是否启用（1 显示 / 0 隐藏）
        "children": [               // 子菜单
            { "name": "插件首页", "icon": "bi bi-house", "path": "/plugin/demo/index",
              "type": "menu", "status": 1,
              "children": [         // 页面级按钮权限（path 即权限派生依据）
                  { "name": "接口连通性",   "path": "/api/demo/test/ping",   "type": "button" },
                  { "name": "参数回显",     "path": "/api/demo/test/echo",   "type": "button" },
                  { "name": "统一错误响应", "path": "/api/demo/test/errors", "type": "button" }
              ] }
        ]
    }
]
```

**菜单节点字段**：

| 字段 | 说明 |
|------|------|
| `name` | 节点显示名（目录名/菜单名/按钮名）。 |
| `icon` | Bootstrap Icons 图标类名（如 `bi bi-plugin`），按钮级可留空。 |
| `path` | 访问路径，即**路由地址**（如 `/plugin/demo/index`）。目录/按钮也可填，作为权限依据；`dir` 顶级通常留空。 |
| `component` | 前端组件标识（预留，通常留空）。 |
| `sort` | 同级排序（数值小在前）。 |
| `type` | 节点类型：`dir`=目录（容器）、`menu`=可访问页面、`button`=按钮/操作（仅用于生成权限，不出现在导航）。见下表。 |
| `status` | 是否启用：`1` 显示 / `0` 隐藏。 |
| `children` | 子菜单数组（`dir`/`menu` 可含 children；`button` 不再嵌套）。 |

**`type` 三种取值的语义**：

| type | 语义 | 渲染/用途 |
|------|------|----------|
| `dir` | 目录（`Menu::TYPE_CATALOG`） | 作为**分组容器**，自身不可点，子项在 `children` 中。demo 顶级即 `dir`。 |
| `menu` | 页面菜单（`Menu::TYPE_MENU`） | 对应一个可访问页面，`path` 指向页面路由（如 `/plugin/demo/index`）。 |
| `button` | 按钮/操作权限（`Menu::TYPE_BUTTON`） | **不进入导航树**（菜单查询会过滤 type≠button），仅用于**自动派生页面内的操作权限**，`path` 作为权限标识依据。demo 把"接口连通性/参数回显"这类 API 动作声明为按钮。 |

> **展示分流规则（`MenuRegistrar::register()`）**：
> - 顶级菜单数量 = 1（demo 即此）→ 入口挂到「插件扩展」；若该顶级为 `dir`，其 `children` 直接平铺到入口下；若为 `menu`，自动包一层「插件名」目录。此类插件不可被钉选。
> - 顶级菜单数量 ≥ 2 → 入口挂到「启动台」，由用户主动钉选后进入「常用插件区」。
> - 无 `menus` 则不创建任何根节点。

**权限联动**：菜单项（含 `menu`/`button`）注册时经 `MenuPermissionBridge`（`app/admin/service/MenuPermissionBridge.php`）按 `path` **自动派生一条权限记录**（ident、method 由策略生成），无需在 `permissions` 中重复声明。

---

### 10.6 `permissions`（权限定义）

`permissions` 用于声明**与菜单无直接对应关系、无法由菜单 `path` 自动派生**的"独立操作权限"（权限标识写法为 `插件.控制器.动作`）。demo 只声明了 1 条（它没有对应的菜单按钮）：

```jsonc
"permissions": [
    { "name": "请求信息调试", "ident": "demo.test.method", "method": "POST",
      "type": "custom", "description": "POST /api/demo/test/method —— 无对应菜单按钮的独立权限" }
]
```

**权限项字段**：

| 字段 | 说明 |
|------|------|
| `name` | 权限名称（后台角色授权页展示）。 |
| `ident` | 权限**唯一标识**，遵循 `插件.控制器.动作` 约定（如 `demo.test.method`），供角色绑定与代码内权限判断使用。 |
| `method` | 该操作对应的 **HTTP 方法**（`GET`/`POST`），用于路由权限判断。 |
| `type` | 权限类型：`custom` = 独立权限；值为 `menu` 时会被跳过（菜单派生权限不在此声明）。 |
| `description` | 权限说明，展示在授权界面辅助理解。 |

> **消费逻辑（`PermissionRegistrar::register()`）**：过滤掉 `type === 'menu'` 的项后，对每条独立权限调用 `Perm::registerPermission()` 写入权限系统（含 `plugin` 归属标识）；卸载时 `unregister()` 会清理 `auth_role_permission` 关联后删除权限。

---

### 10.7 `config`（配置项 schema）

demo 声明了 1 个配置项（`enable` 开关，用的是下表写法 A）。对需要配置的插件，`config` 决定：安装时播种默认值、后台自动渲染配置表单；不需要配置则写 `{}` 或 `[]` 均可。

支持三种历史写法（`PluginConfigSchema::normalize()` 统一归一化）：

```jsonc
// A. 对象 map + value
"config": { "key": { "type": "string", "title": "标签", "value": "默认值" } }
// B. 数组 list + name（最常用）
"config": [ { "name": "enable", "type": "radio", "title": "启用插件", "value": "1",
              "options": { "1": "启用", "0": "禁用" } } ]
// C. 对象 map + default
"config": { "cache_time": { "type": "number", "default": 3600 } }
```

**单字段可用键**：

| 键 | 类型 | 说明 |
|------|------|------|
| `name`/`key` | string | 配置键名（落库键，如 `demo.enable`）。 |
| `type` | string | 表单类型：`string/text/textarea/number/select/radio/checkbox/switch/boolean/color/date/editor/image/array`（`switch` 与 `boolean` 等价，均渲染为开关并强转布尔）。 |
| `title` | string | 配置项显示标题。 |
| `value` / `default` | mixed | 默认值（`value` 优先）。 |
| `options` | object | 选项映射 `{值: 文案}`，`select/radio/checkbox` 需要。 |
| `tips` / `description` | string | 配置项说明文字。 |
| `required` | bool | 是否必填。 |
| `tab` | string | **标签页**名（外层，`Nav tabs` 切换）。缺省为空 = 不参与标签页；同一声明下多个 `group` 可落在同一个 `tab` 里。 |
| `group` | string | 分区名（标签页内部的分区标题）。 |
| `sort` | int | 同 `tab` + 同 `group` 内的排序。 |

**展示层级**：`tab`（标签页，外层） → `group`（分区，内层） → 字段。
只有一个标签页时不渲染 tab 栏，直接按 `group` 平铺分区（存量插件只写 `group` 时的形态不变）。

**标签页/分区的中文标题与顺序**在 manifest 顶层声明（值支持 `{title, sort}`，也可简写为字符串）：

```jsonc
"config_tabs": {
    "basic":   { "title": "站点设置", "sort": 10 },
    "api":     "接口输出"                                  // 简写 = 只给标题
},
"config_groups": {
    "basic":   { "title": "基础设置", "sort": 10 },
    "feed":    { "title": "订阅输出", "sort": 70 }
}
```

- 未声明的 `tab` / `group` 回退框架内置通用表（`PluginConfigForm::TAB_TITLES` / `GROUP_TITLES`：basic / content / advanced / …），
  仍未登记则直接显示名称原文；`default` 分区与「未声明 tab」固定排最后。
- 读取链路：`PluginMetadata::getConfigTabs()/getConfigGroups()` → `PluginManager::getPluginConfigMeta()` → `Plugin::configIndex()` → `PluginConfigForm::render()` 的 `tabs`/`groups` 选项。
- ⚠️ 新增的 manifest 字段必须同时进入 `PluginMetadata::toArray()`，否则扫描缓存（`plugin.cache_enabled` 默认 true）往返会静默丢弃。

> **消费点**：`PluginConfigSchema` 负责归一化/取默认值/校验；安装时 `PluginManager::seedDefaultConfig()` 把默认值**幂等播种**到 `plugin_config` 表；后台据此渲染配置页。声明了非空 `config` 的已安装插件，列表里会出现"配置"操作（`PluginManager::buildActions()`）。

---

### 10.8 `slots`（前端插槽）

`slots` 用于把插件提供的 UI 组件注入框架**预定义页面插槽**。demo 该字段为 `{}`（不注入 —— 它的会员中心入口是走 `ExtPoints::MEMBER_FEATURE` / `MEMBER_CENTER_ENTRY` **代码注册**的，属另一条链路；见 `plugins/demo/member/`）。启用插槽插件的结构：

```jsonc
"slots": [
    {
        "slot":       "dashboard.widgets.top",  // 框架已定义的插槽名（见 SlotManager::boot()）
        "component":  "demo/MyWidget.vue",     // 注入的前端组件
        "title":      "挂件标题",
        "icon":       "bi bi-widget",
        "props":      { "pageSize": 10 },       // 传给组件的 props
        "permission": "demo.widget.view",       // 可选，控制可见性
        "priority":   100
    }
]
```

> **消费点**：安装时 `SlotManager::registerFromManifest($name, $slots)` 逐条注册到 `ExtPoint::SLOT_CONTENT`；插槽未定义则跳过不阻断；卸载时 `unregisterFromManifest()` 清理。空对象 `{}` 表示插件不注入任何前端内容。

---

### 10.9 事件监听（跨插件 / 框架事件）

**manifest 的 `hooks` 顶层键已废弃**：旧版清单用它声明"本插件参与的钩子名清单"，但框架始终只把它当作元数据透出、**从不据此注册任何监听器**（配套的 `PluginMetadata::getHooks()` / `Plugin::getHooks()` 已一并移除）。该字段已在全部内置插件清单中清理，新插件**不要写入**。

插件若要监听其他插件或框架事件，统一在**插件自身的 `event.php`** 中实现（ThinkPHP 原生事件机制，由 `WiseBootServiceProvider::loadEventFile()` 在插件启动时加载）：

```php
<?php
// plugins/my-plugin/event.php
return [
    'bind' => [
        // 可选：自定义事件类绑定
        // 'OrderPaid' => \plugin\myplugin\event\OrderPaid::class,
    ],
    'listen' => [
        // 监听插件生命周期事件（框架 Event::trigger 触发）
        'plugin.installed' => [
            \plugin\myplugin\listener\OnPluginInstalled::class,
        ],
        // 监听其它插件主动触发的事件（业务代码 Event::trigger 发出）
        'shortlink.visit' => [
            \plugin\myplugin\listener\OnShortlinkVisit::class,
        ],
    ],
    'subscribe' => [
        // 可选：事件订阅者类
    ],
];
```

**要点**：

- **监听方**只需在自己的 `event.php` 里把"事件名 → 监听器类"注册进 `listen`，无需在 manifest 声明任何内容。
- **事件名怎么约定**：生命周期事件名为 `plugin.installed / plugin.uninstalling / plugin.uninstalled / plugin.enabled / plugin.disabled`；其它插件业务事件以对应插件文档为准（如 shortlink 的 `shortlink.visit`，由业务代码 `Event::trigger(...)` 触发）。
- 生命周期事件由 `PluginLifecycle` 用 `Event::trigger()` 触发，**监听器无法阻止**安装/卸载/启停 —— 事件是通知，不是事务钩子。
- `PluginManager::callHook()` 与 `hook_dispatcher/` 已删除（2026-11）：前者是"直接反射调用插件主类方法"的旧机制，后者全仓 0 监听器。

---

### 10.10 已废弃字段说明

以下清单顶层键属于**旧规则**，已在全部内置插件中移除，新插件**不应再写入**：

| 废弃字段 | 曾经用途 | 现状与替代 |
|----------|---------|-----------|
| `hooks` | 旧规则声明插件"参与/提供的钩子名"清单 | 已废弃。框架从不据此注册监听器；监听其它插件或框架事件统一在插件自身 `event.php` 实现，见 [10.9](#109-事件监听跨插件--框架事件)。 |
| `editor_provider` / `editor_types` | 旧规则声明"插件分类/用途"为编辑器提供者及类型 | 已废弃。编辑器发现现走独立机制：`plugins` 表 `editor_provider` 列 + 约定类 `plugin\{ident}\EditorProvider`（实现 `EditorPluginInterface`），见 `EditorManager`。 |
| `storage_drivers` / `payment_drivers` / `captcha_drivers` | 旧规则声明插件提供的存储/支付/验证码驱动 | 已废弃。能力扩展改为 **ExtPoint 显式注册**（`ExtPoint::register(...)`），不再经 manifest 声明。 |
| `plugin_class` | 冗余声明插件主类 | 已废弃。`Plugin.php` 约定整体移除（2026-11），框架不再推导、不再调用任何插件主类。 |
| `member` | 声明会员功能开关初始建议 / 必需功能 / 场景预设 | 已迁出内核。会员接入由 **member 插件**的 `event.php` 监听 `plugin.installed` / `plugin.uninstalled`，直接读取插件 manifest 的 `member` 段；内核不再解析该字段。 |
| `providers` | 冗余镜像 DI 绑定 | 已废弃。真实 DI 绑定来源是**物理文件 `provider.php`**（`WiseBootServiceProvider::registerProviders()`），与 manifest 无关。 |

对应代码变更：`PluginMetadata`（`fromArray`/`toArray`/`toManifest`）已不再解析、存储、输出上述任何键；基类 `Plugin::getHooks()` 亦已移除。

---

### 10.11 小结：demo 清单字段的消费矩阵

| 字段 | demo 取值 | 是否被框架消费 | 消费组件 |
|------|---------------|:---:|----------|
| `name` | `demo` | ✅ | Scanner / PluginManager（名称校验、命名空间推导） |
| `title` | 示例插件 | ✅ | PluginMetadata→显示名 |
| `version` / `description` / `author` | `1.0.0` / … / wiseAdmin | ✅ | 元数据展示、依赖比对、市场 |
| `dependencies` | `{}` | ✅ | DependencyChecker |
| `service_provider` | `plugin\demo\BootServiceProvider` | ✅ | PluginManager::initServiceProvider() |
| `develop` | `true` | ✅ | 插件列表透出 |
| `icon` | `{"type":"bi","value":"bi bi-box","color":"#0d6efd"}` | ✅ | 启动台渲染 `Index::resolveLaunchIcon()`（读取展示，不落库） |
| `menus` | dir + menu + button | ✅ | MenuRegistrar / MenuPermissionBridge |
| `permissions` | 1 条 custom | ✅ | PermissionRegistrar / Perm |
| `config` | 1 项（`enable` 开关） | ✅ | PluginConfigSchema / seedDefaultConfig |
| `slots` | `{}` | ✅ | SlotManager |
| `vendor` | `{}` | ✅ | NamespaceRegistrar::registerVendor() |

---

### 10.12 启动台图标 `icon`（轻量声明）

**字段作用**：声明插件在**启动台九宫格**展示的品牌图标。**纯 manifest 声明、仅渲染读取，不落库、不改 `menus` 表**，与菜单导航图标（`menus[].icon`）完全解耦——菜单图标继续由 `menus[].icon`（bi 类名）表达，二者互不影响。

**两种写法（推荐对象形态）**：

```jsonc
// ① 对象形态（推荐）：显式 type + value + 可选 color
"icon": {
    "type":  "image",          // bi | image | emoji
    "value": "/statics/plugins/smartstock/logo.svg",
    "color": "#0d6efd"         // 可选：圆形容器底色/主题色
}

// ② 字符串形态（省略 type，渲染时自动推导）
"icon": "bi bi-box-seam"               // → bi 字体图标
"icon": "/statics/plugins/xxx/logo.png" // → image
"icon": "https://cdn.example.com/a.svg" // → image（svg 资源当图片渲染）
"icon": "📦"                            // → emoji
```

**type 载荷与渲染**：

| type | value 载荷 | 渲染 |
|------|-----------|------|
| `bi` | Bootstrap Icons 类名，如 `bi bi-box-seam` | 字体图标（可配合 `color` 作底色） |
| `image` | 图片 URL（本地 `/statics/plugins/{ident}/…`、相对 `/…` 或 `https://…`） | 圆形底 `background-size:cover` 铺满 |
| `emoji` | 单个 Emoji | 文本渲染进圆形容器 |

**解析与消费**（均为轻量读取，无落库）：
- `PluginMetadata::fromArray()` 解析 `icon`（字符串/对象）→ `getIcon(): {type,value,color}`，内置 `guessIconType()` 推导字符串形态。
- 后台首页 `Index::buildLauncherItem()` 经 `resolveLaunchIcon(ident)` 从插件元数据读取该字段，随 `window.__launcherPlugins__` 输出 `iconType / iconValue / iconColor`。
- 未声明 `icon` → `type=bi, value=bi bi-plugin`，行为与旧版一致。

**菜单布局页**：不涉及启动台图标配置（仅管理归属/标题/导航 bi 图标），改动图标只需修改 manifest 后重装/启用插件。

**安全**：图标值仅在启动台容器内以内联样式/文本渲染，不执行脚本；远程图片仅作 CSS 背景。

---

**ServiceProvider 自动推导规则**：
- 如未声明 `service_provider`，框架自动尝试 `plugin\{name}\BootServiceProvider`。
- 插件**没有主类**：`Plugin.php` 约定已于 2026-11 整体移除（见 [10.4](#104-service_provider运行时装配)），
  建表/初始数据走 `database/migrations` + `database/seeds`，生命周期副作用走插件自身
  `event.php` 监听 `plugin.*` 事件。

---

### 10.13 `packages`（库包依赖声明）

声明插件依赖某个 **composer 库包**（`src/packages/wiseadmin/wise-*`，可选安装），与 `dependencies`（插件 ↔ 插件）语义区分：库包卸载后不会有"同名插件"可查，必须按 composer 安装集合判定。

```json
"packages": {
    "wiseadmin/wise-attachment": "*",                                    // 简写 = 硬依赖
    "wiseadmin/wise-oauth": { "constraint": "^1.0", "required": false }  // 软依赖
}
```

| 档位 | 适用场景 | 缺失时行为 |
|---|---|---|
| **硬依赖**（默认） | 加载路径本身就依赖该包：继承其基类、中间件数组里写 `\wise\member\middleware\JwtAuth::class` | **不加载**：`PluginLoader::bootPlugins()` 与 `PluginManager::initServiceProvider()` 跳过并记 warning；**不能启用**：`DependencyChecker::checkDependencies()` 抛 `PluginDependencyException` |
| **软依赖**（`required: false`） | 只在一个模型关系（`belongsTo(Member::class)`）或带 `class_exists` 守卫的服务里引用 | 正常加载；`plugin:scan` 与插件列表的 `missing_packages` 给出诊断 |

**判定依据**：`vendor/composer/installed.json`（包是否仍在安装集合里）—— 与 `wise:package:cleanup` 的卸载判定同源，避免"源码目录还在（path 仓库软链已删）就以为还装着"。该文件不可读时一律视为可用（fail-open，宁漏拦不误拦）。

**归一化与缓存**：`PluginMetadata::normalizePackages()` 兼容 `"pkg": "*"`、`"pkg": {constraint, required}`、`["pkg"]` 三种写法，统一为 `{包名: {constraint, required}}`，该结构同时是 `toArray()` 的输出形态 —— `packages` 必须同时出现在 `fromArray()` / `toArray()` / `toManifest()` 三处，否则**扫描缓存往返（TTL 默认 3600s）后会丢键**（与 `config_schema` / `config_tabs` / `publish` 同一个坑）。

**诊断**：`php think plugin:scan` 逐插件输出依赖满足情况（扫描缓存已按清单指纹自愈，改完 manifest 无需 `--refresh`；该参数仍可强制刷新）。

## 11. 配置说明

插件配置分两层，**应用层覆盖包内默认值**（`array_replace_recursive`）：

**① 包内默认值** —— `extend/wise/plugin/config/plugin.php`（框架自带，一般不改）：

```php
return [
    'plugin_dir'    => root_path('plugins'), // 插件目录
    'config_driver' => 'database',           // 配置存储：database | file
    'config_dir'    => root_path('plugin_config'), // file 驱动的存储目录
    'cache_enabled' => true,                 // 是否启用扫描缓存
    'cache_ttl'     => 3600,                 // 扫描缓存 TTL（秒）
    'market_driver' => 'local',              // 市场驱动：local | http
    'market_config' => ['api_url' => '', 'api_key' => ''],
];
```

**② 应用层覆盖** —— `src/config/plugin.php`（当前实际内容）：

```php
return [
    'auto_update'     => true,                              // 自动更新插件列表
    'service_url'     => 'https://wise.uxiangtk.com/',      // 插件服务器地址
    'license_key'     => env('PLUGIN_LICENSE_KEY', ''),     // 付费插件许可 Key
    'special_plugins' => ['storage' => 11, 'payment' => 12],// 特殊插件分类 ID
    'admin_auth'      => [                                  // 插件后台鉴权
        'enabled'             => true,                      // 总开关（灰度回滚用）
        'except_routes'       => [],                        // 路由级豁免（支持 *）
        'fail_closed_plugins' => ['wisecms'],               // 未登记权限节点即 403
    ],
];
```

---

## 12. 插件生命周期流程

### 12.1 安装流程

```
验证名称 → 依赖检查 → 复制静态资源 → 执行数据库迁移 → 执行数据填充
    → 注册菜单+权限（事务） → 注册前端插槽 → 播种配置默认值
    → 初始化服务提供者 → 持久化安装+启用状态 → 触发 plugin.installed → 清除缓存
```

**失败回滚**:
- 迁移失败 → 清理静态资源
- 填充失败 → 回滚迁移 + 清理静态资源
- 注册失败 → 回滚迁移 + 清理静态资源

### 12.2 卸载流程

```
验证名称 → 反向依赖检查 → 触发 plugin.uninstalling
    → 回滚数据库迁移 → 删除菜单+权限 → 删除插槽内容 → 清除状态+配置
    → 删除静态资源 → 删除插件目录（可选）→ 触发 plugin.uninstalled → 清除缓存
```

### 12.3 启用流程

```
验证名称 → 已安装校验 → 依赖检查 → 确保静态资源完整 → 注册菜单+权限（幂等）
    → 初始化服务提供者 → 持久化启用状态 → 触发 plugin.enabled → 清除缓存
```

### 12.4 禁用流程

```
验证名称 → 先持久化禁用状态 → 触发 plugin.disabled → 清除缓存
```

### 12.5 升级流程

```
备份旧版本 → 下载/解压新版本 → 复制静态资源 → 执行数据库迁移
    → 执行数据填充 → 清理备份 → 刷新缓存
```

**失败回滚**: 恢复备份目录 + 重新复制静态资源。

---

## 13. 安全设计

| 措施 | 实现位置 | 说明 |
|------|----------|------|
| 插件名校验 | `PluginManager::validatePluginName()` | 正则 `/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/` 防路径遍历 |
| 标识校验 | `PluginManager::checkPluginIdent()` | 长度 3~32，排除保留词 |
| 命名空间校验 | `PluginManager::initServiceProvider()` | 服务提供者必须在 `plugin\{name}\` 下 |
| 文件锁 | `FileConfigDriver` | `LOCK_EX` 保证并发安全 |
| 损坏文件备份 | `FileConfigDriver` | 损坏 JSON 自动备份到 `.bak` 文件 |
| 异常隔离 | `PluginLoader` | 单插件异常不阻断其他插件和请求 |
| 后台 CSRF 防护 | `plugin\BaseController::$middleware` | 框架统一提供，插件后台写操作自动校验令牌（见 §13.1） |

### 13.1 插件后台 CSRF 防护（框架统一提供，插件零代码）

插件后台与 admin 应用等价，CSRF 由框架统一提供，**插件无需自己实现**。三件套：

| 环节 | 实现位置 | 插件需要做什么 |
|------|----------|----------------|
| 校验 | `plugin\BaseController::$middleware = [VerifyCsrfToken::class]` | 无需任何代码（后台控制器继承 `plugin\BaseController` 即生效） |
| 令牌来源 | `extend/plugin/view/base.html` 的 `{:csrf_meta()}` | 后台视图继承 `__PLUGIN_VIEW__/base` 即自动带上 |
| 自动携带 | `wise-framework.js` 的 `$(document).ajaxSend` 注入 `X-CSRF-TOKEN` | AJAX 提交写请求无需额外代码 |

> 唯一需要手写的一行：使用**原生 `<form method="post">`** 提交时，在表单内加 `{:csrf_field()}`
> （浏览器原生表单无法自动携带请求头，这是固有限制；AJAX 提交不受影响）。

设计要点：

1. **为什么放在 `plugin\BaseController` 而不是 `app/plugin/middleware.php`**：`plugin` 应用同时承载
   插件后台（`PluginDispatch` → `/plugin/*`）与「默认前端插件」（`IndexDispatch` → 前台任意 URL，
   见 `src/route/miss.php`）。挂在应用级会让匿名访客访问前台任意页面都被写入 `csrf_token` Cookie，
   破坏全页缓存/CDN。放在基类则天然只覆盖后台（`plugin\FrontController`、`plugin\ApiController`
   不在该继承链上，前台与 JWT API 均不受影响）。
2. **子类覆盖须知**：PHP 属性覆盖是**替换**而非合并。插件若需要追加自定义中间件，必须把
   `VerifyCsrfToken` 一并写回，例如
   `plugins/wisedocs/controller/Base.php` 的 `[DocsAuth::class, VerifyCsrfToken::class]`；
   否则该插件后台会静默失去 CSRF 校验。

---

## 14. 性能优化

| 优化措施 | 说明 |
|----------|------|
| 扫描缓存 | `PluginScanner` 缓存扫描结果，TTL 默认 3600s |
| 请求级内存缓存 | `PluginManager::$cachedPlugins` 首次扫描后缓存 |
| Adapter 懒加载 | `MenuRegistrar`、`AssetManager` 等按需实例化（集中在 `PluginSupport`） |
| 单次 I/O | `PluginScanner::validateAndLoadPlugin()` 合并验证和解析 |
| 首次初始化 | `PluginLoader` 仅首次 HTTP 请求时初始化插件 |
| 链式处理缓存 | `CapabilityResolver` 缓存已解析的能力结果 |
| HTTP 客户端复用 | `HttpMarket` 复用 Guzzle 客户端实例 |
| 迁移直接执行 | `MigrationRunner` 直接实例化命令类，消除子进程开销 |

---

## 15. 事件系统

### 内置生命周期事件

| 事件名 | 触发时机 | ThinkPHP Event 别名 |
|--------|----------|---------------------|
| `plugin.installed` | 插件安装完成后 | `pluginInstalled` |
| `plugin.uninstalling` | 插件卸载前 | `pluginUninstalling` |
| `plugin.uninstalled` | 插件卸载完成后 | `pluginUninstalled` |
| `plugin.enabled` | 插件启用完成后 | `pluginEnabled` |
| `plugin.disabled` | 插件禁用完成后 | `pluginDisabled` |

### 监听插件事件

只有一套事件系统：ThinkPHP `Event`。插件在自身 `event.php` 声明监听器（推荐），
或在任意已加载的服务里 `Event::listen()`（临时调试用）：

```php
use think\facade\Event;

Event::listen('plugin.installed', function (array $payload) {
    // $payload 为 PluginMetadata::toArray() 数组
    \think\facade\Log::info('插件已安装: ' . $payload['name']);
});
```

> 负载以**位置参数**传入（`Event::trigger('plugin.installed', [$payload])`），
> 因此监听器签名必须是 `handle(array $payload)` / `function (array $payload)`。

---

## 16. 使用示例

### 通过代码管理插件

```php
use wise\plugin\PluginContainerKeys;

/** @var \wise\plugin\PluginManager $manager */
$manager = app()->get(PluginContainerKeys::MANAGER);

// 安装插件
$metadata = $manager->install('my-plugin');

// 获取所有插件
$plugins = $manager->getAllPlugins();

// 检查插件状态
if ($manager->isEnabled('my-plugin')) {
    // 插件已启用
}

// 禁用插件
$manager->disable('my-plugin');

// 卸载插件
$manager->uninstall('my-plugin');               // 默认：只卸载，保留插件目录
$manager->uninstall('my-plugin', true);         // 第二参 true = 打包备份到 backup/ 后删除插件目录
$manager->uninstall('my-plugin', true, true);   // 第三参 purge = 无痕清理（不可恢复）

// 获取插件配置
$config = $manager->getPluginConfig('my-plugin');

// 设置插件配置
$manager->setPluginConfig('my-plugin', ['key' => 'value']);
```

### 通过 CLI 管理插件

```bash
# 安装插件
php think wise:plugin:install my-plugin

# 启用/禁用插件
php think wise:plugin:enable my-plugin
php think wise:plugin:disable my-plugin

# 扫描所有插件
php think wise:plugin:scan

# 卸载插件
php think wise:plugin:uninstall my-plugin --remove-files

# 运行插件数据库迁移
php think plugin:migrate:run my-plugin
```

### 创建新插件骨架

```bash
php think wise:plugin:create my-new-plugin
```

生成的插件目录结构：

```
plugins/my-new-plugin/
├── manifest.json            # 插件清单
├── ServiceProvider.php    # 服务提供者
├── controller/
│   └── Index.php
├── model/
├── view/
├── statics/
├── route/
│   └── api.php
├── database/
│   └── migrations/
└── config/
    └── console.php
```

### 插件服务提供者示例

```php
<?php

namespace plugin\my-plugin;

use think\App;
use wise\plugin\contract\PluginServiceProviderInterface;

class ServiceProvider implements PluginServiceProviderInterface
{
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function register(): void
    {
        // 注册服务到容器
    }

    public function boot(): void
    {
        // 启动服务（加载路由、配置等）
    }
}
```

---

## 附录

### 依赖关系

- **PHP**: >= 8.0
- **框架**: ThinkPHP 8.x
- **可选**: `composer/semver`（更精确的版本约束解析）
- **可选**: `guzzlehttp/guzzle`（HTTP 市场驱动）

### 数据库表

插件系统需要 `plugin_config` 表（通过迁移自动创建）：

```sql
CREATE TABLE `plugin_config` (
    `id`          int(11) NOT NULL AUTO_INCREMENT,
    `plugin_name` varchar(100) NOT NULL COMMENT '插件名称',
    `key`         varchar(200) NOT NULL COMMENT '配置键',
    `value`       text COMMENT '配置值（JSON 编码）',
    `create_time` datetime NOT NULL COMMENT '创建时间',
    `update_time` datetime NOT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_plugin_config_unique` (`plugin_name`, `key`),
    KEY `idx_plugin_config_plugin` (`plugin_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='插件配置存储';
```

> 上表为迁移 DSL 的等价 DDL 语义，实际以
> `extend/wise/plugin/database/migrations/20251001000026_create_plugin_config_table.php` 为准。
>
> **时间列**：统一为框架风格 `create_time` / `update_time`，类型 `datetime`
> （不使用 `timestamp`，避免时区隐式转换），由 `PluginConfigModel` 的自动时间戳写入，
> 不依赖数据库 `CURRENT_TIMESTAMP` 默认值。

### 容器键常量

通过 `PluginContainerKeys` 类统一管理：

```php
PluginContainerKeys::SCANNER            // 'plugin.scanner'
PluginContainerKeys::CONFIG             // 'plugin.config'
PluginContainerKeys::CONFIG             // 'plugin.config'
PluginContainerKeys::CONFIG_DRIVER      // 'plugin.config.driver'
PluginContainerKeys::DEPENDENCY_CHECKER // 'plugin.dependency_checker'
PluginContainerKeys::MANAGER            // 'plugin.manager'
PluginContainerKeys::MARKET             // 'plugin.market'
```
