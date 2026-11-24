<?php
// +----------------------------------------------------------------------
// | WiseAdmin 迁移文件多路径注册中心
// +----------------------------------------------------------------------
// | 定义迁移引擎扫描的路径列表。
// | 框架核心 + 各子包的迁移文件保持原地（不复制），由 WiseMigrate 统一扫描。
// |
// | ServiceProvider 可在 boot() 时调用 Config::set() 动态追加路径。
// +----------------------------------------------------------------------

return [
    // 迁移文件扫描路径列表
    // 所有路径中的 *.php 文件都将被迁移引擎加载和追踪
    'paths' => [
        // 框架核心迁移（始终存在）
        app()->getRootPath() . 'database' . DIRECTORY_SEPARATOR . 'migrations',
        app()->getRootPath() . str_replace('/', DIRECTORY_SEPARATOR, 'extend/wise/plugin/database/migrations'),
        // 会员迁移由 member 插件生命周期接管（plugins/member/database/migrations），
        // 由 `php think plugin:migrate:run member` 执行，无需在此静态声明。

        // ⚠️ 业务插件（plugins/*）**不在此登记**。插件迁移由「插件引擎」独立接管：
        //     - `php think plugin:migrate:run -p <name>`（PluginServiceProvider 注册）
        //     - PluginManager::install()/upgrade()/uninstall() → MigrationRunner
        //   记录写入独立的 `{prefix}plugin_migrations`（ws_plugin_migrations），
        //   与框架/wise-* 包使用的 `{prefix}migrations`（ws_migrations）完全隔离，
        //   避免插件版本号与框架、各包版本号在同一张表内冲突。
    ],
];
