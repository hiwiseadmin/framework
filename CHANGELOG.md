# Changelog

> 记录方式：按日期归档，同一天的多项改动合并为一条；只记录**对外可见的能力变化**，
> 内部重构与细节见对应包的 `CHANGELOG.md`。

## 2026-11-10

### 新增

- **框架内部版本号**：新增 `app_version()`（实现见 `app/common/library/Version.php`），
  只读应用根目录 `version.json`（`product/channel/commit/builtAt`）。`config/site.php` 的
  `version` 与 `config/update.php` 的 `current_version` 均已改为引用它，版本号不再多处手写。
  新增命令 `php think wise:version [--json]`（`version.json` 缺失时回落到 `0.0.0` 并以退出码 1 告警）。
- **插件静态资源发布映射（`manifest.publish`）**：`{源相对插件根} → {目标相对应用根}`，
  安装/启用时由 `AssetManager::copyAssets()` 与 `statics/` 一并复制，卸载时一并清理。
  会员主题资产（`theme/{theme}/assets`）由此发布到 `public/statics/member/themes/{theme}`。

### 修复

- **member 插件化遗留**：主题目录由 `theme/theme/{theme}` 拍平为 `theme/{theme}`；
  主题根 / 插件根 / 后台页前端模块路径改为按插件根解析（此前 `dirname()` 层级仍按包布局计算，
  前台模板会解析到 `plugins/theme/...` 而白屏）；15 个 `verify-*.php` 改用公共
  `tests/bootstrap.php`（统一注册 `plugin\*` 命名空间），路径与断言同步修正。
  后台页视图 scope 由 `wise-member` 收敛为插件标识 `member`。

## 2026-11-09

### 新增

- **控制器内回收站（CrudAction）**：回收站不再是独立能力，而是 `_restore()/_forceDelete()` 的列表视图。
  控制器写 4 行 public 方法即启用（`recycleList` / `recycleToken` / `recycleRestore` /
  `recycleForceDelete`），`CrudSchemaTrait::crudUrls()` 按动作存在性下发端点（与 `export` 同构），
  前端据此决定是否渲染「回收站」按钮；模型未启用软删除时 `supportsRecycle()` 返回 false，
  相关动作返回明确错误而非空列表。列表与 `_getList()` 同构（`formatPaginate`），
  模块可覆写 `buildRecycleQuery()` 追加业务过滤、覆写 `afterRestore()` 重建被 `afterDelete` 清理的关联。
  首个接入样例：菜单管理（`Menu` + 回收站弹层表格），权限节点见迁移
  `20261109120000_add_menu_recycle_permissions`。
  全局回收站（`/admin/recycle`）保留，定位为跨模块兜底与运维清理。

### 修复

- **`CrudAction::_restore()` 原本不可用**：旧实现探测并调用 `restoreByIds()`，而该方法只存在于
  可选的 `app\common\model\traits\SoftDeleteTrait`（宿主模型均未引入），任何调用都只会得到
  「当前模型不支持恢复操作」。现改为 `onlyTrashed()` 取记录后逐条 `restore()`，并支持 `afterRestore()` 钩子。
  同步修正 `Menu::restore()` / `Permission::restore()` 两处同样的错误调用。
- **`CrudAction::_forceDelete()` 无法删除回收站记录**：软删模型的默认查询会排除已软删数据，
  旧的 `whereIn(...)->select()->force()->delete()` 目标集恒为空；现显式 `withTrashed()` 取记录后逐条 `force()->delete()`，
  并新增 `$fromRecycle` 开关：只允许删除回收站内的记录 + 校验一次性确认令牌（防止该入口变成任意物理删除通道）。

## 2026-11-07

### 变更 / 注意

- **字段级变更记录（P0-2B）收窄采集口径**：新增 `config/change_log.php` 的 `actors`
  主体白名单（默认 `['admin']`），**只有后台管理员发起的写入才落库**；
  会员自助（`member`）、CLI / 队列 / 定时任务（`cli`）、匿名前端（`guest`）在采集入口直接丢弃。
  影响：升级后 CLI 批量导入/迁移不再产生字段级记录；如需临时全量采集，把 `actors` 设为
  `null` 或 `['*']`（会同时关闭主体判定）。命中过滤会留一条 info 日志（`log_skipped`，默认开）。
