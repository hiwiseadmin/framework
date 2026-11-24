# WiseAdmin

> **把现有数据库里的表，一条命令变成后台增删改查。**
> ThinkPHP 8 开源后台框架 · 不改表结构 · 不生成整页代码 · Apache-2.0 免费商用

| | |
| --- | --- |
| 当前版本 | 1.0.1 |
| 运行环境 | PHP >= 8.1 · MySQL 5.7+ / 8.0 · Composer 2.x |
| 基础框架 | ThinkPHP 8（Apache-2.0） |
| 主项目许可 | Apache License 2.0（见 `LICENSE.txt`） |
| 代码仓库 | https://github.com/hiwiseadmin/framework |
| Gitee 镜像 | https://gitee.com/wiseadmin/wiseadmin |
| 项目主页 | https://www.wiseadmin.top |

WiseAdmin 是一套**以"开放 + 插件化"为核心设计理念**的后台框架：
框架本身开源免费，任何个人与组织均可免费用于商业项目；围绕框架建设了
`wise-*` 系列可复用 Composer 组件（组件生态）与插件商城（官方应用 + 第三方免费/
商业插件），让开发者用更少的时间交付**能长期维护**的后台业务系统。

## 60 秒看到结果：现有表 → 后台模块

装好后（见下方「快速开始」），对你库里**已有的任意表**先做一次预览，不写任何文件：

```bash
php think wise:crud ws_article --module=admin --dry-run
```

> `--module` **没有默认值**，框架级生成必须显式指定目标模块（如 `admin`）；插件级改用 `--plugin={ident}` 时可省略。

它只读表结构，逐字段告诉你它准备怎么渲染，以及每条判定是从哪条规则推出来的：

```text
表 ws_article  →  类 Article  →  标题 文章
（输出节选，真实列：[字段 / DB类型 / 判定 / 层 / 命中规则 / 选项 / 备选]）

字段        DB类型        判定  层  命中规则
id          int           跳过  P1  [推断] 精确=主键
title       varchar(120)  text  P1  [推断] 精确=title
created_at  datetime      跳过  P1  [推断] 系统字段白名单（created_at）

共 9 字段：声明 0 / 启发式推断 7 / 兜底 0 / 跳过 2
提示：标注 [推断] 的字段由命名/注释启发式得出，建议核对；标注 [声明] 的为显式覆盖，可直接采信。
```

> 上表为**示意**（列名与规则串以你库里那张表的实际输出为准）。
> 关键点不是输出长什么样，而是：**它把「猜的」和「你声明的」分开了**，猜的部分可以逐条核对。

确认判定没问题再落盘：

```bash
php think wise:crud ws_article --module=admin --title=文章管理
```

```text
  + app/admin/controller/Article.php
  + app/admin/model/Article.php
  + public/statics/admin/article.js
  + database/migrations/20261117153000_menu_article.php
完成：新增 4，跳过 0

下一步：
  1) php think migrate:run
  2) 后台「权限 → 更新权限列表」
```

> 菜单迁移的文件名是 `{14 位时间戳}_menu_{去前缀表名}.php`（迁移类 `MenuArticle`）；
> 重跑会按文件名正则复用同一份，不会堆出多个同名迁移。

判定不符合预期时**显式覆盖**，不用改表结构、不用读源码：

```bash
php think wise:crud ws_article \
  --module=admin \
  --field=status:radio:0=草稿,1=已发布 \
  --relation=author_id:ws_admins:id:username \
  --dry-run
```

> 关联声明会**校验目标表与列**是否存在（上例依赖 `ws_admins.username`），写错表名直接报错，不会静默生成 TODO。

加 `--save-map` 可把声明合并进 `config/crud_map.php`，下次重新生成时自动复用；
默认不覆盖你的改动：手写的声明文件（无 `@wise:generated` 标记）只打印可粘贴片段，
产物要加 `--force` 才会被覆盖。

### 与「一键生成整页代码」的区别

