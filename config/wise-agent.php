<?php
declare(strict_types=1);

return [
    // =========================================================================
    // 默认 Provider
    // =========================================================================
    'default' => env('AI_DEFAULT_PROVIDER', 'deepseek'),

    // AI 前端入口版本（用于 /statics/plugins/wise-agent/js/agent-chat.js?v= 缓存失效）
    // 该版本号同时透传给同目录的 agent-chat.css（该 CSS 由 JS 动态注入，不经过
    // 框架的 HTML 资源版本化），前端有改动时必须递增，否则会命中旧缓存
    'version' => '1.7.0',

    // =========================================================================
    // LLM Provider 配置
    // =========================================================================
    'providers' => [
        'openai' => [
            'class'      => \wise\agent\client\OpenAiClient::class,
            'api_key'    => env('OPENAI_API_KEY', ''),
            'base_url'   => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model'      => env('OPENAI_MODEL', 'gpt-4o'),
            'timeout'    => 60,
            'max_retry'  => 2,
        ],
        'deepseek' => [
            'class'      => \wise\agent\client\DeepSeekClient::class,
            'api_key'    => env('DEEPSEEK_API_KEY', ''),
            'base_url'   => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'model'      => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'timeout'    => 60,
            'max_retry'  => 2,
        ],
    ],

    // =========================================================================
    // SSE (Server-Sent Events) 配置 — TODO: 待 SSE 功能实现后启用以下配置
    // =========================================================================
    // 'sse' => [
    //     'timeout'          => 300,
    //     'heartbeat_interval' => 15,
    //     'retry'            => 3000,
    //     'stream_tool_calls' => true,
    //     'buffer_size'      => 1,
    // ],

    // =========================================================================
    // Agent 默认配置
    // =========================================================================
    'agent' => [
        'max_steps'           => 10,
        'max_tokens_per_step' => 4096,
        'temperature'         => 0.7,
        'default_type'        => 'simple',
        'system_prompt'       => 'You are the WiseAdmin AI assistant. You have access to tools to accomplish tasks. Always respond in the same language as the user. When you have enough information, provide the final answer directly. Format the answer in Markdown when it improves readability: fenced code blocks with a language tag, lists and tables.',
        'enable_memory'       => true,
        'session_ttl'         => 86400,
    ],

    // =========================================================================
    // 记忆配置
    // =========================================================================
    'memory' => [
        'default' => 'database',
        'stores'  => [
            'database' => [
                'class' => \wise\agent\memory\DatabaseMemory::class,
            ],
            'redis' => [
                'class'  => \wise\agent\memory\RedisMemory::class,
                'prefix' => 'wai:mem:',
            ],
        ],
    ],

    // =========================================================================
    // 内置工具配置
    // =========================================================================
    'tools' => [
        'file_read'        => ['class' => \wise\agent\tool\builtin\FileReadTool::class,         'enabled' => false],
        'file_write'       => ['class' => \wise\agent\tool\builtin\FileWriteTool::class,        'enabled' => false],
        'db_query'         => ['class' => \wise\agent\tool\builtin\DatabaseQueryTool::class,     'enabled' => false],
        'http_request'     => ['class' => \wise\agent\tool\builtin\HttpRequestTool::class,      'enabled' => false],
        'orchestrate_step' => ['class' => \wise\agent\tool\builtin\OrchestrateStepTool::class,  'enabled' => true],
    ],

    // =========================================================================
    // Skill 技能配置
    // =========================================================================
    'skills' => [
        // 是否启用 Skill 系统
        'enabled' => true,

        // SKILL.md 文件目录（包内置 skills/ 目录，可覆盖）
        // 设为空字符串则仅从 extra_dirs 加载
        'dir' => '',

        // 额外的 SKILL.md 扫描目录（插件 skills 目录）
        // 示例: [app()->getRootPath() . 'plugin/cms/skills']
        'extra_dirs' => [],

        // 内置 Skill 类名映射（PHP 类方式，与 SKILL.md 互补）
        'builtin' => [
            // 内容类
            'content.title_generate'     => \wise\agent\skill\builtin\TitleGenerateSkill::class,
            'content.article_generate'   => \wise\agent\skill\builtin\ArticleGenerateSkill::class,
            'content.rewrite_humanize'   => \wise\agent\skill\builtin\RewriteHumanizeSkill::class,
            'content.summarize'          => \wise\agent\skill\builtin\SummarizeSkill::class,
            'content.translate'          => \wise\agent\skill\builtin\TranslateSkill::class,
            'content.seo_optimize'       => \wise\agent\skill\builtin\SeoOptimizeSkill::class,
            // 数据类
            'data.analyze'               => \wise\agent\skill\builtin\DataAnalyzeSkill::class,
            'data.report_generate'       => \wise\agent\skill\builtin\ReportGenerateSkill::class,
            'data.trend_predict'         => \wise\agent\skill\builtin\TrendPredictSkill::class,
            'data.anomaly_detect'        => \wise\agent\skill\builtin\AnomalyDetectSkill::class,
            // 系统类
            'system.orchestrate'         => \wise\agent\skill\builtin\OrchestrateSkill::class,
        ],

        // Skill 执行默认配置
        'defaults' => [
            'temperature'   => 0.7,
            'max_tokens'    => 4096,
            'max_steps'     => 5,   // Skill 通常比 Agent 循环少
            'enable_memory' => false,
        ],
    ],

    // =========================================================================
    // 上下文注入配置
    // =========================================================================
    'context' => [
        // 是否启用上下文注入
        'enabled' => true,

        // 内置上下文注入器
        'builtin' => [
            \wise\agent\context\builtin\SystemContextProvider::class => true,
            \wise\agent\context\builtin\UserContextProvider::class  => true,
        ],
    ],

    // =========================================================================
    // 流水线（Pipeline）配置
    // =========================================================================
    'pipeline' => [
        // 是否启用 Pipeline 系统
        'enabled' => true,

        // 最大执行时间（秒）
        'max_duration' => 600,

        // 默认重试次数
        'default_retries' => 0,

        // 预定义流水线（声明式）
        'definitions' => [
            // 示例：CMS 自动发布流水线
            // 'cms.auto_publish' => [
            //     'description' => '从主题到发布的全自动流水线',
            //     'triggers' => ['manual', 'schedule'],
            //     'source' => 'plugin:cms',
            //     'steps' => [
            //         ['skill' => 'content.title_generate', 'input_mapping' => ['topic' => '$input.topic', 'count' => 5], 'output_key' => 'titles'],
            //         ['skill' => 'content.article_generate', 'input_mapping' => ['title' => '$input.selected_title'], 'output_key' => 'article'],
            //         ['skill' => 'content.rewrite_humanize', 'input_mapping' => ['content' => '$steps.article'], 'output_key' => 'final'],
            //     ],
            // ],
        ],
    ],

    // =========================================================================
    // 调度配置
    // =========================================================================
    'schedule' => [
        'enabled' => true,

        // 预定义定时任务
        'tasks' => [],
    ],

    // =========================================================================
    // 反馈配置
    // =========================================================================
    'feedback' => [
        'enabled' => true,

        // 低分阈值（低于此值触发预警）
        'low_rating_threshold' => 3.5,

        // 最少反馈数才参与统计
        'min_count' => 5,
    ],

    // =========================================================================
    // Schema 自动发现配置（Phase 1-4）
    // =========================================================================
    'schema' => [
        // 是否启用 Schema 自动发现
        // 启用后，安装插件时自动：
        //   1. 内省插件数据模型
        //   2. 生成标准 CRUD Tool（list/get/search/stats/create/update/delete/list_with_relation）
        //   3. 解析业务能力标签（inventory/sales/crm/content）
        //   4. 注入领域知识 Context
        'auto_discover' => true,

        // Schema 缓存目录（相对于 runtime 路径）
        'cache_dir' => 'wise-agent/schema',

        // Schema 缓存 TTL（秒），默认 1 小时
        'cache_ttl' => 3600,

        // 默认的安全配置（全局，可被插件 manifest 覆盖）
        'defaults' => [
            // 写操作默认需要确认
            'write_requires_confirmation' => true,

            // 自动排除敏感字段（password/token/secret 等）
            'auto_exclude_sensitive' => true,

            // 自动排除系统字段（id/create_time/update_time 等）
            'auto_exclude_system' => true,

            // 自动探测 owner_key（user_id/created_by/operator_id 等归属字段）
            'auto_detect_owner_key' => true,

            // 自动应用行级 owner 隔离（读操作仅返回当前操作者数据，
            // 超管不受限；创建时自动写入归属人）
            'auto_owner_scope' => true,
        ],

        // 第三方能力注册（可扩展）
        // 内置能力（始终可用）：inventory, sales, crm, content
        'capabilities' => [
            'ecommerce' => \wise\agent\schema\capability\examples\EcommerceCapability::class,
            // 'finance'   => \app\ai\capability\FinanceCapability::class,
            // 'workflow'  => \app\ai\capability\WorkflowCapability::class,
        ],
    ],

    // =========================================================================
    // 日志配置
    // =========================================================================
    'log' => [
        'enabled' => true,
        'channel' => 'ai',
    ],

    // =========================================================================
    // 队列配置（异步 Agent 使用）
    // =========================================================================
    'queue' => [
        'connection'   => 'database',
        'job'          => \wise\agent\job\AiAgentJob::class,
        'pipeline_job' => \wise\agent\job\PipelineJob::class,
    ],

    // =========================================================================
    // 安全配置
    // =========================================================================
    'security' => [
        'max_tool_executions_per_step' => 5,
        'max_tool_execution_time'      => 30,   // 单工具最大执行秒数
        'max_agent_duration'          => 300,
        'max_pipeline_step_duration'  => 120,  // Pipeline 单步最大执行秒数

        // ---------------------------------------------------------------------
        // AI 能力权限校验（工具 + 技能）
        // ---------------------------------------------------------------------

        // 工具权限校验总开关。关闭后所有 AI 工具不再做权限校验（应急用，不建议生产关闭）
        'tool_permission_check' => true,

        // 技能权限校验总开关。技能是编排入口，关闭等于开放任意技能执行
        'skill_permission_check' => true,

        // 控制台 / 队列等无 HTTP 请求的上下文是否视为可信系统身份（放行并记日志）。
        // 定时任务、pipeline、队列消费依赖此开关；HTTP 匿名请求不适用。
        'trust_console_context' => true,

        // 声明式授权策略（由 wise\agent\listener\CapabilityAuthorizePolicy 读取，工具与技能共用）
        // 命中 deny  → 硬拒绝，且不再走 RBAC 兜底
        // 命中 allow → 直接放行（无需给角色分配权限）
        // 均未命中   → 回落 wise-auth RBAC（超级管理员直通）
        'tool_authorize' => [
            // 允许清单：'*' | 能力名(orchestrate_step) | 权限标识(ai.tool.db_query)
            //          | 前缀通配(ai.skill.*) | 访问类别(access:read / access:write)
            'allow' => [],
            // 拒绝清单：优先级高于 allow 与 RBAC
            'deny'  => [],
            // 未登录会话是否放行（默认 false，仅建议可信内网 / 调试环境开启）
            'allow_anonymous' => false,
        ],

        // AI 能力权限注册（写入 wise-auth 权限表，供后台按角色可视化勾选）
        // 同步入口：php think wise:agent:permissions / 后台「权限配置 → 更新权限列表」
        // 权限树：AI 能力 → [全部 AI 能力 | 只读能力 | 写入能力 | 编排执行 | 管理能力]
        'tool_permissions' => [
            // 权限树分组名称
            'group_name' => 'AI 能力',
            // 是否生成「全部 AI 能力」通配 ai.capability.*（一键全开）
            'include_all_wildcard' => true,
            // 是否生成类别通配 ai.access.{read|write|execute|admin}.*（日常勾选单元）
            'include_access_wildcard' => true,
            // 是否生成汇总标识 ai.tool.* / ai.skill.* / plugin:{ident}.auto.*
            'include_aggregate_wildcard' => true,
            // 是否注册技能权限 ai.skill.{name}
            'include_skills' => true,
            // 细粒度层级：aggregate = 自动生成工具按「插件 × 操作类型」聚合（推荐）
            //             full      = 精确到「插件 × 操作类型 × 表名」
            'detail_level' => 'aggregate',
            // 声明式补充（插件 / 业务方可只写配置）：
            // 'plugin:demo.auto.*' => ['name' => '演示插件 AI 工具', 'access' => 'write'],
            'items' => [],
        ],

        // 审批策略（与权限解耦：权限决定「能不能调用」，审批决定「本次是否需要人工介入」）
        //
        // 规则优先级（首个命中生效）：
        //   1. rules.capabilities  按能力标识（精确 / 前缀通配），用于例外
        //   2. rules.risk          按风险等级 low | medium | high
        //   3. rules.access        按访问类型 read | write | execute | admin
        //   4. default             兜底
        //
        // 模式：none（直接执行）| confirm（需人工确认）| workflow（需提交审批）
        // 说明：能力自身 requireConfirmation()=true 视为下限，策略只能加严不能放宽。
        //      命中 confirm / workflow 时会生成审批单（ws_ai_approvals）并安全阻断；
        //      批准后申请人重新发起同一调用，命中审批单并原子消费一次后放行（不重放历史调用）。
        'approval' => [
            'enabled' => true,
            'default' => 'none',
            'rules'   => [
                // 高危能力一律需要确认（如 delete / 出库 / 外部系统调用）
                'risk'         => ['high' => 'confirm'],
                // 管理类能力默认需要确认
                'access'       => ['admin' => 'confirm'],
                // 例外示例：
                // 'plugin:*.auto.delete.*' => 'workflow',
                // 'ai.tool.db_query'       => 'confirm',
                'capabilities' => [],
            ],

            // 审批单参数
            'request' => [
                // 有效期（秒）；0 = 永不过期
                'ttl' => 900,
                // 一张审批单可放行的调用次数
                'max_uses' => 1,
                // 匹配范围：capability = 申请人 + 能力即可；arguments = 还需调用参数完全一致
                'scope' => 'capability',
                // 被驳回后的冷静期（秒），期内不允许重复申请；0 = 关闭
                'reject_cooldown' => 300,
            ],

            // 审批单生成后需要通知的管理员用户 ID（需安装 wise-notification；为空则不发通知）
            // 解析优先级：notify_user_ids → workflow.approvers → 拥有
            // admin.ai_approval.approve 权限的用户 → 超级管理员（super_admin_ids）
            'notify_user_ids' => [],

            // wise-workflow 集成（可选，未安装时审批单表自身即状态机，功能不受影响）
            'workflow' => [
                // 工作流实例开关。开启后每张审批单建立 ai_approval:{id} 实例，
                // 可用 Workflow::getHistory() 审计流转，并在审批详情页展示
                'enabled' => true,
                // 会签审批人（用户 ID）。为空 = 单人审批（谁有审批权限谁可处理）
                'approvers' => [],
                // 会签策略：any=或签（任一人通过即流转）| all=会签（全部通过才流转）
                'policy' => 'any',
            ],
        ],
    ],

    // =========================================================================
    // Web 路由配置（AI 聊天 / 配置管理 HTTP 接口）
    // 由 wise\agent\RouteRegistrar 消费；总开关关闭时整包不注册任何 HTTP 路由。
    //
    // 路由由包内自注册（不再在 app/admin/route/admin.php 中硬编码）：
    // 前缀 'admin/ai' 首段被基类 RouteRegistrar 识别为 admin 应用，
    // 自动延迟投递到 ExtPoints::ROUTE_ADMIN，由 app/admin/route/packages.php
    // 的 applyRoutes() 在 admin 应用内以相对前缀 'ai' 落地（/admin/ai/*），
    // 因此天然继承 admin 应用全局中间件链（IP 守卫 / RBAC / 2FA / CSRF / 审计等）。
    // 卸载 wise-agent 后这些路由随包一起消失，后台不会残留无效入口。
    // =========================================================================
    'web' => [
        'enable'     => true,
        'prefix'     => 'admin/ai',
        'middleware' => [],
        'groups' => [
            'admin' => [
                'enable'     => true,
                'middleware' => [],
            ],
            // 审批中心页（/admin/ai_approval/*，包内独立后台页）
            'admin_approval' => [
                'enable'     => true,
                'prefix'     => 'admin/ai_approval',
                'middleware' => [],
            ],
        ],
    ],

    // =========================================================================
    // MCP (Model Context Protocol) Server 配置
    // =========================================================================
    'mcp' => [
        // 总开关：是否启用 MCP HTTP 端点（/admin/ai/mcp）
        // 该端点会暴露所有 AI 工具（含写操作），生产环境启用前务必挂载认证中间件
        'enable' => false,

        // 是否启用 stdio 命令（php think wise:agent:mcp）
        'stdio' => true,
    ],

];
