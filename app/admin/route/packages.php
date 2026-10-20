<?php
// 后台应用 — wise-* 包投递路由
//
// app/admin 是标准的多应用，think-multi-app 已把 pathinfo 中的 'admin' 段剥离，
// 各包投递的分组前缀也是相对路径（如 'demo' → /admin/demo）。
//
// 文件名刻意排在 admin.php 之后加载（glob 为字典序），保证后台自身的显式路由
// 优先匹配，包路由只做补充。

use think\facade\Route;
use wise\support\ExtPoints;
use wise\support\router\RouteRegistrar;

Route::group(function () {
    RouteRegistrar::applyRoutes(ExtPoints::ROUTE_ADMIN);
});
