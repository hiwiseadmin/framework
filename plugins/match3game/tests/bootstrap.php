<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap — 消消乐插件单元测试
 *
 * 1. 加载 Composer autoload（尝试多个 vendor 路径）
 * 2. 注册插件与 game 包的 PSR-4 自动加载（插件无独立 composer，game 包未接入宿主）
 */

$autoloadFiles = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
];

foreach ($autoloadFiles as $file) {
    if (file_exists($file)) {
        require_once $file;
        break;
    }
}

// 注册插件 / game 包命名空间自动加载（composer 未覆盖的部分）
spl_autoload_register(static function (string $class): void {
    $map = [
        'plugin\\match3game\\' => __DIR__ . '/../',
        'wise\\game\\stage\\'   => __DIR__ . '/../../../packages/wiseadmin/wise-game-stage/src/',
        'wise\\game\\core\\'    => __DIR__ . '/../../../packages/wiseadmin/wise-game-core/src/',
    ];

    foreach ($map as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $rel  = substr($class, strlen($prefix));
            $path = $base . str_replace('\\', '/', $rel) . '.php';
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }
    }
});
