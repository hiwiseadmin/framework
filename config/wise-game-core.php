<?php

/**
 * WiseAdmin 小游戏核心能力 — 默认配置
 *
 * 可通过宿主的 config/wise-game-core.php 覆盖任意项。
 */
return [
    // ================================================================
    //  Redis 连接配置
    // ================================================================

    /**
     * Redis 连接标识（对应 config/cache.php 中 stores.redis 的配置）
     * 或 database.php 中 redis 连接名
     */
    'redis_connection' => 'default',

    // ================================================================
    //  排行榜 (Leaderboard) 配置
    // ================================================================

    'leaderboard' => [
        /**
         * Redis 键前缀
         * 最终键名格式: game:board:{board_key}
         */
        'key_prefix' => 'game:board:',

        /**
         * 顶端 N 名缓存 TTL（秒）
         * 避免高频 ZREVRANGE 查询
         */
        'top_n_cache_ttl' => 60,

        /**
         * 顶端缓存数量
         */
        'top_n_count' => 100,

        /**
         * 相邻排名默认查询范围（前后各 N 名）
         */
        'neighbor_range' => 10,

        /**
         * 好友榜最大好友数量限制
         */
        'max_friends' => 500,

        /**
         * 赛季归档数据表（不含前缀）
         */
        'archive_table' => 'game_board_archive',
    ],

    // ================================================================
    //  游戏存档 (Save) 配置
    // ================================================================

    'save' => [
        /**
         * 单存档最大容量（字节），默认 1MB
         */
        'max_size' => 1048576,

        /**
         * 存档版本历史保留数量（0 表示不保留历史）
         */
        'history_limit' => 3,

        /**
         * 默认允许的顶层字段白名单（空数组表示不限制）
         * 例: ['level', 'coins', 'inventory', 'achievements']
         */
        'field_whitelist' => [],

        /**
         * 存档数据表（不含前缀）
         */
        'table' => 'game_save',
    ],

    // ================================================================
    //  游戏配置下发 (Config) 配置
    // ================================================================

    'config' => [
        /**
         * ABTest 分桶总数
         */
        'bucket_count' => 100,

        /**
         * 默认分桶 ID（未命中实验时使用）
         */
        'default_bucket' => 0,

        /**
         * 配置 key 前缀（用于 wise-config 命名空间隔离）
         */
        'key_prefix' => 'game_config:',

        /**
         * 增量下发最大 diff 条目数
         */
        'max_diff_items' => 1000,

        /**
         * 配置数据表（不含前缀）
         */
        'table' => 'game_config',
    ],

    // ================================================================
    //  背包货币 (Inventory) 配置
    // ================================================================

    'inventory' => [
        /**
         * 插件背包模型映射
         * key: game_id
         * value: 实现 InventoryModelInterface 的模型类 FQCN
         *
         * 示例:
         *   'xxl' => \plugin\xxl\model\Inventory::class,
         */
        'models' => [
            // ── 消消乐插件 ──────────────────────────────────────────────
            // 插件自带 `plugins/match3game/config/wise-game-core.php`，但插件配置只落在
            // **插件域**（`config('match3game.wise-game-core.inventory.models')`），
            // 而 `InventoryService` 读的是**全局域** `config('wise-game-core.inventory.models')`
            // —— 不在宿主这里登记一次，`home/index` 等接口会直接报
            // `[210202] 背包模型未注册: game_id=match3_game`（2026-09-26 实测）。
            'match3_game' => \plugin\match3game\model\Match3PlayerInventory::class,
        ],

        /**
         * 是否启用背包操作流水写入
         */
        'enable_log' => true,

        /**
         * 流水数据表（不含前缀）
         */
        'log_table' => 'inventory_log',

        /**
         * 默认分页大小（查询流水时）
         */
        'log_page_size' => 20,

        /**
         * 是否启用 Redis 缓存加速（减少 DB 查询）
         */
        'cache_enabled' => false,

        /**
         * 缓存 TTL（秒）
         */
        'cache_ttl' => 300,
    ],

    // ================================================================
    //  路由前缀（兼容旧版，新代码请使用 web 节）
    // ================================================================

    'route' => [
        'api_prefix' => 'api/game',
    ],

    // ================================================================
    //  Web 路由（配置驱动，由 RouteRegistrar 读取）
    // ================================================================

    'web' => [
        // 总开关：false 时本包不注册任何 HTTP 路由
        'enable' => true,
        // 默认路由前缀（分组级 prefix 可覆盖）
        'prefix' => 'api/game',
        // 全局默认中间件（所有分组生效）
        'middleware' => [],
        // 分组配置：key 对应 RouteRegistrar::define() 中的 group('key', ...)
        'groups' => [
            // 排行榜读操作（公开）
            'leaderboard_read' => [
                'enable'     => true,
                'middleware' => [],
            ],
            // 排行榜写操作（需 JWT）
            'leaderboard_write' => [
                'enable'     => true,
                'middleware' => [
                    [\wise\jwt\middleware\JwtAuth::class, ['app', 'bearer']],
                ],
            ],
            // 游戏存档（需 JWT）
            'save' => [
                'enable'     => true,
                'middleware' => [
                    [\wise\jwt\middleware\JwtAuth::class, ['app', 'bearer']],
                ],
            ],
            // 游戏配置下发（公开）
            'config' => [
                'enable'     => true,
                'middleware' => [],
            ],
            // 背包货币（需 JWT）
            'inventory' => [
                'enable'     => true,
                'middleware' => [
                    [\wise\jwt\middleware\JwtAuth::class, ['app', 'bearer']],
                ],
            ],
        ],
    ],
];
