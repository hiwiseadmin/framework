# AGENTS.md · WiseAdmin 插件开发约定（面向使用框架的开发者）

> 适用：WiseAdmin 1.0.x ｜ 最后更新：2026-11-18
> 读者：人类开发者与 AI 编码助手（Cursor / Claude Code / CodeBuddy 等）
> 定位：**这是一份约定与禁止清单，不是 API 手册**。命令、字段、契约以代码与命令自身 `--help` 为准。
> 深度内容：`skill/`（AI 技能包，随框架分发；当前已落地「命令表」「manifest 字段表」两份，见 §14）与 `vendor/wiseadmin/*/README.md`。
> 本文件只描述**插件开发**（本框架的扩展方式）。修改 `app/`、`extend/`、`vendor/` 属于框架二次开发，不在本文范围。

---

## 1. 你在什么项目里

ThinkPHP 8（`topthink/framework ^8.0`）+ PHP 8.1+ 的后台管理框架。
**当前目录就是项目根目录**（`think`、`app/`、`config/`、`public/` 都在这一层）。

三条开发路径，动手前先选一条：

| 路径 | 适用 | 产物位置 | 可升级 |
|:--|:--|:--|:--:|
| **A. 写插件**（推荐，本文主线） | 要把业务能力复用到多个站点 / 上架市场 | `plugins/{ident}/` | ✅ |
| B. 宿主内改业务 | 一次性交付、只服务本站 | `app/` | ⚠️ 升级需人工合并 |
| C. 组合 wise-* 包 | 需要支付、审批、附件、JWT 等基础能力 | `composer require wiseadmin/wise-*` | ✅ |

> **AI 默认选 A**。除非用户明确说“只改本站”，否则不要往 `app/` 里写插件业务。

---

## 2. 黄金 10 条（违反即返工）

1. 插件代码**只写** `plugins/{ident}/`；不要写进 `app/`、`extend/`、`vendor/`。
2. 命名空间必须是 `plugin\{ident}\...`；目录名与 manifest 的 `name` 必须完全一致。
3. 后台列表**不要**手写 `<table>` + `{foreach}`；用 `window.Wise.table.createSync()`。
4. **不要** `import '/statics/modules/*'`（与内核 bundle 不是同一实例）；只用 `window.Wise.*`。
5. **不要** `alert()` / 原生 `confirm()`；用 `Wise.ui.toast` / `Wise.ui.dialog.confirm`。
6. 前端校验用 `data-validation` 契约，**不是** HTML5 `required`；后端 Validate 必须独立存在。
7. 迁移文件名必须是 `{14位时间戳}_{snake_case}.php`；类名 = 文件名 StudlyCase。
8. `database/seeds/` 的语义要用 manifest `seed` 声明（`required` / `demo` / `none`），不要假设“装完必跑”。
9. 需要框架版本约束就写 `compatible_with`（如 `["^1.0"]`）；不写视为未知（不拦截）。
10. 交付前必须跑完 §10 的验证链；**本地不通过不要交付**。

---

## 3. 目录地图（第三方视角）

```
.
├── app/                宿主应用（后台/公共控制器、模型、中间件）—— 插件不要改
├── config/             项目配置（plugin.php：市场地址/许可/扫描缓存）
├── extend/             框架扩展（plugin/ 基类、wise/ 框架内核）—— 不要改
├── vendor/             Composer 依赖；wise-* 包在 vendor/wiseadmin/*
├── plugins/{ident}/     ★ 你的插件（唯一可写目录）
├── public/
│   ├── index.php       入口
│   └── statics/        前端资源；statics/plugins/{ident}/ 是插件资源副本（自动生成，勿手改）
├── route/              宿主路由（插件路由在插件目录内）
├── database/           宿主迁移/种子（插件迁移在插件目录内）
├── runtime/            缓存与日志（不要提交）
├── think               CLI 入口
└── skill/              AI 技能包（已落地命令表 / manifest 字段表；索引见 §14）
```

