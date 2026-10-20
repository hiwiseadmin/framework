<?php
// 插件应用 — wise-* 包投递路由
//
// 插件自身的请求由 plugin.php 交给 PluginDispatch 处理；本文件只落地包投递到
// ExtPoints::ROUTE_PLUGIN 的分组，且排在 plugin.php 之后加载，避免抢占插件路由。

use think\facade\Route;
use wise\support\ExtPoints;
use wise\support\router\RouteRegistrar;

Route::group(function () {
    RouteRegistrar::applyRoutes(ExtPoints::ROUTE_PLUGIN);
});
