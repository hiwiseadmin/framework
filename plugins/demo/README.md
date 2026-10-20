# demo

插件描述

| 项 | 值 |
|:---|:---|
| 插件标识 | `demo` |
| 版本 | 1.0.0 |
| 作者 | Unknown |
| 创建时间 | 2026-09-22 10:30:47 |
| 骨架档位 | 必需 + 契约壳 + 生命周期 + 示例 |

## 快速开始

```bash
# 启用插件（框架自动完成：迁移 / 菜单 / 权限 / 静态资源）
php think wise:plugin:enable demo
```

启用后访问默认页面：`/plugin/demo/index`

## 文件契约

`plugin:create` 生成的每个文件都对应 boot 链上的一个读取点。下表说明**加载步骤**与**删掉它会怎样**。

| # | 文件 | 加载步骤 | 缺失后果 |
|:--:|:---|:---|:---|
| 1 | `manifest.json` | 扫描期 | 插件无法被扫描发现 |
| 2 | `BootServiceProvider.php` | manifest 的 service_provider | 插件不启动 |
| 3 | `config/app.php` | Step 2 | config('demo.xxx') 取不到默认值 |
| 4 | `route/plugin.php` | Step 9 | 后台无路由 |
| 5 | `controller/Index.php` | Step 9（路由指向） | 默认路由指向不存在的控制器 |
| 6 | `view/index/index.html` | 控制器渲染 | 默认页面渲染失败 |
| 7 | `README.md` | — | — |
| 8 | `common.php` | Step 3 | 公共函数不可用 |
| 9 | `config/console.php` | Step 6 | 自定义 CLI 命令不注册 |
| 10 | `event.php` | Step 7 | 事件监听不生效 |
| 11 | `middleware.php` | Step 8 | 中间件不注册 |
| 12 | `provider.php` | Step 10 | DI 绑定不生效 |
| 13 | `service.php` | Step 11 | 子服务提供者不注册 |
| 14 | `Plugin.php` | 安装 / 启用钩子 | install / uninstall / enable / disable 钩子不执行 |

> boot 链共 11 步，实现见 `extend/wise/plugin/WiseBootServiceProvider.php`。
> 表外的文件（controller / model / service / validate / view / statics）由 PSR-4 自动加载（`plugin\demo\` → 本目录）与静态资源复制处理，无读取点约束。
> 若以 `--minimal` 生成，则只含「必需」档文件，上表其余项需自行创建。

## 目录结构

```
plugins/demo/
├── BootServiceProvider.php      # 必需
├── Plugin.php                   # 生命周期
├── README.md                    # 必需
├── common.php                   # 契约壳
├── config/app.php               # 必需
├── config/console.php           # 契约壳
├── controller/Index.php         # 必需
├── database/migrations/         # 框架读取
├── database/seeds/              # 框架读取
├── event.php                    # 契约壳
├── manifest.json                # 必需
├── middleware.php               # 契约壳
├── model/Example.php            # 示例
├── provider.php                 # 契约壳
├── route/plugin.php             # 必需
├── service.php                  # 契约壳
├── service/ExampleService.php   # 示例
├── statics/css/                 # 静态资源
├── statics/images/              # 静态资源
├── statics/js/                  # 静态资源
├── validate/Example.php         # 示例
└── view/index/index.html        # 必需
```

## 开发指南

### 后台路由

在 `route/plugin.php` 中注册。**不要写 `Route::group('plugin/...')`** —— 框架在 Step 9 已自动加上 `plugin/demo` 前缀。

```php
Route::get('demo', 'plugin\\demo\\controller\\Demo@index');   // → /plugin/demo/demo
```

### 数据表迁移与填充

这两个目录是框架硬编码读取路径，**不可改名**：

- 迁移：`database/migrations/`（`MigrationRunner::migrate()`）
- 填充：`database/seeds/`（注意不是 `seeders`）

```bash
php think plugin:migrate:create demo CreateExampleTable
php think plugin:migrate:run demo

php think plugin:seed:create demo ExampleSeeder
php think plugin:seed:run demo
```

### 配置

主配置**必须命名为 `config/app.php`**，其内容会被平铺到插件配置域根：

```php
config('demo.info.name');      // 平铺写法（推荐）
config('demo.app.info.name');  // 子键写法（等价）
```

其他配置文件（如 `config/console.php`）只产生子键：`config('demo.console.commands')`。

### 静态资源

`statics/` 在 debug 模式下自动同步到 `public/statics/plugins/demo/`（Step 1）。
引用方式：`/statics/plugins/demo/css/app.css`

### 公共函数

`common.php` 中的函数命名以 `demo_` 为前缀，并需用 `function_exists()` 包裹（防止 Swoole 常驻下重复定义）。

### 扩展点速查

| 想做什么 | 改哪个文件 |
|:---|:---|
| 加后台路由 | `route/plugin.php` |
| 加事件监听 | `event.php` |
| 加全局中间件 | `middleware.php`（**插件根目录**） |
| 注册 CLI 命令 | `config/console.php` |
| 绑定 DI 实现 | `provider.php` |
| 注册子服务提供者 | `service.php` |
| 定义公共函数 | `common.php` |
| 定义默认配置 | `config/app.php` |
| 安装 / 卸载钩子 | `Plugin.php`（需 `--with-plugin` 生成） |

## 许可证

MIT License
