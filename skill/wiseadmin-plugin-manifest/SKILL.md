---
name: wiseadmin-plugin-manifest
description: |
  WiseAdmin 插件 manifest.json 字段全表与语义（第三方开发者视角）。涵盖必填字段、菜单/权限节点、
  config 三种写法与字段键、seed 三态、compatible_with 兼容范围、config_tabs/config_groups、slots、
  vendor、api、publish、capabilities，以及兼容键/废弃键与最小合规示例。
  触发词：manifest.json、插件清单、menus、permissions、config、config_schema、seed、compatible_with、
  publish、capabilities、vendor、slots、manifest 字段、字段不生效。
source: src/extend/wise/plugin/manifest.schema.json + src/extend/wise/plugin/README.md §10
updated: 2026-11-18
verify_note: 2026-11-18 以 manifest.schema.json 与 AuditRunner（KNOWN_FIELDS/校验）逐字段核对。
---

# manifest.json 字段全表

> 入口与硬约束见 [`../../AGENTS.md`](../../AGENTS.md)（第 5 节给最小合规示例）。
> 本文件只回答“有哪些字段、写什么类型、谁消费、写错会怎样”。

## 1. 必填与常用字段

| 字段 | 类型 | 必填 | 默认 | 消费点 / 说明 |
|:--|:--|:--:|:--|:--|
| `name` | string | ✅ | — | 插件唯一标识。必须与目录名一致；`a-z0-9_`，2–50 位；不能用保留词（admin/common/system/index/plugin/app/api） |
| `version` | string | ✅ | — | 语义化版本（`1.0.0`）；用于依赖比对与升级判断 |
| `title` | string | — | `name` | 显示名（后台/市场）；缺省回退 `name` |
| `description` | string | — | — | 市场详情与审核判断的第一手信息 |
| `author` | string | — | — | 作者署名 |
| `develop` | bool | — | `false` | 仅后台展示区分；**`true` 的包不允许发布** |
| `service_provider` | string | — | `plugin\{name}\BootServiceProvider` | 插件启动类；必须在 `plugin\{name}\` 下并继承 `WiseBootServiceProvider` |
| `seed` | enum | — | `required` | `required` 基线数据（装完即执行，失败回滚）/ `demo` 演示数据（默认跳过）/ `none` 不执行 |
| `compatible_with` | string \| string[] | — | 未声明=未知 | 框架兼容范围（Composer 语法，数组=任一满足）；不满足时安装/升级被拒，`--ignore-compat` 可绕 |
| `dependencies` | object | — | `{}` | 插件间依赖 `{ident: "^1.0"}`；缺失则不加载且不能启用 |
| `packages` | object | — | `{}` | Composer 库包依赖；字符串=硬依赖，对象可写 `{constraint, required:false}` 软依赖 |
| `icon` | string \| object | — | — | 启动台图标：`bi` / `image` / `emoji`，对象需 `value`，可带 `color` |
| `menu` | object | — | — | 插件**根容器**声明：`title` / `icon` / `mount` / `group` / `pinned` / `sort` / `path` |
| `menus` | array | — | `[]` | 后台菜单树（见 §2）；安装时写入，卸载/停用按生命周期处理 |
| `permissions` | array | — | `[]` | **无法由菜单派生**的独立权限（见 §3） |
| `config` | object \| array | — | — | 配置项 schema（见 §4）；安装时幂等播种默认值 |
| `config_tabs` | object | — | — | 配置页标签页标题/顺序：`{key: {title, sort}}` 或 `{key: "标题"}` |
| `config_groups` | object | — | — | 配置页分区标题/顺序（同上） |
| `slots` | array \| object | — | — | 前端插槽注入（`slot` / `component` / `title` / `icon` / `props`） |
| `vendor` | object | — | — | 第三方 PSR-4 命名空间：`{命名空间: 相对插件根路径}` |
| `api` | object | — | — | API 版本策略：`versions` / `default_version` / `require_version` / `deprecated` |
| `publish` | object | — | — | 额外静态资源映射 `{源相对插件根: 目标相对应用根（public/ 开头）}` |
| `capabilities` | object | — | — | 能力协商：`requires`（必需缺失阻断）+ `provides.services`（仅诊断建议） |

## 2. `menus` 节点

| 键 | 类型 | 必填 | 说明 |
|:--|:--|:--:|:--|
| `name` | string | ✅ | 菜单标题 |
| `type` | enum | ✅ | `dir`（分组）/ `menu`（页面）/ `button`（动作，仅用于派生权限） |
| `path` | string | — | `/plugin/{ident}/{controller}/{action}`；`dir` 可为空；`button` 用动作级 path |
| `icon` | string | — | Bootstrap Icons 类名，如 `bi bi-ticket` |
| `sort` | int | — | 排序（升序） |
| `status` | 0\|1 | — | 1 显示（缺省） |
| `component` | string | — | 前端组件标识（特殊页面用） |
| `children` | array | — | 子节点；兼容别名 `child` / `childlist`，**推荐 `children`** |

规则：

- 菜单 `path` 会自动派生权限点（ident/method），**不要在 `permissions` 里重复声明**；
- 方法敏感规则见 `config/menu_permission.php`（**方法集合**语义）：
  - 首选命名：`edit` = GET 表单页，`update` = POST 保存，`save|status` = POST；
  - 歧义后缀已容错：`edit|create` → `GET,POST`，`delete|remove` → `POST,DELETE`，其余默认 GET；
  - 个别路由不符约定时，在 `method.overrides` 里按完整 path 精确指定；
  - 同路径混用 GET/POST 仍禁止（RBAC 白名单下必 403），请拆成 `edit_form` + `update`；
- `button` 节点只为派生权限存在，不要指望它出现在导航里；
- 菜单写错最常见的两个原因：键名写成 `menu`（少 s）、`path` 与实际路由不一致（用 `plugin:menu-derive --check` 查）。

## 3. `permissions` 节点

| 键 | 类型 | 必填 | 说明 |
|:--|:--|:--:|:--|
| `name` | string | ✅ | 权限名称（授权界面展示） |
| `ident` | string | ✅ | 形如 `{ident}.{controller}.{action}`（如 `acme_ticket.ticket.export`） |
| `method` | string | — | HTTP 方法；缺省按路径推导 |
| `type` | string | — | `custom` = 独立权限；值为 `menu` 的项会被跳过 |
| `description` | string | — | 授权界面辅助说明 |

## 4. `config` 三种写法（`PluginConfigSchema::normalize()` 统一归一）

```jsonc
// A. 对象 map + value
"config": { "enable": { "type": "radio", "title": "启用", "value": "1", "options": { "1": "启用", "0": "禁用" } } }

