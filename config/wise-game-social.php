<?php

declare(strict_types=1);

/**
 * WiseAdmin 社交关系链配置
 *
 * 好友系统、分享码、邀请奖励、平台同步、好友发现
 */
return [

    // 好友系统
    'friend' => [
        'max_friends'         => 500,
        'request_expire_days' => 7,
        'page_size'           => 20,
    ],

    // 分享码
    'share' => [
        'code_length' => 8,
        'default_ttl' => 86400,
        'daily_limit' => 50,
        'max_retry'   => 3,
    ],

    // 邀请奖励
    'invite' => [
        'max_reward_per_inviter' => 100,
        'trigger_events'         => [
            'first_login',
            'first_pass',
            'reach_level_5',
            'reach_level_10',
        ],
    ],

    // 平台同步
    'platform' => [
        'sync_on_score_submit' => true,
        'sync_batch_size'      => 10,
        'platform_adapters'    => [
            // 'wx' => \wise\wx\adapter\WxGameSocialAdapter::class,
            // 'dy' => \plugin\douyin\adapter\DyGameSocialAdapter::class,
        ],
    ],

    // 好友发现
    'discovery' => [
        'search_min_length' => 2,
        'recommend_count'   => 10,
        'import_batch_size' => 50,
    ],

    // 路由开关
    'route_enabled' => true,

    // ================================================================
    //  Web 路由（配置驱动，由 RouteRegistrar 读取）
    // ================================================================

    'web' => [
        // 总开关：false 时本包不注册任何 HTTP 路由
        'enable' => true,
        // 默认路由前缀（分组级 prefix 可覆盖）
        'prefix' => 'api/game/social',
        // 全局默认中间件（所有分组生效）
        'middleware' => [],
        // 分组配置：key 对应 RouteRegistrar::define() 中的 group('key', ...)
        'groups' => [
            'social' => [
                'enable'     => true,
                'middleware' => [],
            ],
        ],
    ],

    // 分享渲染器（可选，游戏插件实现 ShareRendererInterface）
    'share_renderer' => null,
];
