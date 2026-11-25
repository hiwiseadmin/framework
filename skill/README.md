# skill/ · WiseAdmin AI 技能包

> 面向第三方插件开发者的**细节层**；入口与硬约束在 [`../AGENTS.md`](../AGENTS.md)。
> 冲突处理：**AGENTS.md 优先**，本目录只补充“怎么做”，不改变“必须怎么做”。

## 已落地

| 技能 | 解决什么 | 何时读 |
|:--|:--|:--|
| [`wiseadmin-plugin-commands/SKILL.md`](wiseadmin-plugin-commands/SKILL.md) | 插件相关命令全表：脚手架、生命周期、迁移/种子、菜单、自检、打包发布、排障 | 不确定该敲哪条命令 / 参数怎么传 / 退出码含义 |
| [`wiseadmin-plugin-manifest/SKILL.md`](wiseadmin-plugin-manifest/SKILL.md) | `manifest.json` 字段全表：必填、菜单/权限节点、config 三种写法、seed、compatible_with、废弃键 | 新建/修改清单，或字段“写了不生效” |

## 尚未落地（规划）

- 插件页面（列表/表单/交互）细则：目前以 `../AGENTS.md` 第 7 节 + 内核 `window.Wise.*` 为准；
- 后端 CRUD 细则：以 `../AGENTS.md` 第 6 节 + `app/common/controller/traits/CrudAction.php` 注释为准；
- 多运行时（FPM/Swoole）细则：以 `../AGENTS.md` 第 9 节为准。

> 新增技能时：在本表登记，并在 `../AGENTS.md` 第 14 节“索引”里加一条，避免两份清单漂移。