// B. 数组 list + name（最常用）
"config": [
  { "name": "enable", "type": "radio", "title": "启用", "value": "1", "options": { "1": "启用", "0": "禁用" } }
]

// C. 对象 map + default
"config": { "cache_time": { "type": "number", "default": 3600 } }
```

单字段键：`name`/`key`、`type`、`title`、`value`/`default`（`value` 优先）、`options`（`select/radio/checkbox` 必填）、`tips`/`description`、`required`、`tab`、`group`、`sort`。

`type` 取值：`string/text/textarea/number/select/radio/checkbox/switch/boolean/color/date/editor/image/array`
（`switch` 与 `boolean` 等价，均渲染为开关并强转布尔）。

展示层级：`tab`（外层标签页）→ `group`（内层分区）→ 字段；只有一个 tab 时不渲染 tab 栏。

读取：`PluginConfigFacade::get('{ident}.{key}', $default)` —— key 必须是两段点号格式。

## 5. `seed` 语义（决定“装完库里有什么”）

| 值 | 安装 | 升级 | 失败处理 | 适用 |
|:--|:--|:--|:--|:--|
| `required`（缺省） | 执行 | 执行 | **回滚 + 阻断安装** | 默认站点、内容模型、栏目等“缺了就是半个系统”的基线数据 |
| `demo` | **跳过** | 跳过 | 只告警 | 示例内容/演示数据；`plugin:install --with-demo` 才写入 |
| `none` | 不执行 | 不执行 | — | 明确没有 seeds |

> Seeder 写在 `database/seeds/`，类名与文件名一致（首字母大写）；重复执行应幂等。

## 6. `compatible_with` 语义

- 语法同 Composer：`^1.0` / `~1.2` / `1.2.*` / `>=1.0 <2.0` / `^1.0 | ^2.0`；数组 = 任一满足；
- **未声明 = 未知**：不拦截、不标红（存量插件都走这条路）；
- 声明且不满足 → 安装/升级被拒，报错含声明范围与当前版本；`--ignore-compat` 仅应急；
- 它是**作者声明**，不是实测结论；市场/后台徽章也按“声明”展示；
- 建议写主版本 `["^1.0"]`，不要写死补丁号（否则补丁升级后“看起来不兼容”）。

## 7. 兼容键与废弃键（不要在新清单里写）

| 键 | 状态 | 说明 |
|:--|:--|:--|
| `ident` | 兼容 | 与 `name` 不一致会报 `manifest.ident_mismatch`；新清单只写 `name` |
| `display_name` / `config_schema` | 缓存序列化键 | 扫描缓存往返用；新清单写 `title` / `config` |
| `require` | 历史键 | 用 `dependencies`（插件间）或 `packages`（composer） |
| `third_namespaces` | 兼容 | 用 `vendor` |
| `hooks` / `editor_provider` / `editor_types` / `storage_drivers` / `payment_drivers` / `captcha_drivers` / `plugin_class` / `providers` | **废弃** | 框架不读取；DI 绑定用插件目录下 `provider.php` |

## 8. 最小合规示例

```json
{
    "name": "acme_ticket",
    "title": "工单管理",
    "version": "1.0.0",
    "author": "ACME",
    "description": "报修与工单流转",
    "service_provider": "plugin\\acme_ticket\\BootServiceProvider",
    "compatible_with": ["^1.0"],
    "seed": "none",
    "menus": [
        {
            "name": "工单管理",
            "type": "dir",
            "path": "",
            "children": [
                { "name": "工单列表", "type": "menu", "path": "/plugin/acme_ticket/ticket/index" },
                { "name": "新建工单", "type": "button", "path": "/plugin/acme_ticket/ticket/save" }
            ]
        }
    ],
    "config": [
        { "name": "enable", "type": "switch", "title": "启用", "value": "1" }
    ]
}
```

## 9. 校验与排错

| 目的 | 命令 |
|:--|:--|
| 结构与清单预检（未知字段、必填、枚举、publish 越界） | `php think plugin:audit -p <ident>` |
| 契约检查（菜单/权限/迁移/路由与清单一致性） | `php think plugin:test --unit -p <ident>` |
| 菜单与控制器一致性 | `php think plugin:menu-derive -p <ident> --check`（有漂移退出 2） |
| 配置归一化与默认值 | `php think plugin:config:check -p <ident>` |
| 字段契约本身 | `extend/wise/plugin/manifest.schema.json` |

常见“写了不生效”：

| 症状 | 原因 |
|:--|:--|
| 菜单不出现 | 写成 `menu` 而非 `menus`；或 `type` 缺失 |
| 配置读不到 | 写在 `config_schema`；或读取 key 不是 `{ident}.{key}` |
| 权限重复 | 菜单 path 已派生，`permissions` 又声明了一遍 |
| 装完一堆假数据 | seeder 是演示数据但没声明 `seed: demo` |
| 升级后菜单残留 | manifest 删了节点但没跑 `plugin:menu-migrate --prune` |

## 10. 与 AGENTS.md 的对应

| 本文件章节 | AGENTS.md |
|:--|:--|
| 1（字段总表） | 第 5 节「manifest.json 契约（最小合规）」+ 第 13 节常见错误 |
| 2（menus） | 第 4 节「命名与目录」菜单 path 规则 |
| 5（seed） / 6（compatible_with） | 第 8 节迁移与种子、第 11 节 DoD |
| 9（校验命令） | 第 11 节 + `../wiseadmin-plugin-commands/SKILL.md` 第 5 节 |
