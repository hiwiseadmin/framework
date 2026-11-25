<?php
/**
 * 字段级变更记录（P0-2B）
 *
 * 采集侧在模型里 `use \wise\support\traits\ChangeLoggable;` 并自行实现 onAfterInsert / onAfterWrite 钩子调用采集方法；
 * 本配置控制「采不采」（actors 主体）× 「怎么落地」（黑名单 / 截断 / 队列 / 归档），
 * 不负责登记模型。
 *
 * 口径：**本表是后台管理员的业务操作留痕**。会员自助、系统任务、登录轨迹
 * 不属于审计对象，由 actors 在采集入口直接丢弃（见 ChangeLogService::shouldRecord()）。
 */
return [
    // 总开关（false 时 ChangeLogManager 直接 no-op，模型侧无额外开销）
    'enable' => true,

    // ========================================================================
    // 采集主体白名单 —— 只有这些「操作主体」发起的写入才落库
    // ========================================================================
    //
    // 取值 = 认证 Guard 名 + 三个虚拟主体（大小写不敏感）：
    //   admin   后台管理员（后台 Web 会话 / App JWT / 插件后台，均归此类）
    //   member  会员自助（前台 Web 会话、会员 API JWT —— 由 JwtAuth 写入 Context）
    //   api     其它已认证的 API 主体
    //   cli     CLI / 队列 / 定时任务（`php think xxx`，含 Swoole 无请求协程）
    //   guest   有 HTTP 请求但未认证（匿名注册、找回密码、前台游客触发的写入）
    //
    // 特殊值：
    //   null 或 []  = 不限制（回到初版全量采集，仅排障时临时用）
    //   ['*']       = 同上，显式表达「不做主体判定」
    //
    // ⚠️ 想只关闭「会员自助」而保留系统任务时，不要写成 ['admin', 'cli'] 后就忘了
    //    cli：批量导入/迁移也走 CLI，一并会进表（这正是审计噪音的主要来源）。
    'actors' => ['admin'],

    // 主体被过滤时是否留一条 info 日志（300 秒限流），避免「为什么没记」无从排查
    'log_skipped' => true,

    // 落地模式：queue（默认，异步落库）| sync（同步落库，无 worker 环境/测试用）
    'mode' => env('CHANGE_LOG_MODE', 'queue'),

    // 队列名（null 用默认队列）
    'queue' => null,

    // 队列投递失败时是否降级为同步落库（false 只记错误日志，会丢记录）
    'fallback_sync' => true,

    // 单字段值最大存储字节数（超出截断并打 truncated 标记）
    'max_value_bytes' => 4096,

    // 敏感字段：字段名命中即**整字段不落库**（不是打码，表里查不到任何痕迹）
    // 支持 * 通配符，大小写不敏感
    'sensitive_fields' => [
        'password',
        'passwd',
        'pwd',
        'salt',
        'token',
        '*_token',
        'secret',
        '*_secret',
        'private_key',
        'access_key',
        'api_key',
        'appsecret',
        'remember_token',
    ],

    // 永不记录的字段 —— **基础设施层的最后一道闸**
    //
    // 这里只放 ORM 自己生成、任何模型都无审计价值的字段（时间戳）。
    // 领域噪音（登录轨迹、UI 偏好 JSON 等）属于模型知识，放模型侧
    // changeLogExcept()；字段白名单放 changeLogFields()。
    //
    // 与 trait 的基础排除集重复是**故意的**（纵深防御）：
    //   - trait 那道闸只覆盖模型事件采集；
    //   - 本配置额外兜住手工 `push()`（如回滚留痕 recordRollback）等绕过 trait 的入口。
    'ignore_fields' => [
        'create_time',
        'update_time',
        'delete_time',
    ],

    // 时间线
    'timeline' => [
        // 单次最多返回的变更行数（按 id 倒序取，再按 change_set 分组）
        'max_rows' => 500,
        // diff 折叠上下文行数
        'context' => 3,
        // 单条文本 diff 的行数上限（超出降级为整块替换）
        'max_lines' => 2000,
    ],

    // 归档（配合 `php think change-log:archive` 与 wise-crontab 日任务）
    'archive' => [
        'table' => 'operation_log_changes_archive',
        // 保留天数（0 = 不自动归档）
        'days' => 180,
        'batch' => 5000,
    ],

    // 回滚
    'rollback' => [
        // 只能回滚这些字段之外的？留空表示按记录字段回滚
        'ignore_fields' => ['create_time', 'delete_time'],
    ],
];