`vendor/wiseadmin/wise-*` 是框架的能力包（auth / attachment / api / jwt / workflow / market / mcp / agent …）。
**扩展它们只允许**：配置、事件监听、`ExtPoint::register()`、继承其接口。
**禁止**直接改 `vendor/` 里的文件（`composer install/update` 会覆盖）。

---

## 4. 插件骨架与命名

`php think plugin:create acme_ticket`（默认 13 文件；`--minimal` 7 个；`--with-example` 16 个）生成：

```
plugins/acme_ticket/
├── manifest.json            声明：菜单/权限/配置/兼容/填充策略
├── BootServiceProvider.php  插件启动入口（注册路由、事件、扩展点）
├── common.php               全局函数（每个函数必须 function_exists() 包裹）
├── event.php                事件订阅
├── middleware.php           插件中间件
├── provider.php             容器绑定
├── service.php              服务类
├── controller/              控制器（后台继承 plugin\BaseController）
├── model/                   模型（继承 plugin\BaseModel）
├── validate/                验证器
├── route/                   路由：plugin.php / api.php / index.php
├── database/migrations/     迁移
├── database/seeds/          数据填充
├── view/                    模板（插件后台页面）
├── statics/                 前端资源（安装/启用时复制到 public/statics/plugins/{ident}/）
└── lang/                    多语言（自动登记，无需注册）
```

命名硬约束：

| 对象 | 规则 | 例 |
|:--|:--|:--|
| 插件标识 | 小写字母开头，`a-z0-9_`，2–50 位，与目录名一致 | `acme_ticket` |
| 命名空间 | `plugin\{ident}\` | `plugin\acme_ticket\controller\Ticket` |
| 后台路由 | `/plugin/{ident}/{controller}/{action}` | `/plugin/acme_ticket/ticket/index` |
| 菜单 path | 与后台路由同形；`button` 用动作级 path | `/plugin/acme_ticket/ticket/save` |
| 数据表 | 建议 `{ident}_` 前缀；模型 `$name` 必须写全表名 | `acme_ticket_ticket` |
| 权限 ident | 菜单自动派生；独立权限用 `{ident}.{controller}.{action}` | `acme_ticket.ticket.export` |

菜单权限方法约定（`config/menu_permission.php`）：首选 `edit`=GET 表单页、`update`=POST 保存；
歧义后缀已用**方法集合**容错（`edit|create`=`GET,POST`，`delete|remove`=`POST,DELETE`），
避免开发者用习惯方法时被 403；但不符约定的个别路由必须在 `method.overrides` 精确声明，
且**不要同路径混用 GET/POST**。

控制器基类（别继承错）：

| 场景 | 基类 |
|:--|:--|
| 后台管理页（要登录 + 权限 + CSRF） | `plugin\BaseController` |
| 对外 API（JWT / 签名） | `plugin\ApiController` |
| 前台页面（无后台权限） | `plugin\FrontController` |

---

## 5. manifest.json 契约（最小合规）

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
    ]
}
```

必读字段（其余见 `extend/wise/plugin/manifest.schema.json`）：

| 字段 | 说明 |
|:--|:--|
| `name` / `version` / `author` | 必需；`name` 与目录名一致 |
| `title` / `description` | 后台与市场展示用；缺了只是展示退化 |
| `service_provider` | 缺省推导 `plugin\{ident}\BootServiceProvider` |
| `menus` | 安装时写入菜单；`menu`/`button` 会自动派生权限点 |
| `permissions` | 只放**无法由菜单派生**的独立权限 |
| `config` | 配置项 schema；安装时播种默认值，后台自动渲染表单 |
| `publish` | 额外静态资源映射 `{源: "public/..."}` |
| `seed` | 数据填充策略：`required`（缺省，装完即执行，失败回滚）/ `demo`（默认跳过，`--with-demo` 才跑）/ `none` |
| `compatible_with` | 框架兼容范围，Composer 语法；未声明 = 未知，不拦截 |

> **字段全表**（类型/默认值/消费点/兼容键与废弃键）：[`skill/wiseadmin-plugin-manifest/SKILL.md`](skill/wiseadmin-plugin-manifest/SKILL.md)

---

## 6. 后端：两种写法

### 6.1 一张表 → CRUD（最快）

