# WiseAdmin

> 基于 ThinkPHP 8 的多应用插件化后台快速开发框架
> 开源免费 · 可免费商用 · 支持插件扩展与官方插件商城

| | |
| --- | --- |
| 当前版本 | 1.0.1 |
| 运行环境 | PHP >= 8.1 · MySQL 5.7+ / 8.0 · Composer 2.x |
| 基础框架 | ThinkPHP 8（Apache-2.0） |
| 主项目许可 | Apache License 2.0（见 `LICENSE.txt`） |
| 项目主页 | https://www.wiseadmin.top |

WiseAdmin 是一套**以"开放 + 插件化"为核心设计理念**的后台管理系统工程：
框架本身开源免费，任何个人与组织均可免费用于商业项目；围绕框架建设了
`wise-*` 系列可复用组件（组件生态）与插件商城（官方应用 + 第三方免费/
商业插件），帮助开发者用更少的时间交付可维护的后台业务系统。

## 核心特性

- **多应用 + 插件化**：基于 ThinkPHP 多应用模式组织后台 / API / 前台等
  应用；插件系统支持 manifest 声明、ServiceProvider 引导与完整的生命周期
  （扫描 → 安装 → 启用 → 运行 → 禁用 → 卸载），业务以 `plugin\{ident}\`
  命名空间隔离，易扩展易卸载。
- **完整 RBAC 权限**：管理员 / 角色 / 权限点体系 + 路由级白名单鉴权，
  覆盖菜单、按钮与接口三级粒度。
- **多层安全体系**：登录安全（设备指纹、失败锁定）、操作日志、审计日志、
  API 签名 / 限流 / 追踪、三级安全水位、XSS 过滤与 CSRF 防护等开箱即用。
- **统一的分层工程架构**：公共 Traits（JsonResponse / CrudAction /
  RequestQueryBuild…）、BaseController / BaseModel 继承体系，让后台
  CRUD 页面与 API 的写法高度一致、可复制。
- **现代后台前端**：Bootstrap 5 + AdminLTE 4 + Bootstrap Table，提供
  自研 `ws-*` 组件库，并逐步演进到 ESM 模块化（`wise-bootstrap.js` +
  动态 import），支持深度二次定制。
- **丰富的通用能力**：附件/图片、配置中心、计划任务、通知、队列、
  工作流、Webhook、OAuth、二维码、编辑器、AI Agent 等，见下方组件生态。
- **插件商城**：官方应用与第三方插件统一托管，支持免费与商业插件、
  在线安装与版本更新。

## 组件生态（wise-*）

框架维护了一批可独立分发、可复用的 Composer 组件，覆盖基础设施与业务
能力两个层面，例如：`wise-support`（基础设施层）、`wise-auth`（RBAC）、
`wise-api`（API 安全）、`wise-jwt`、`wise-oauth`、`wise-workflow`、
`wise-webhook`、`wise-agent`、`wise-operation-log`、`wise-config`、
`wise-notification`、`wise-attachment`、`wise-image`、`wise-qrcode`、
`wise-login-security`、`wise-crontab` 等 30+ 个组件。

> `wise-*` 组件均为可独立安装的 Composer 包，许可统一为 Apache-2.0
> （个别私有组件除外，以各包自身声明为准）。

## 环境要求

- PHP >= 8.1（建议 8.1+，参考根目录 `composer.json` 的 `require`）
- 数据库：MySQL 5.7+ / 8.0（其它 ThinkORM 支持的数据库亦可）
- 扩展：`openssl`、`pdo`、`mbstring` 等（按 `composer.json` / `extend` 提示补齐）
- Composer 2.x；Redis 可选（队列、缓存、分布式锁场景需要）

## 快速开始

```bash
composer install
cp .example.env .env   # 按需修改数据库等配置
php think run          # 本地服务：http://localhost:8000
```

初始化与数据库：

```bash
php think migrate:run                    # 执行数据库迁移
php think service:discover               # 发现服务提供者
php think vendor:publish                 # 发布包配置
php think wise:vendor:publish            # 发布包内 public/ 静态资源到应用 public/
php think plugin:bridge                  # 插件桥接（刷新插件与组件的服务注册）
```

> 提示：仓库 `plugins/` 内置了若干演示插件（`demo`、`wisechat`、`wiseeditor`
> 等）可作插件开发样板；`app/` 下多应用（`admin` 后台、`api`、`member`、
> `install` 等）随安装向导初始化。

## 目录结构

```
src/
├── app/                  # 多应用目录（admin / api / member / install / common…）
│   ├── common/           # 公共基类与 Traits（BaseController、BaseModel）
│   └── plugin/           # 插件公共逻辑
├── config/               # 全局配置
├── database/             # 迁移与数据初始化
├── extend/               # 自定义扩展（PSR-0）
├── plugins/              # 已安装插件（含演示插件）
├── public/               # Web 入口与静态资源
├── route/                # 路由定义
├── runtime/              # 运行时缓存与日志
├── composer.json         # 主项目依赖与自动加载
├── LICENSE.txt           # Apache-2.0 许可全文
└── think                 # 命令行入口
```

## 参与贡献

欢迎通过 Issue 报告缺陷与建议、通过 Pull Request 提交代码。参与前请阅读：

- `CONTRIBUTING.md` —— 贡献流程与约定；
- `CLA/` —— 个人 / 企业《贡献者许可协议》（提交首个 PR 时按其中简化方式签署）；
- 代码风格请遵循仓库既有分层与命名约定。

## 许可与品牌

- **主项目**：以 **Apache License 2.0** 发布，全文见 `LICENSE.txt`；
  版权、第三方与商标声明见 `NOTICE`。
- **wise-* 组件**：与主项目一致，均采用 **Apache License 2.0** 发布
  （见各组件自身 `composer.json` 与 `LICENSE`）。
- **官方应用与商业插件**：随插件商城以**商业授权**方式提供，不随本开源仓库
  分发。
- **商标**：WiseAdmin 名称与标识的使用须遵循 `TRADEMARK.md` 品牌政策；
  开源许可不构成商标授权。

## 联系我们

- 项目主页 / 插件商城：https://www.wiseadmin.top
- 邮箱：wiseadmin@126.com
- 安全漏洞请邮件上报，勿公开提交。