- **修复会员 API 身份串号**：`wise-member` 的 `JwtAuth` 现在会写入 `RequestContext`
  （`setUser` + `setGuard('member')`）。此前会员 API 请求的 guard 会回落到默认值 `admin`，
  导致审计/日志归属被误判为后台上下文。
- **噪音字段治理**：`Admin` / `Member` 模型排除登录轨迹（`last_login_time` / `last_login_ip`），
  `Admin` 额外排除 `ext_fields`（仅存 UI 主题偏好）；`ignore_fields` 收敛为 3 个时间戳，
  作为 sink 层兜底。`ignore_routes` 本次不引入（路由白名单是开放集合，主体判定已覆盖同类场景）。
- **行为变更（内核）**：`wise-support` 的 `ChangeLoggable::changeLogExcept()` 语义由
  「整体替换默认黑名单」改为「在基础排除（create_time / update_time / delete_time）之上**追加**」。
  模型侧不要再重复声明三个时间戳；若曾用只含 `update_time` 的返回值表达「仅排除更新时间」，
  升级后 `create_time` 也会被恒定排除。

## 2026-11-05

### 新增

- **服务端导出（P0-1）**：列表页支持按「当前筛选全部 / 勾选（含跨页）」导出 CSV，
  与 `getList` 共用 `buildListQuery()`（行数与顺序一致）；流式分批写盘、UTF-8 BOM、
  敏感字段黑名单；超过阈值自动转异步任务，完成后进下载中心。
  入口：`CrudAction::_export()` + 控制器 `public export()`；前端为对象型工具栏按钮，按权限渲染。
- **全局回收站（P0-2A）**：跨模型查看已软删记录，支持还原 / 彻底删除 / 按模型清空；
  模型通过扩展点 `ExtPoints::RECYCLE_PROVIDER` 注册（宿主读 `config/recycle.php`，
  包与插件各自注册），注册表变更即时可见；四个独立权限节点 + 确认令牌 + 审计。
- **字段级变更记录与回滚（P0-2B）**：模型 `use wise\support\traits\ChangeLoggable` 一行接入，
  自动记录字段级 旧值→新值（同一保存聚合为 `change_set`）；`password/token/secret` 类字段
  **整字段不落库**，超 4KB 截断并标记；后台「变更记录」页按记录展示时间线（行级 diff 高亮），
  支持回滚到某次变更之前（独立权限 + 确认令牌 + 回滚自身留痕）。
  新增命令 `php think change-log:archive`（归档旧记录）。
- **定时任务后台界面（P0-3）**：`wise-crontab` 包内新增后台页面 —— 任务列表（含配置文件任务，
  只读标记）、新增/编辑、启停、异步立即执行、执行日志（列表 + 详情）、调度错误面板。
  写入动作全部 POST，权限节点由菜单 path 派生；新增队列 handler `crontab:run`。
- **通用文本 diff**：`TextDiff` 下沉到 `wise-support`（`wise\support\tools\TextDiff`），
  宿主、包、插件共用；wisecms 侧保留薄壳，行为不变。

### 变更 / 注意

- 新增迁移 4 条（下载中心菜单、回收站菜单、`operation_log_changes` 表、变更记录菜单），
  升级后需执行 `php think migrate:run`。
- 新增权限节点若干（每页独立 `admin.{controller}.export`、`admin.recycle.*`、
  `admin.change_log.*`、`admin.crontab.*`），执行 `php think menu:sync` 或重新安装后生效。
- `config/menu_permission.php` 的 `path_suffix` pattern 新增 `run` / `issuetoken` → `POST`
  （否则非超管调用「立即执行 / 签发确认令牌」会 403，且下一次 `menu:sync` 会覆盖迁移式纠正）。
- 队列新增 handler：`export:list`、`changelog:persist`、`crontab:run`；
  异步能力依赖 `php think queue:work` 常驻。
- 完整验收步骤见 `docs/wiseadmin-p0-acceptance-checklist.md`；
  自动化自测：`tests/manual/p0-{1a,2a,2b,3}-*.php`（145 项断言全通过）。
