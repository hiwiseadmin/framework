<?php
// 会员应用 — wise-* 包投递路由
//
// 会员端自身路由见 member.php，出口②③ 见 wise-member-api.php / wise-member-admin.php，
// 本文件只落地包投递到
// ExtPoints::ROUTE_MEMBER 的分组（相对前缀，如 /member/xxx 下的 'xxx'）。
// 文件名排在字典序末尾，保证会员端自有路由优先匹配。

use think\facade\Route;
use wise\support\ExtPoints;
use wise\support\router\RouteRegistrar;

Route::group(function () {
    RouteRegistrar::applyRoutes(ExtPoints::ROUTE_MEMBER);
});