```bash
php think wise:crud acme_ticket_ticket -p acme_ticket -t "工单" --merge-manifest
```

生成控制器/模型/视图/JS 垫片，并（`--merge-manifest`）把菜单并入 manifest。
`--dry-run` 只看计划；`--field` / `--relation` 声明枚举与外键；`--save-map` 复用字段声明。

### 6.2 手写控制器（需要精细控制时）

```php
<?php
declare(strict_types=1);

namespace plugin\acme_ticket\controller;

use app\common\controller\traits\CrudTrait;
use plugin\BaseController;
use plugin\acme_ticket\model\Ticket as TicketModel;
use think\Model;

class Ticket extends BaseController
{
    use CrudTrait;

    /** CrudTrait 要求显式声明模型（不做隐式 $this->model 推断） */
    protected function getModel(): Model
    {
        return new TicketModel();
    }

    // 只开放你写出来的动作；没写的动作不可达（fail-closed）
    public function index()   { return $this->_index(); }
    public function getList() { return $this->_getList(); }
    public function save()    { return $this->_save(); }
    public function update()  { return $this->_update(); }
    public function delete()  { return $this->_delete(); }
}
```

`CrudTrait` = `RequestQueryBuild`（查询构建）+ `CrudAction`（`_index/_getList/_save/_update/_delete/_status/_sort/_export/_restore/_recycleList/...`）。
钩子：`beforeSave(array &$data, bool $isNew)`、`beforeDelete(array $ids)`、`validateBySchema()`。

### 6.3 读插件配置

```php
use wise\plugin\config\PluginConfigFacade;

// key 必须是 "插件标识.配置键"（点号分隔，两段）
$enabled = (bool) PluginConfigFacade::get('acme_ticket.enable', false);
```

> 配置项必须先写进 manifest `config`，否则读不到（安装时播种默认值）。

### 6.4 模型 trait 的事件钩子要自己写

`wise-support` 的 `ChangeLoggable`、`wise-url` 的 `TracksUrlAlias` 等只提供**方法**，不声明 `onAfterInsert` / `onAfterWrite` / `onAfterDelete`。在模型里 `use` 之后必须自行实现钩子并转发：

```php
class Ticket extends BaseModel
{
    use \wise\support\traits\ChangeLoggable;

    protected static function onAfterInsert($model): void { $model->changeLogOnInsert(); }
    protected static function onAfterWrite($model): void  { $model->changeLogOnWrite(); }
}
```

钩子必须是 `protected static`（TP8 以类名字符串调用）。不写不会报错，但审计 / URL 301 会静默失效；两个 trait 都声明同名钩子则直接 Fatal error。原因见根 `AGENTS.md` §5 第 7 条。

---

## 7. 后台页面（AI 最容易写错的地方）

### 7.1 列表页

模板只放两个容器，**不要**自己渲染表格：

```html
<div id="toolbar" class="ws-toolbar"></div>
<table id="table"></table>
```

业务脚本放在插件的 ESM 文件里（`statics/js/*.js`），页面用 nonce 引入：

```html
<script type="module" src="/statics/plugins/acme_ticket/js/ticket.js"
        nonce="{$csp_nonce|default=''}"></script>
```

```js
// statics/js/ticket.js
window.Wise.table.createSync({
    idTable: 'table',
    url: '/plugin/acme_ticket/ticket/getList',
    pageSize: 20,
    columns: [
        { field: 'id', title: 'ID', width: 80, align: 'center' },
        { field: 'title', title: '标题' },
        { field: 'status', title: '状态', formatter: 'normal' },
        { field: 'operate', title: '操作', formatter: 'operate' }
    ]
});
```

后端返回约定：`{ code: 0, msg, data: { rows, total } }`（`Wise.table` 默认读这两个键）。

### 7.2 提示 / 确认 / 提交

```js
Wise.ui.toast.success('已保存');
Wise.ui.dialog.confirm('确定删除？').then((ok) => { if (ok) { /* ... */ } });

// 统一链路：确认 → Loading → 请求 → 提示
Wise.ui.action.execute({
    url: '/plugin/acme_ticket/ticket/delete',
    data: { id: row.id },
    confirmMessage: '确定删除该工单？',
    successMsg: '已删除'
});
```

