<?php
/**
 * WiseAdmin 微信小游戏 — 默认配置
 *
 * 可通过 config('wise-wx.xxx') 读取所有配置项。
 * 发布后自动复制到项目 config/ 目录，业务可按需覆盖。
 */
return [
    // ================================================================
    // 小程序基本信息
    // ================================================================

    /**
     * 小程序 AppID（必填）
     * 可通过环境变量 WX_APP_ID 覆盖
     */
    'app_id' => env('WX_APP_ID', ''),

    /**
     * 小程序 AppSecret（必填）
     * 可通过环境变量 WX_APP_SECRET 覆盖
     */
    'app_secret' => env('WX_APP_SECRET', ''),

    // ================================================================
    // 微信支付 V3
    // ================================================================

    /**
     * 商户号（必填）
     */
    'mch_id' => env('WX_MCH_ID', ''),

    /**
     * API V3 密钥（必填）
     */
    'api_v3_key' => env('WX_API_V3_KEY', ''),

    /**
     * 商户 API 证书序列号（必填）
     */
    'mch_serial_no' => env('WX_MCH_SERIAL_NO', ''),

    /**
     * 商户 API 私钥文件路径（必填）
     * PEM 格式，相对于项目根目录或绝对路径
     */
    'mch_private_key_path' => env('WX_MCH_PRIVATE_KEY_PATH', ''),

    /**
     * 支付回调通知 URL
     * 微信将向此 URL 推送支付结果
     */
    'pay_notify_url' => env('WX_PAY_NOTIFY_URL', ''),

    /**
     * 退款回调通知 URL
     */
    'refund_notify_url' => env('WX_REFUND_NOTIFY_URL', ''),

    // ================================================================
    // Access Token 管理
    // ================================================================

    /**
     * Access Token Redis 缓存配置
     */
    'token_cache' => [
        /**
         * Redis 缓存键前缀
         */
        'prefix' => 'wx:access_token:',

        /**
         * 分布式刷新锁键前缀
         */
        'lock_prefix' => 'wx:access_token:lock:',

        /**
         * 分布式刷新锁超时时间（秒）
         */
        'lock_ttl' => 10,

        /**
         * 提前刷新阈值（秒）
         * Access Token 剩余有效期小于此值时触发刷新
         */
        'refresh_margin' => 300,
    ],

    // ================================================================
    // 登录模块
    // ================================================================

    'login' => [
        /**
         * 登录接口地址（code2session）
         */
        'code2session_url' => 'https://api.weixin.qq.com/sns/jscode2session',

        /**
         * JWT Token 有效期（秒），默认 2 小时
         */
        'token_ttl' => 7200,

        /**
         * Session Key Redis TTL（秒）
         * 与微信 session_key 有效期同步，默认 2 小时
         */
        'session_key_ttl' => 7200,
    ],

    // ================================================================
    // 支付模块
    // ================================================================

    'pay' => [
        /**
         * V3 JSAPI 下单接口
         */
        'jsapi_order_url' => 'https://api.mch.weixin.qq.com/v3/pay/transactions/jsapi',

        /**
         * 订单查询接口
         */
        'order_query_url' => 'https://api.mch.weixin.qq.com/v3/pay/transactions/out-trade-no/%s',

        /**
         * 退款申请接口
         */
        'refund_url' => 'https://api.mch.weixin.qq.com/v3/refund/domestic/refunds',

        /**
         * 订单超时时间（秒），默认 5 分钟
         */
        'order_timeout' => 300,

        /**
         * 平台证书缓存路径（用于回调验签）
         */
        'platform_cert_cache_path' => '',

        /**
         * 是否启用操作日志审计（需安装 wise-operation-log）
         */
        'audit_enabled' => false,
    ],

    // ================================================================
    // 订阅消息模块
    // ================================================================

    'msg' => [
        /**
         * 发送订阅消息接口
         */
        'send_url' => 'https://api.weixin.qq.com/cgi-bin/message/subscribe/send',

        /**
         * 获取模板列表接口
         */
        'templates_url' => 'https://api.weixin.qq.com/wxaapi/newtmpl/gettemplate',

        /**
         * 消息队列连接
         */
        'queue_connection' => 'redis',

        /**
         * 消息队列名称
         */
        'queue_name' => 'wx_msg',

        /**
         * 频率控制：每用户每模板每日最大发送次数
         */
        'daily_limit' => 10,

        /**
         * 频率控制 Redis 键前缀
         */
        'rate_limit_prefix' => 'wx:msg:rate:',

        /**
         * 重试配置
         */
        'retry' => [
            /**
             * 最大重试次数
             */
            'max_attempts' => 3,

            /**
             * 退避策略（秒）：第 1 次重试 5 秒，第 2 次 30 秒，第 3 次 300 秒
             */
            'backoff' => [5, 30, 300],
        ],
    ],

    // ================================================================
    // 激励视频广告模块
    // ================================================================

    'ad' => [
        /**
         * 广告回调验签 Token
         *
         * 在微信公众平台「开发 - 开发设置 - 消息推送」中配置。
         * 用于广告回调 URL 验证和事件推送签名校验。
         */
        'server_token' => env('WX_AD_SERVER_TOKEN', ''),

        /**
         * 消息加解密密钥（EncodingAESKey）
         *
         * 43 位 Base64 编码字符串。
         * 在微信公众平台「开发 - 开发设置 - 消息推送」中配置。
         * 仅安全模式（加密回调）时需要。
         */
        'encoding_aes_key' => env('WX_AD_ENCODING_AES_KEY', ''),

        /**
         * 最低观看时长（秒）
         *
         * 视频观看时长小于此值时拒绝发放奖励（返回 fail 让微信重试）。
         * 可按广告单元独立配置：ad.units.{ad_unit_id}.min_watch_time
         */
        'min_watch_time' => 15,

        /**
         * 启用的广告单元 ID 白名单
         *
         * 仅在白名单中的广告单元回调才会被处理。
         * 空数组表示不限制（全部放行）。
         *
         * 示例：
         * 'enabled_ad_units' => ['adunit-abc123', 'adunit-def456'],
         */
        'enabled_ad_units' => [],

        /**
         * 奖励处理器完整限定类名（FQCN）
         *
         * 实现 wise\wx\contract\AdRewardHandlerInterface 接口。
         * 由消费方（游戏插件）提供，负责具体的奖励发放逻辑。
         *
         * 示例：'app\\game\\service\\AdRewardHandler'
         */
        'reward_handler' => env('WX_AD_REWARD_HANDLER', ''),

        /**
         * 广告单元独立配置
         *
         * 按广告单元 ID 设置独立参数。
         * 当前支持：min_watch_time（覆盖全局 ad.min_watch_time）
         *
         * 示例：
         * 'units' => [
         *     'adunit-double-reward' => ['min_watch_time' => 30],
         * ],
         */
        'units' => [],
    ],

    // ================================================================
    // 熔断器（Circuit Breaker）
    // ================================================================

    'circuit' => [
        /**
         * 熔断阈值：连续失败多少次后打开断路器
         *
         * 仅统计上游 5xx / 网络超时；4xx 及微信业务 errcode 不计入失败。
         */
        'failure_threshold' => 5,

        /**
         * 熔断后冷却时间（秒）
         *
         * 冷却结束后进入 HALF_OPEN 半开态，允许少量探测请求；
         * 同时作为 HTTP Retry-After 头的值返回给客户端。
         */
        'timeout' => 60,
    ],

    // ================================================================
    // 路由前缀
    // ================================================================

    'route' => [
        'api_prefix' => 'api/wx',
    ],
];
