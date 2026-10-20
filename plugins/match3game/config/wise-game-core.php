<?php

/**
 * 消消乐插件 — wise-game-core 注册
 *
 * 注册背包模型映射，使 wise-game-core 的 InventoryService
 * 能通过 InventoryModelInterface 操作 match3_player_inventory 表。
 *
 * 注意：本文件由插件 BootServiceProvider::mergeConfig() 装载到插件配置域
 * (config('match3game.wise-game-core.inventory.models'))。
 * 运行时 InventoryService 读取 config('wise-game-core.inventory.models')（全局域），
 * 宿主接入时需将本映射合并进全局 wise-game-core 配置（见宿主 config/wise-game-core.php）。
 */
return [
    'inventory' => [
        'models' => [
            'match3_game' => \plugin\match3game\model\Match3PlayerInventory::class,
        ],
    ],
];
