<?php
/**
 * 插件中间件定义
 *
 * P0-2：注册 WiseDocs 后台鉴权中间件（插件层兜底）。
 *
 * 说明（重要）：
 * - 这里注册的是**全局**中间件（WiseBootServiceProvider::loadMiddleware → Middleware::add），
 *   在 Swoole 常驻进程下可生效；但在传统 FPM 下，插件在首个请求才完成 boot，
 *   全局中间件管道在 boot 之前已构建，故此处可能不参与当次请求。
 * - 因此**主要防护**由 `plugin\wisedocs\controller\Base` 声明的**控制器级中间件**承担
 *   （由 PluginDispatch 读取 `$middleware` 属性后注册，可靠且精确）。
 * - 本中间件已做「插件 + 后台基类」双重收敛，即使全局执行也不会误伤前台或其它插件。
 */
return [
    // WiseDocs 后台鉴权（登录 + 权限）。前台控制器与其它插件请求会被放行。
    \plugin\wisedocs\middleware\DocsAuth::class,
];
