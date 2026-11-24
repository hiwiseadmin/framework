<?php
/**
 * WiseWorkflow 配置文件
 * 
 * 通过 config('wise-workflow.xxx') 读取配置项
 */
return [
    // 数据库连接（空=默认）
    'connection'  => '',
    // 缓存配置
    'cache'       => [
        'enabled' => true,
        'ttl'     => 3600,
    ],
    // 通知配置
    'notification' => [
        'enabled'   => false,
        'channel'   => 'database',  // database / mail / sms / wechat
        'templates' => [
            'transition' => '工作流 [{workflow}] 状态由 [{from}] 变更为 [{to}]',
            'delegate'   => '工作流 [{workflow}] 已由 [{from_user}] 委托给您处理',
            'sla_alert'  => '工作流 [{workflow}] 在 [{place}] 状态已超时 [{duration}]',
        ],
    ],
    // SLA 配置
    'sla'          => [
        'enabled'         => false,
        'default_timeout' => 86400,     // 默认超时（秒），24小时
        'default_action'  => 'notify',   // 默认超时动作：notify/auto_reject/escalate
        'cron_expression' => '*/5 * * * *', // SLA 检查频率
    ],
    // 乐观锁配置
    'lock'         => [
        'enabled'      => true,
        'max_retries'  => 3,            // 最大重试次数
    ],
    // 版本管理默认策略：strict / migrate / legacy
    'version_policy' => 'strict',

    // 用户 Session Key（历史记录自动记录操作人）
    // 各项目可按自身登录规范配置，例如 'user.id' / 'uid' / 'admin_id'
    'user_session_key' => 'user.id',

    // 事件服务容器绑定标识
    // 各项目可按自身事件系统配置，例如 Laravel 中改为 'events'
    'event_binding'          => 'think\Event',
    'event_fallback_binding' => 'events',

    // ─────────────── Web 路由：显式认证决策（安全审计阶段 0） ───────────────
    // 背景：包的 /api/workflow/* 分组默认以「零中间件」注册（包默认
    //   web.groups.workflow.middleware = []），而 api 应用级流水线按设计不装配认证
    //   （认证由各路由组自行声明）。即：本文件不写 web 节 == 该分组匿名可达。
    //   这里显式声明认证，把生效决策从「无认证」改为「需 JWT」。
    //
    // guard 选择：
    //   'app'   —— api 应用与其余 wise-* 包路由的既有约定（HS256 / JWT_SECRET）
    //   'admin' —— 若该接口实际由后台管理员令牌调用（独立密钥 JWT_ADMIN_SECRET）
    //   两者选错会得到 401（fail-closed、立即暴露），不会静默放行。
    //
    // 若该组暂不应对内或对外提供，直接改成 'enable' => false 关掉整组
    //   （可控的 404，比挂错认证更安全）。
    'web'        => [
        'enable'     => true,
        'prefix'     => 'api/workflow',
        'middleware' => [],
        'groups'     => [
            'workflow' => [
                'enable'     => true,
                'middleware' => [
                    [\wise\jwt\middleware\JwtAuth::class, ['app', 'bearer']],
                ],
            ],
        ],
    ],

    // ───────────────────── V2: Pipeline 管道配置 ─────────────────────

    // 管道工序列表（按执行顺序排列）
    // 支持三种场景管道：default / approval / simple
    'pipelines' => [
        // 默认管道（所有工作流共享，12 步标准流程）
        'default' => [
            wise\workflow\pipes\FindTransitionPipe::class,
            wise\workflow\pipes\CheckPendingTasksPipe::class,
            wise\workflow\pipes\LoadMarkingPipe::class,
            wise\workflow\pipes\VerifyVersionPipe::class,
            wise\workflow\pipes\DispatchBeforeEventPipe::class,
            wise\workflow\pipes\CheckPermissionPipe::class,
            wise\workflow\pipes\EvaluateGuardsPipe::class,
            wise\workflow\pipes\ValidateFromPlacesPipe::class,
            wise\workflow\pipes\TransitionStatePipe::class,
            wise\workflow\pipes\OptimisticLockSavePipe::class,
            wise\workflow\pipes\RecordHistoryPipe::class,
            wise\workflow\pipes\DispatchAfterEventsPipe::class,
        ],

        // 审批类工作流（保留，与 default 相同，预留 SLA 扩展点）
        'approval' => null,  // null = 继承 default

        // 精简管道（无并行审批检查、无事件、无权限——适合简单状态机）
        'simple' => [
            wise\workflow\pipes\FindTransitionPipe::class,
            wise\workflow\pipes\LoadMarkingPipe::class,
            wise\workflow\pipes\ValidateFromPlacesPipe::class,
            wise\workflow\pipes\TransitionStatePipe::class,
            wise\workflow\pipes\OptimisticLockSavePipe::class,
            wise\workflow\pipes\RecordHistoryPipe::class,
        ],
    ],

    // 命名插槽（供插件注册自定义 Pipe）
    // 插槽名规则：{before|after}_{PipeBaseName}
    // 例：'after_VerifyVersion' = 在 VerifyVersionPipe 之后执行
    'pipe_slots' => [
        'before_LoadMarking'       => [],  // 状态加载前
        'after_VerifyVersion'      => [],  // 版本校验后
        'before_CheckPermission'   => [],  // 权限检查前
        'after_CheckPermission'    => [],  // 权限检查后
        'before_EvaluateGuards'    => [],  // 守卫检查前
        'after_EvaluateGuards'     => [],  // 守卫检查后
        'before_TransitionState'   => [],  // 状态转换前
        'after_TransitionState'    => [],  // 状态转换后
        'before_OptimisticLockSave'=> [],  // 保存前
        'after_OptimisticLockSave' => [],  // 保存后
        'before_RecordHistory'     => [],  // 历史记录前
        'after_DispatchAfterEvents'=> [],  // 事件触发后
    ],
];