|  | 生成整页代码（常见做法） | WiseAdmin |
|:--|:--|:--|
| 产物 | 控制器 + 模型 + 校验器 + 两个 HTML 模板 + JS | 控制器 + 模型 + 菜单迁移 + 列表 JS，**页面由运行时渲染** |
| 表结构要求 | 字段名、类型要符合生成器预期 | 分层判定 + 显式声明覆盖，**异构存量库也能用** |
| 想改页面 | 改生成的 HTML，下次重新生成即被覆盖 | 改一处共享模板或表单元数据，所有页面同步 |
| 判定依据 | 不可见 | `--dry-run` 输出「命中层 + 命中规则 + 备选」 |

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
- **数据治理与运维界面**：列表页服务端 CSV 导出（当前筛选 / 勾选 / 跨页，
  超阈值自动转异步任务 + 下载中心）、全局回收站（跨模型还原 / 彻底删除 / 清空，
  扩展点注册、包与插件各自接入）、字段级变更记录与回滚（敏感字段整字段不落库、
  超长字段截断标记）、定时任务可视化管理（新增 / 启停 / 异步立即执行 /
  执行日志 / 调度错误面板）。
- **现代后台前端**：Bootstrap 5 + AdminLTE 4 + Bootstrap Table，提供
  自研 `ws-*` 组件库，并逐步演进到 ESM 模块化（`wise-bootstrap.js` +
  动态 import），支持深度二次定制。
- **丰富的通用能力**：附件/图片、配置中心、计划任务、通知、队列、
  工作流、Webhook、OAuth、编辑器、AI Agent 等，见下方组件生态。
- **插件商城**：官方应用与第三方插件统一托管，支持免费与商业插件、
  在线安装与版本更新。

## 组件生态（wise-*）

框架维护了一批可独立分发、可复用的 Composer 组件，覆盖基础设施与业务
能力两个层面，例如：`wise-support`（基础设施层）、`wise-auth`（RBAC）、
`wise-api`（API 安全）、`wise-jwt`、`wise-oauth`、`wise-workflow`、
`wise-webhook`、`wise-agent`、`wise-operation-log`、`wise-config`、
`wise-notification`、`wise-attachment`（内含 `wise\image` 图片引擎命名空间）、
`wise-crontab` 等 20+ 个组件。

> `wise-*` 组件均为可独立安装的 Composer 包，许可统一为 Apache-2.0
> （个别私有组件除外，以各包自身声明为准）。

## 环境要求

- PHP >= 8.1（建议 8.1+，参考根目录 `composer.json` 的 `require`）
- 数据库：MySQL 5.7+ / 8.0（其它 ThinkORM 支持的数据库亦可）
- 扩展：`openssl`、`pdo`、`mbstring` 等（按 `composer.json` / `extend` 提示补齐）
- Composer 2.x；Redis 可选（队列、缓存、分布式锁场景需要）

## 快速开始

```bash
git clone https://github.com/hiwiseadmin/framework.git wiseadmin
cd wiseadmin/src
composer install
cp .example.env .env          # 填数据库连接
php think run                 # http://localhost:8000
```

> **注意**：到这一步还没看到登录页是正常的 —— 数据库尚未初始化。
> 下面两条路径**任选其一**，别跳过。

初始化（等价的两条路径）：

- **Web 向导**：浏览器打开 `http://localhost:8000`，按 6 步填写数据库与管理员账号
- **CLI**：
  ```bash
  php think install --db_host=127.0.0.1 --db_name=wiseadmin \
    --db_user=root --db_pwd=你的密码 --admin_pwd=你的后台密码
  ```

装完即可回到上方「60 秒看到结果」，生成第一个业务模块。

从已有部署升级 / 手工初始化时，按顺序执行：

```bash
php think migrate:run                    # 执行数据库迁移
php think service:discover               # 发现服务提供者
php think vendor:publish                 # 发布包配置
php think wise:vendor:publish            # 发布包内 public/ 静态资源到应用 public/
php think wise:package:install --all     # 安装接入型 Composer 包（迁移+seeds+菜单+资源+登记）
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
