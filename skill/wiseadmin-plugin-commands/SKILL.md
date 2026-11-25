---
name: wiseadmin-plugin-commands
description: |
  WiseAdmin 插件相关命令全表（第三方开发者视角）。涵盖脚手架、安装/升级/卸载/重装、迁移与种子、
  菜单派生与重注册、契约自检（test/audit）、开发期同步器（dev）、打包发布（publish）、模块抽取（extract）、
  CRUD 生成（wise:crud），以及退出码、--json 约定与常见失败处理。
  触发词：plugin:create、plugin:install、plugin:test、plugin:audit、plugin:dev、plugin:publish、plugin:extract、
  plugin:migrate、plugin:seed、plugin:menu-derive、wise:crud、插件命令、退出码、--json。
source: src/extend/wise/plugin/command + src/app/common/controller/traits
updated: 2026-11-18
verify_note: 2026-11-18 逐条以 `php think <command> --help` 核对参数；与 src/AGENTS.md 第 11 节 DoD 对齐。
---

# 插件命令全表

> 入口与硬约束见 [`../../AGENTS.md`](../../AGENTS.md)。本文件只回答“敲哪条、参数怎么传、失败怎么读”。
> 所有命令在**项目根目录**执行（即 `think` 所在目录）。

## 0. 先看三条路径

| 目标 | 命令序列 |
|:--|:--|
| **首插件跑起来** | `plugin:create` → 写 manifest/控制器 → `plugin:install` → 打开 `/plugin/{ident}` |
| **每日开发** | 改代码 → `plugin:dev --assets` → `plugin:dev --refresh` →（有迁移）`plugin:dev --migrate` |
| **上架交付** | `plugin:audit` → `plugin:test --unit` → `plugin:test --integration` → `plugin:publish` |

## 1. 脚手架与生成

| 命令 | 作用 | 关键参数 |
|:--|:--|:--|
| `php think plugin:create <ident>` | 生成插件骨架（默认 13 个文件） | `--minimal`（7 个）/ `--with-example`（16 个）/ `--author=` / `--plugin-version=` / `-f` 覆盖 |
| `php think wise:crud <table> -p <ident>` | 从数据表生成插件内 CRUD（控制器/模型/视图/JS 垫片） | `-t` 标题、`--field=` 枚举覆盖（可多次）、`--relation=` 关联、`--merge-manifest` 合并菜单、`--dry-run`、`-f` |
| `php think plugin:extract -p <ident> --from=<路径>` | 把已有模块抽进插件骨架并改写命名空间 | `--to=` 单文件目标、`--strip=` 路径剥离、`--map-namespace=old=new`、`--dry-run`、`--json` |
| `php think plugin:connector:create` | 从模板创建外部系统连接器 | 见 `--help` |

> `wise:crud` 只是**生成声明**，页面由运行时渲染；生成后立刻 `plugin:dev --refresh` 让菜单/缓存生效。

## 2. 生命周期（写库，注意环境）

| 命令 | 作用 | 关键参数 / 说明 |
|:--|:--|:--|
| `php think plugin:install <ident>` | 安装：依赖/能力/兼容检查 → 资源 → 迁移 → 数据填充 → 菜单权限 → 启用 | `--with-demo`（仅 `seed: demo` 生效）；`--ignore-compat`（应急跳过兼容拦截） |
| `php think plugin:uninstall <ident>` | 卸载（默认保留目录） | `--purge` 无痕清理（物理删菜单/权限、删残留表、清迁移记录、删 public 资源）；`--remove-files` 删目录（先打包到 `backup/`） |
| `php think plugin:enable <ident>` / `plugin:disable <ident>` | 启用 / 停用（不建表） | 未安装时先 install；`blocked`（库包缺失）时启用无效 |
| `php think plugin:upgrade <ident> <version>` | 升级：备份 → 下载/解压 → 兼容校验 → 迁移 → 填充（只跑 `required`）→ 菜单重注册 | `--package-url=` 不传表示文件已就位；`--ignore-compat` |
| `php think plugin:reinstall <ident>` | 修复安装状态（保留数据） | `-f/--force` 清库重装（**删业务数据**）；`--with-demo` 仅配合 `--force` |
| `php think plugin:scan` | 扫描插件清单 | `--refresh` 强制刷新扫描缓存（一般不需要，缓存会按清单指纹自愈） |