### 7.3 表单校验

```html
<div class="mb-3">
    <label class="form-label">标题 <span class="text-danger">*</span></label>
    <input type="text" name="title" class="form-control"
           data-validation="required|maxLength:50"
           data-validation-message-required="请填写标题">
    <div class="invalid-feedback"><span class="validation-text"></span></div>
</div>
```

规则用**前端词汇**（`required` / `minLength` / `maxLength`），不是后端（`require` / `max`）。
条件必填请用 `WsFormValidator.setRules()` + `when`；`data-conditions` 目前无消费方。
**不要**用 `WsFormValidator.autoSubmit`（硬编码 POST + serialize，不支持 PUT/JSON/CSRF）。

### 7.4 静态资源缓存与动态 import

服务端会给 HTML 里的 `<script src>` / `<link href>`（`/statics/` 下的 js/css/图片）自动追加 `?_v=`。
后台「清理缓存 → 组件缓存」= 该版本号 +1，HTML 与动态加载一起失效。

动态加载的资源必须走框架 helper，否则浏览器按 URL 复用旧文件（表现：文件已更新、页面仍跑旧逻辑）：

```js
// 正确：追加全局版本号（已带 _v= 时原样返回）
const mod = await import(Wise.util.asset('/statics/plugins/acme_ticket/js/detail.js'));

// 顶层别名等价
const mod2 = await import(Wise.asset('/statics/plugins/acme_ticket/js/list.js'));
```

约束：

- ESM 的**相对/静态 import**（`import './detail.js'`）由浏览器按模块 URL 解析，**不继承**父模块的
  `?_v=` —— 这类入口必须打成单文件 bundle，或改用上面的 helper 传绝对路径；
- 不要在 JS 里写死 `?v=1.0.0`：热修复时没人记得改它，只会命中旧缓存。

---

## 8. 数据库：迁移与种子

```bash
php think plugin:migrate:create acme_ticket create_acme_ticket_table
php think plugin:migrate:run acme_ticket
php think plugin:migrate:status acme_ticket
php think plugin:seed:create AcmeTicketSeeder -p acme_ticket   # 或手写 database/seeds/ 下的类
```

```php
// plugins/acme_ticket/database/migrations/20261118010101_create_acme_ticket_table.php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateAcmeTicketTable extends Migrator
{
    public function up(): void
    {
        $this->table('acme_ticket_ticket', ['engine' => 'InnoDB', 'comment' => '工单'])
            ->addColumn(Column::string('title', 100)->setComment('标题'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('1 待处理 2 已完成'))
            ->addColumn(Column::datetime('create_time')->setNullable())
            ->addTimestamps()   // 若项目已有 create/update 约定，可改为显式列
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('acme_ticket_ticket')) {
            $this->table('acme_ticket_ticket')->drop()->save();
        }
    }
}
```

规则：
- 文件名 `{14位时间戳}_{snake_case}.php`，类名 StudlyCase 且与文件名一致；
- `up()` + `down()` 成对，`down()` 负责删表/回滚（**要能卸干净**，`plugin:test --integration` 会验）；
- 建表语句与模型 `$name` 完全一致；
- `seed: required` 只放“缺了就是半个系统”的基线数据；示例/演示数据用 `seed: demo`。

---

## 9. 多运行时：FPM 与 Swoole 必须同时兼容

> 只写一份代码，**不写 FPM/Swoole 分支**。优先级：ThinkPHP 原生 > `wise-support` 收口 > 裸 Swoole（禁止）。

| 需求 | ✅ 用 | ❌ 不要用 |
|:--|:--|:--|
| 输入 / 上传 / 服务端信息 | `$request->param()` / `->file()` / `->server()` / `->header()` | `$_GET` `$_POST` `$_FILES` `$_SERVER` |
| 会话 | `$request->session()` | `$_SESSION`、`session_start()` |
| 响应 | 返回 `Response` / 统一 JSON trait | `header()` / `setcookie()` / `exit` / `die` |
| 请求级数据 | `$request->xxx`、`wise\support\context\RequestContext` | 插件类属性、`static $var` 存用户/租户/当前请求 |
| 改配置 | 请求级传参、`$request->xxx` | `Config::set()` 改全局后依赖它 |
| 异步/延时 | `think-queue` 投递 | HTTP 路径内 `sleep()` / `usleep()` |
| 探测运行环境 | `class_exists()` 守卫（收口到 wise-support） | 插件里散写 `extension_loaded('Swoole')` |

