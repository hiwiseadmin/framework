<?php
declare(strict_types=1);

/**
 * WiseAdmin Login Security 配置文件
 *
 * 发布命令：php think vendor:publish（之后可手动调整）
 * 读取方式：config('wise-login-security.captcha.driver') 等
 */

return [
    // ========================================================================
    // ⚠️ 当前版本【尚未实现】的配置项（预留，改动不会生效）：
    //   - guard_pipeline / result_pipeline   —— 管道顺序由中间件代码决定
    //   - two_factor.required_roles          —— 按角色强制 2FA
    //   - device_management.auto_register    —— 设备登记由 login_result.actions.device_record 控制
    //   - events.retention_days              —— 事件自动清理无调度入口
    //   - events.types                       —— 事件类型过滤未实现
    //   - middleware.login_guard.check_two_factor / record_device
    //                                        —— 2FA 由 TwoFactorMiddleware 负责，设备记录由 result_pipeline 负责
    // 需要启用时请先确认对应能力已实现，避免"改了配置但无任何效果"。
    // ========================================================================

    // ============ 验证码 ============
    'captcha' => [
        // 全局验证码开关（false = 前端不渲染验证码区域、后端不校验验证码）
        'enabled' => false,
        // 驱动：gd（GD 库图片验证码）| geetest（行为验证码，配置由用户实现 BehaviorCaptchaDriver）
        'driver'      => 'gd',

        // 验证码字符长度
        'length'      => 4,

        // 字符集：digits 数字 / letters 字母 / mix 数字字母混合
        'charset'     => 'mix',

        // 验证码有效期（秒）
        'expire'      => 300,

        // 图片宽度（px）
        'width'       => 130,

        // 图片高度（px）
        'height'      => 50,

        // 字体大小（使用 TrueType 字体时实际生效；无 TrueType 字体时自动限制为 1-5）
        'font_size'   => 20,

        // TrueType 字体文件路径（可选，不设则自动检测系统常见字体。如 Arial、DejaVuSans 等）
        'font_path'   => '',

        // 干扰点数量
        'noise_dots'  => 80,

        // 干扰线数量
        'noise_lines' => 3,

        // 文字扭曲（0-1）
        'distortion'  => 0.3,

        // 缓存前缀
        'cache_prefix' => 'wise_login_security:captcha:',

        // CAPTCHA 生成频率限制（防止资源耗尽攻击）
        'rate_limit' => [
            // 是否启用频率限制
            'enabled'              => true,

            // 单 IP 每分钟最大生成次数
            'max_per_ip_minute'    => 30,

            // 每会话每分钟最大生成次数（无 IP 降级）
            'max_per_session_minute' => 10,

            // 全局每分钟最大生成次数（兜底，0=不限制）
            'max_global_per_minute' => 500,

            // 频率限制缓存前缀
            'cache_prefix'         => 'wise_login_security:captcha_rate:',
        ],

        // 第三方行为验证码（geetest 等）的配置占位
        'geetest'     => [
            'id'         => '',
            'key'        => '',
            'api_server' => 'https://gcaptcha4.geetest.com/validate',
        ],

        // P1-#1：腾讯云验证码配置
        'tencent'     => [
            'app_id'     => '',
            'secret_key' => '',
            'api_server' => 'https://ssl.captcha.qq.com/ticket/verify',
        ],

        // P1-#1：hCaptcha 国际验证码配置
        'hcaptcha'    => [
            'site_key'   => '',
            'secret_key' => '',
            'api_server' => 'https://api.hcaptcha.com/siteverify',
        ],
    ],

    // ============ 登录失败次数限制 ============
    'failed_attempts' => [
        // 最大失败次数
        'max_attempts'    => 5,

        // 锁定时长（分钟），0 表示不自动解锁（需管理员手动解锁）
        'lockout_minutes' => 15,

        // 计数器存储：cache（缓存）| redis（Redis）| database（数据库）
        'store'           => 'database',

        // 统计维度：ip（按 IP）| username（按用户名）| ip_username（按 IP+用户名组合）
        'scope'           => 'ip_username',

        // 缓存前缀
        'cache_prefix'    => 'wise_login_security:failed:',

        // 失败计数衰减窗口（分钟），窗口外的失败计数自动重置
        'decay_minutes'   => 60,

        // 渐进式锁定时长（P0-#5）
        // 启用后 lockout_minutes 作为首次锁定基础值，后续锁定逐级递增
        'progressive_lockout' => [
            // 是否启用渐进式锁定
            'enabled'              => false,

            // 锁定级别（分钟），索引0=第1次锁定，依次递进
            'levels'               => [1, 5, 15, 30, 60, 1440],

            // 成功登录后是否重置锁定级别
            'reset_after_success'   => true,

            // 锁定历史保留天数（超过后自动归零重新计算）
            'history_ttl_days'     => 7,
        ],
    ],

    // ============ IP 黑白名单 ============
    'ip_list' => [
        // 工作模式（显式控制，避免隐式行为）：
        //   'blacklist' - 仅检查黑名单，白名单不生效（默认，安全）
        //   'whitelist' - 仅检查白名单，只允许列表中的 IP 访问
        //   'both'      - 白名单优先：白名单 IP 直接放行，其余走黑名单检查
        'mode' => 'blacklist',

        // 白名单（mode=whitelist 或 mode=both 时生效）
        'whitelist' => [
            '127.0.0.1',
            '::1',
        ],

        // 黑名单（mode=blacklist 或 mode=both 时生效）
        'blacklist' => [],

        // 列表提供器：config（从本配置）| database（从数据库动态加载，P1-#10 已实现）
        'provider'           => 'config',

        // database 提供器缓存时间（秒，P1-#10）
        'provider_cache_ttl' => 300,

        // 是否在反向代理环境下信任 X-Forwarded-For。
        // 注意：仅当 REMOTE_ADDR 命中下方 trusted_proxies 时才真正采信代理头；
        // 未配置 trusted_proxies 时该开关不会生效（fail-closed），
        // 否则客户端可伪造 X-Forwarded-For 绕过 IP 黑名单与失败锁定。
        'trust_proxy_header' => true,

        // 已识别代理服务器 IP（CIDR 格式）。
        // 反向代理/网关部署时必须填写，例如本机 Nginx：['127.0.0.1/32', '::1/128']；
        // 留空表示"没有可信代理"，将忽略 X-Forwarded-For 并直接使用 REMOTE_ADDR。
        'trusted_proxies'    => [],
    ],

    // ============ 双因素认证（TOTP）============
    'two_factor' => [
        // 全局开关
        'enabled'  => true,

        // 默认多态用户类型（HasTotp Trait 和 TotpConfig 表使用）
        'user_type_default' => 'admin',

        // 发行者（显示在 Google Authenticator 中）
        'issuer'   => 'WiseAdmin',

        // 码位数（通常为 6）
        'digits'   => 6,

        // 时间步长（秒，RFC 6238 默认 30）
        'period'   => 30,

        // HMAC 算法：sha1 | sha256 | sha512
        'algorithm' => 'sha1',

        // 密钥长度（字节，Base32 编码后约为 1.6 倍）
        'secret_length' => 20,

        // 备份码数量
        'backup_codes_count' => 10,

        // 是否跳过已信任设备的 TOTP
        'skip_for_trusted_device' => true,

        // 强制要求 2FA 的角色 ID 列表（空 = 所有已启用 2FA 的用户都需验证）
        'required_roles' => [],

        // 备份码速率限制（P0-#16）
        // 防止备份码被暴力破解，内置冷却机制
        'backup_codes_rate_limit' => [
            // 是否启用
            'enabled'            => true,

            // 时间窗口内最大尝试验证次数
            'max_attempts'       => 3,

            // 时间窗口（分钟）
            'window_minutes'     => 5,

            // 触发限制后的冷却时间（分钟）
            'cooldown_minutes'   => 30,

            // 缓存前缀
            'cache_prefix'       => 'wise_login_security:backup_rate:',
        ],

        // P1-#14：邮件双因素认证
        'email' => [
            'enabled'         => true,   // 需安装 wise-notification + SMTP 配置
            'code_ttl'        => 300,    // 验证码有效期（秒）
            'code_length'     => 6,      // 验证码位数
            'max_attempts'    => 3,      // 最大尝试次数
            'resend_cooldown' => 60,     // 重发冷却（秒）
            'cache_prefix'    => 'wise_login_security:2fa_email:',
        ],

        // P1-#14：SMS 双因素认证
        'sms'  => [
            'enabled'         => false,  // 需安装 wise-notification + 短信 SDK 后启用
            'code_ttl'        => 300,
            'code_length'     => 6,
            'max_attempts'    => 3,
            'resend_cooldown' => 60,
            'template_code'   => 'verify_code',
            'cache_prefix'    => 'wise_login_security:2fa_sms:',
        ],

        // P2-#17：恢复码配置
        'recovery' => [
            'count'       => 8,   // 恢复码数量
            'code_length' => 16,  // 恢复码长度（16位 = 4×4格式）
        ],
    ],

    // ============ 设备管理 ============
    'device_management' => [
        // 设备信任有效期（天）
        'trust_duration_days' => 30,

        // 已信任设备是否跳过 TOTP
        'skip_totp_for_trusted_device' => true,

        // 设备指纹盐值（增强安全性，请修改为随机字符串）
        'fingerprint_salt'    => env('WISE_LOGIN_FINGERPRINT_SALT', 'change-me-to-random-string'),

        // User-Agent / IP 解析顺序
        'trust_proxy_header'  => true,

        // 是否在登录时自动注册新设备
        'auto_register'       => true,

        // 是否在新设备登录时记录安全事件（可由监听器发邮件）
        'notify_new_device'        => true,

        // P2-#18："记住此设备"功能
        'remember_device_enabled'  => true,                // 启用用户可选的"记住此设备"
        'remember_cookie_name'     => 'ws_remember_2fa',   // Cookie 名称
    ],

    // ============ P2-#21：登录异常检测 ============
    'anomaly_detection' => [
        // 是否启用
        'enabled' => true,

        // 缓存前缀
        'cache_prefix' => 'wise_login_security:anomaly:',

        // 规则1：异地登录检测
        'geo_anomaly' => [
            'enabled'           => true,
            'window_minutes'    => 60,   // 检测窗口（分钟）
            'ip_count_threshold' => 2,   // 不同 IP 数量触发阈值
        ],

        // 规则2：新设备+新IP 检测
        'new_device' => [
            'enabled'       => true,
            'lookback_days' => 7,       // 历史回溯天数
        ],

        // 规则3：异常时段检测
        'time_anomaly' => [
            'enabled'    => true,
            'start_hour' => 1,          // 异常时段起始（0-23）
            'end_hour'   => 5,          // 异常时段结束（0-23）
        ],

        // 规则4：频率异常检测
        'frequency_anomaly' => [
            'enabled'         => true,
            'window_minutes'  => 10,    // 检测窗口（分钟）
            'login_threshold' => 5,     // 登录次数阈值
        ],
    ],

    // ============ 中间件配置 ============
    'middleware' => [
        // 统一登录守卫中间件
        'login_guard' => [
            // 验证码启用开关
            'enabled' => true,

            // 失败达到此次数后强制要求验证码
            'require_captcha_after_failures' => 2,

            // 是否在守卫中检查 IP 黑白名单
            'check_ip_list' => true,

            // 是否在守卫中检查失败锁定
            'check_lock' => true,

            // 是否在守卫中检查 TOTP
            'check_two_factor' => true,

            // 是否在守卫中记录设备
            'record_device' => true,
        ],

        // 独立 IP 守卫中间件
        'ip_guard' => [
            'enabled' => true,
        ],

        // 独立 TOTP 中间件
        'two_factor' => [
            'enabled' => true,
        ],
    ],

    // ============ V2: Pipeline 管道配置（⚠️ 预留，当前版本尚未读取）============
    // 实际守卫顺序由 LoginGuardMiddleware::resolveGuardActions() 决定
    // （IP → 锁定 → 验证码 + 插件注册项）；改动下列顺序不会生效。
    'guard_pipeline' => [
        // 默认守卫顺序：IP → 锁定 → 验证码
        'default' => [
            \wise\loginsecurity\action\IpGuardAction::class,
            \wise\loginsecurity\action\LockCheckAction::class,
            \wise\loginsecurity\action\CaptchaCheckAction::class,
        ],
        // 精简模式：仅 IP + 锁定（如内部系统无需验证码）
        'simple' => [
            \wise\loginsecurity\action\IpGuardAction::class,
            \wise\loginsecurity\action\LockCheckAction::class,
        ],
    ],

    // 登录结果管道（后置处理，按顺序执行；⚠️ 预留，当前版本尚未读取）
    // 实际顺序由 LoginResultMiddleware::resolveResultActions() 决定。
    'result_pipeline' => [
        'default' => [
            \wise\loginsecurity\action\FailureRecordAction::class,
            \wise\loginsecurity\action\SuccessRecordAction::class,
            \wise\loginsecurity\action\DeviceRecordAction::class,
            \wise\loginsecurity\action\AnomalyDetectAction::class,
        ],
    ],

    // ============ 路由配置 ============
    'route' => [
        // 是否自动注册验证码 HTTP 路由（Service::register() 中 loadRoutesFrom）
        'auto_register' => true,

        // 路由前缀（自动注册时使用）
        'prefix' => 'wise/captcha',
    ],

    // ============ 登录结果自动记录（LoginResultMiddleware）============
    'login_result' => [
        // 中间件开关
        'enabled' => true,

        // 响应字段映射
        'response' => [
            // 表示成功/失败的字段名
            'status_field'  => 'code',

            // 成功的值（与 status_field 对比）
            'success_value' => 0,

            // 成功时获取用户 ID 的方式：
            //   字符串模式：dot notation 字段路径，直接从响应体提取
            //   callable 模式：[ClassName::class, 'method']，回调接收 ($body, $request): ?int
            //     避免在响应中直接暴露真实 ID，可从 token 等中间值解析
            'user_id_field' => 'data.user_id',
        ],

        // 请求中用户名的字段名
        'username_input' => 'username',

        // 自动记录动作开关
        'actions' => [
            // 失败 → Lock::recordFailure()
            'failure_lock'   => true,

            // 失败 → Logger::log(LOGIN_FAILURE)
            'failure_event'  => true,

            // P2-#7：锁定触发 → Logger::log(ACCOUNT_LOCKED) + 邮件通知
            'lock_notify'    => true,

            // 成功 → Lock::clear()
            'success_clear'  => true,

            // 成功 → Logger::log(LOGIN_SUCCESS)
            'success_event'  => true,

            // 成功 → DeviceManager::record()
            'device_record'  => true,

            // 成功 → 标记 TOTP 已验证（跳过 2FA 中间件）
            'totp_verified'  => true,

            // P2-#21：成功 → 登录异常检测
            'anomaly_detect' => true,
        ],
    ],

    // ============ 安全事件记录 ============
    'events' => [
        // 是否记录到数据库
        'log_to_database' => true,

        // 是否同时输出到日志
        'log_to_file'     => true,

        // 日志通道
        'log_channel'     => 'login_security',

        // 保留天数（0 = 永久），自动清理通过 think-login-security:init 后的清理命令
        'retention_days'  => 90,

        // 事件类型（可由监听器监听）
        'types' => [
            'login_failure',
            'login_success',
            'account_locked',
            'account_unlocked',
            'ip_blacklisted',
            'captcha_failed',
            'totp_verified',
            'totp_failed',
            'device_trusted',
            'device_new',
            'device_revoked',
        ],

        // 邮件通知按用户反查邮箱所需的用户模型（不配置则无法发送任何通知邮件）
        // 例如：\app\admin\model\Admin::class
        'user_model'     => \app\admin\model\Admin::class,

        // 用户名对应字段（事件只带 username 时按此字段反查用户）
        'username_field' => 'username',

        // P1-#25：新设备登录邮件提醒（NewDeviceMailListener）
        'new_device_email' => [
            'enabled'      => true,
            'email_field'  => 'email',
            'subject'      => '新设备登录提醒 - {app_name}',
            'app_name'     => '',
        ],

        // P2-#7：账户锁定邮件通知（LockedAccountMailListener）
        'lock_notify' => [
            'enabled'      => true,
            'email_field'  => 'email',
            'subject'      => '账户安全提醒 - {app_name}',
            'app_name'     => '',
        ],
    ],

    // ============ CSRF 防护（P0-#29）============
    'csrf' => [
        // 是否启用 CSRF 中间件
        'enabled'     => true,

        // CSRF Token 的 Header 名称
        'header_name' => 'X-CSRF-Token',

        // CSRF Token 的 Body 字段名称
        'field_name'  => '_csrf_token',

        // 排除的路径（支持通配符 *）
        // 例如：['api/callback/*', 'webhook/*']
        'except'      => [],
    ],
];
