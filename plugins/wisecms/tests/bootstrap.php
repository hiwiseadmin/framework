<?php

declare(strict_types=1);

/**
 * WiseCMS 插件单元测试 bootstrap
 *
 * 1. 加载 Composer autoload（尝试多个 vendor 路径，存在才加载）
 * 2. 注册 plugin\wisecms PSR-4 自动加载（插件无独立 composer）
 */

$autoloadFiles = [
    __DIR__ . '/../../../vendor/autoload.php',   // src/vendor/autoload.php
    __DIR__ . '/../../../../vendor/autoload.php', // 兜底
];

foreach ($autoloadFiles as $file) {
    if (file_exists($file)) {
        require_once $file;
        break;
    }
}

// 注册插件命名空间自动加载
spl_autoload_register(static function (string $class): void {
    $prefix = 'plugin\\wisecms\\';
    if (str_starts_with($class, $prefix)) {
        $rel  = substr($class, strlen($prefix));
        $path = __DIR__ . '/../' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
});