常驻模式三个高频坑：

1. **boot 链会重复执行**：`BootServiceProvider::boot()`、`common.php` 的注册动作必须幂等；
   `common.php` 里每个函数都要 `function_exists()` 包裹。
2. **请求级静态状态 = 串号**：协程共享进程，`static` 属性里放当前用户/租户必然串。
3. **HTTP 路径禁阻塞**：`exec` / `shell_exec` / `system` / `proc_open` / `sleep` 只在 CLI（安装、迁移）里允许。

> `topthink/think-swoole` 是可选依赖（`suggest`），默认线上跑 FPM；代码必须两种环境都成立。

---

## 10. 安全红线

- SQL 只用查询构造器 / 绑定参数，**禁止**拼接用户输入。
- **禁止** `eval` / `exec` / `system` / `shell_exec` / `passthru` / `assert(字符串)`。
- **禁止** `$_GET` / `$_POST` 直接进 `include` / `require`；用白名单映射。
- 密钥、许可、账号密码**不得**写进代码或 `manifest.json`；走 `config/` + `.env`。
- 不绕过框架的 CSRF、权限中间件与 `VerifyCsrfToken`（插件后台已自动生效，无需自己实现）。
- 文件上传走 `wise-attachment`，不要自己拼保存路径。
- 提交前必须跑 `plugin:audit`；本地只做结构与低敏正则预检，**AST 与人工审核在服务端**。

---

## 11. 完成定义（DoD）

按顺序跑，全绿才算完成：

| 步骤 | 命令 | 期望 |
|:--:|:--|:--|
| 1 | `php think plugin:audit -p acme_ticket` | 无 `error`（`warn` 只提示） |
| 2 | `php think plugin:test --unit -p acme_ticket` | 15 项契约用例 0 error |
| 3 | `php think plugin:test --integration -p acme_ticket --test-env=test --force` | 装→停→启→卸全链路通过，无残留（`.env.test` 指向测试库；未配置测试环境时写真实库，且 `--force` 必须配 `--expect-db`） |
| 4 | `php think plugin:dev --status -p acme_ticket` | 无漂移（退出 0） |
| 5 | `php think plugin:publish -p acme_ticket --dry-run` | 产出 zip + SHA256（先看包内容） |
| 6 | `php think plugin:publish -p acme_ticket --token=<市场令牌>` | 上传成功，以服务端审核结论为准 |

退出码：`0` 通过 ｜ `1` 发现不一致 ｜ `2` 参数/环境错误。
`--integration` 会建表/删表：优先用 `--test-env=<name>` 指向 `.env.{name}`（完整副本）；
未配置时写当前真实库，非交互（`--force`）必须显式 `--expect-db` 作为守卫；`--dry-run` 可先预览目标库。

> **命令全表**（每条命令的参数、写库/网络边界、常见失败处理）：[`skill/wiseadmin-plugin-commands/SKILL.md`](skill/wiseadmin-plugin-commands/SKILL.md)

---

## 12. 三个可复制的最小示例

### 12.1 最小 action（返回 JSON）

```php
// plugins/acme_ticket/controller/Ping.php
namespace plugin\acme_ticket\controller;

use plugin\BaseController;

class Ping extends BaseController
{
    public function index()
    {
        return $this->success('pong', ['time' => time()]);
    }
}
```

### 12.2 一张表的列表 + 表单（CrudTrait）

