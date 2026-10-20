<?php
// demo 插件 — 全局中间件注册（boot 链 Step 8）
//
// ⚠️ 本文件必须位于**插件根目录**：plugins/demo/middleware.php
//    放进 config/ 不会被加载 —— 框架只读插件根，
//    且 config/ 下的同名文件会被 Step 2 当作配置装载，污染配置域。
//
// 返回中间件类名数组，框架逐个执行 Middleware::add() 全局追加。
return [
    // \plugin\demo\middleware\ExampleMiddleware::class,
];