## 3. 数据：迁移与种子

| 命令 | 作用 | 关键参数 |
|:--|:--|:--|
| `php think plugin:migrate:create <Name> -p <ident>` | 创建迁移文件到 `database/migrations/` | 位置参数是**类名**（如 `CreateAcmeTicketTable`） |
| `php think plugin:migrate:run -p <ident>` | 执行迁移 | `-t/--target=` 到指定版本；`-d/--date=` |
| `php think plugin:migrate:status -p <ident>` | 查看迁移状态 | `-f/--format=json` |
| `php think plugin:migrate:rollback -p <ident>` | 回滚 | `-t/--target=` / `-d/--date=`；`-a/--all` 按文件逐个 DOWN（卸载用）；`-f/--force` 忽略断点 |
| `php think plugin:seed:create <SeederName> -p <ident>` | 创建 Seeder | 位置参数是类名（如 `AcmeTicketSeeder`） |
| `php think plugin:seed:run -p <ident>` | 执行全部 Seeder | `-s/--seed=` 只跑指定类 |
| `php think plugin:config:check` | 检查配置 schema 归一化与默认值播种 | `-p` 限定插件；`--seed` 补播缺失默认值 |

> Seeder 的**执行时机**由 manifest `seed` 决定（`required` / `demo` / `none`），不是“有文件就执行”。
> 详见 `../wiseadmin-plugin-manifest/SKILL.md`。

## 4. 菜单与权限

| 命令 | 作用 | 说明 |
|:--|:--|:--|
| `php think plugin:menu-derive -p <ident>` | 静态推导菜单并检测与控制器的一致性（不落库、不写文件） | `--check` 只校验（有漂移退出 2）；`--print` 打印可粘贴片段；`--apply` 写回 manifest（幂等，新增项带 `_auto_merged`）；`--prune` 回收陈旧自动项；`--sort` 重排；`--only/--exclude/--group` 过滤 |
| `php think plugin:menu-migrate` | 重注册插件菜单（纠正归属、补建缺失） | `--force` 以 manifest 覆盖字段；`--prune` 软删 manifest 已删除项；`--dry-run` |

> 菜单 `path` 会自动派生权限点，**不需要**在 `permissions` 里重复声明；
> 方法敏感规则：末段为 `save|update|edit` → POST，`delete` → DELETE，其余 GET。

## 5. 自检（交付前必跑）

| 命令 | 覆盖 | 关键参数 / 退出码 |
|:--|:--|:--|
| `php think plugin:audit -p <ident>` | 结构 / 清单 / 低敏正则提示（**本地不做 AST**） | `--all`、`--path=<dir\|zip>`、`--strict`（warn 也算失败）、`--ruleset=`、`--show-rules`；`0` 通过 / `1` 有 error / `2` 参数环境错误 |
| `php think plugin:test --unit -p <ident>` | 15 项静态契约（manifest/语法/命名空间/provider/菜单权限/迁移/种子/发布映射/路由） | `--all`、`--path=<zip>`、`--caseset=`、`--show-cases`、`--strict`；无需 DB |
| `php think plugin:test --integration -p <ident> --test-env=test --force` | 装→停→启→卸全链路 + 残留检查（切到 `.env.test`，父进程不连库） | `--test-env` 优先于 `config(plugin.test_env)`；不配则直接写**当前真实库**（会警告，且 `--force` 时**必须**配 `--expect-db`）；`--expect-db=` 与当前连接强校验；非交互/JSON 必须 `--force`；`--dry-run` 只预览不写库；`--purge` 无痕卸载；`--skip-unit` 不推荐 |
| `php think plugin:dev --status -p <ident>` | 只读诊断：静态副本 / 扫描缓存 / 待执行迁移 / 菜单权限未同步 | 退出码 `0` 无漂移 / `1` 有漂移 / `2` 执行失败 |
| `php think plugin:dev --assets -p <ident>` | 只复制有差异的静态文件 | 写文件；`--dry-run` 只报告 |
| `php think plugin:dev --refresh -p <ident>` | 清扫描缓存并按需同步菜单/权限 | 写库，需确认（`--force` 跳过） |
| `php think plugin:dev --migrate -p <ident>` | 列出并执行待执行迁移 | 写库，需确认 |
| `php think plugin:dev --all -p <ident>` | assets → refresh → migrate，遇错即停 | 可加 `--dry-run` |