```php
// plugins/acme_ticket/controller/Ticket.php
namespace plugin\acme_ticket\controller;

use app\common\controller\traits\CrudTrait;
use plugin\BaseController;
use plugin\acme_ticket\model\Ticket as TicketModel;
use think\Model;

class Ticket extends BaseController
{
    use CrudTrait;

    protected function getModel(): Model
    {
        return new TicketModel();
    }

    public function index()   { return $this->_index(); }
    public function getList() { return $this->_getList(); }
    public function save()    { return $this->_save(); }
    public function update()  { return $this->_update(); }
    public function delete()  { return $this->_delete(); }
}
```

```php
// plugins/acme_ticket/model/Ticket.php
namespace plugin\acme_ticket\model;

use plugin\BaseModel;

class Ticket extends BaseModel
{
    protected $name = 'acme_ticket_ticket';   // 必须写全表名
}
```

### 12.3 读配置

```php
use wise\plugin\config\PluginConfigFacade;

$enable = (bool) PluginConfigFacade::get('acme_ticket', 'enable', true);
```

---

## 13. 常见错误 → 正确写法

| 错误 | 现象 | 正确做法 |
|:--|:--|:--|
| 写 `"menu"` 而不是 `"menus"` | 菜单不出现 | 用 `menus`；`plugin:audit` 会提示未知字段 |
| 写 `"config"` 却在代码里读 `config_schema` | 配置读不到 | manifest 写 `config`，代码用 `PluginConfigFacade` |
| 目录名与 `name` 不一致 | 扫描不到/装不上 | 二者必须一致 |
| 迁移文件名没有 14 位时间戳 | 迁移不执行 | `plugin:migrate:create` 生成 |
| 迁移类名与文件名不符 | 迁移被跳过 | StudlyCase 对齐 |
| `seed` 里放演示数据但未声明 | 用户装完一堆假数据 | 声明 `"seed": "demo"` |
| 手写 `<table>` + `{foreach}` | 分页/排序/批量全失效 | `Wise.table.createSync()` |
| `import '/statics/modules/*'` | 内核配置不生效 | `window.Wise.*` |
| 把插件代码放进 `app/` | 升级即丢 | 放进 `plugins/{ident}/` |
| 用了 `exit` | Swoole 下进程退出 | 返回 `Response` |

---

## 14. 索引

| 想查什么 | 看哪里 |
|:--|:--|
| **命令怎么敲 / 参数 / 退出码 / 失败处理** | [`skill/wiseadmin-plugin-commands/SKILL.md`](skill/wiseadmin-plugin-commands/SKILL.md) |
| **manifest 字段 / 类型 / 默认值 / 废弃键** | [`skill/wiseadmin-plugin-manifest/SKILL.md`](skill/wiseadmin-plugin-manifest/SKILL.md) |
| 字段机器契约（唯一事实来源） | `extend/wise/plugin/manifest.schema.json` |
| 本地预检规则库（JSON 快照） | `extend/wise/plugin/audit/rules/local/bundled.json`（`--show-rules` 可读；不要手改级别） |
| 插件系统实现说明（字段、生命周期、内部机制） | `extend/wise/plugin/README.md` |
| 技能包目录与规划 | `skill/README.md` |
| 命令发现 | `php think list` ｜ 单命令选项：`php think <command> --help` |
| 市场与许可 | 项目 `config/plugin.php`；上架与结算规则以市场页面为准 |

> 索引约定：`AGENTS.md` 是入口与硬约束，`skill/*/SKILL.md` 是细节层。
> 新增细节内容优先写进 skill，**只在 AGENTS.md 留一行指针**，避免两份长文互相漂移。

---

## 15. 维护约定

- 新增命令、manifest 字段、契约变更时，**同一个 PR 内**同步三处：
  1. `AGENTS.md`（入口/硬约束/DoD，只留指针）；
  2. `skill/wiseadmin-plugin-commands/SKILL.md` 或 `skill/wiseadmin-plugin-manifest/SKILL.md`（细节表）；
  3. 机器契约（`extend/wise/plugin/manifest.schema.json` / `plugin:audit` 已知字段 / 命令 `--help`）。
- 示例代码必须可直接跑；改动示例后至少在 `plugin:create` 生成的骨架上验证一次。
- 本文件与 `skill/` 冲突时，以本文件为准（它是面向 AI 的最终约束）；`skill/` 与代码冲突时，以代码为准。