> `plugin:test --integration` 会**建表/删表**，优先用 `--test-env=<name>` 指到 `.env.{name}`（完整副本）；
> 未配置时会在**当前真实库**执行并打印警告，且非交互（`--force`）下必须显式 `--expect-db=<当前库名>`。
> 它验证的是“能不能卸干净”，失败通常意味着迁移 `down()` 没删表、菜单/权限没清、`public` 资源没删。

**规则库说明**：`plugin:audit` 的规则来自随包 JSON 快照
`extend/wise/plugin/audit/rules/local/bundled.json`（39 条规则 / 5 条正则），
`--show-rules` 打印的版本号即该文件的 `version`。`code.regex.*` 只能是 `warn`；
手动改 JSON 级别会被加载器拒绝（服务端 AST 规则不在本地）。

## 6. 打包发布

| 命令 | 作用 | 关键参数 |
|:--|:--|:--|
| `php think plugin:publish -p <ident>` | 本地预检（audit + contract 串行）→ 打包 zip → SHA256 → 上传市场 | `--token=`（或用 env `PLUGIN_PUBLISH_TOKEN` / `config/plugin.php`）、`--plugin-version=`（**不要用 `--version`**，那是全局选项）、`--changelog=`、`--first` / `--update`、`--plugin-id=`、`--skip=`（额外排除，支持 glob）、`--dry-run`、`--skip-preflight`、`--json --force`、`--market-url=`、`--output=` |

- 预检有 `error` 会中止（退出 1）；`warn` 只提示。
- `--dry-run` 只产 zip + 报告，不上传；产物默认在 `dist/`。
- 上传后**以服务端审核结论为准**；本地预检不构成上架依据。

## 7. 退出码与 `--json`

| 退出码 | 含义 |
|:--:|:--|
| `0` | 通过 / 无漂移 / 执行成功 |
| `1` | 发现不一致（契约 error、`--strict` 下的 warn、预检阻断、用户取消） |
| `2` | 参数错误、库守卫拒绝、网络/服务端拒绝 |

`--json`（或 `--format=json`）时 **stdout 只有 JSON**，供脚本/AI 消费；错误也走 JSON：
`{"ok":false,"error":"<code>","message":"..."}`。写库命令在 `--json` 下**必须**配 `--force`。

## 8. 常见失败 → 处理

| 现象 | 原因 | 处理 |
|:--|:--|:--|
| `插件目录不存在` | 标识写错 / 未 create | 目录名必须等于 `manifest.name` |
| 菜单 404 | manifest `path` 与实际路由不一致 | `plugin:menu-derive -p <ident> --check` 看漂移，`--apply` 写回 |
| 装完菜单不出现 | 未安装/未刷新缓存 | `plugin:dev --refresh`；确认 `menus`（不是 `menu`） |
| 迁移不执行 | 文件名缺 14 位时间戳 / 类名不符 | 用 `plugin:migrate:create` 重建 |
| 卸载后残留表 | `down()` 没删表 | 补 `down()`，再跑 `plugin:test --integration --purge` |
| `--expect-db` 拒绝 | 当前连接不是测试库；或真实库 + `--force` 但没传 | 切库/改正库名；真实库必须显式声明库名，不要绕过 |
| 安装被拒：compatible_with | 声明范围不含当前框架 | 修 `compatible_with`；应急 `--ignore-compat` |
| 发布被拒：`develop=true` | 开发态插件不允许上架 | manifest 改 `"develop": false` |
| 发布被拒：token | 未提供会员 JWT | `--token=` / env / config 三选一 |

## 9. 与 AGENTS.md 的对应

| 本文件章节 | AGENTS.md |
|:--|:--|
| 0 / 5 / 6（路径、DoD、发布） | 第 11 节「完成定义（DoD）」 |
| 1（脚手架） / 2（生命周期） | 第 4、6 节 |
| 3（迁移/种子） | 第 8 节 |
| 4（菜单权限） | 第 4、5 节 |
| 字段与语义 | `../wiseadmin-plugin-manifest/SKILL.md` + `../../extend/wise/plugin/manifest.schema.json` |
