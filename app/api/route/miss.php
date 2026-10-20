<?php
// API 应用兜底路由
//
// app/api 成为真正的多应用后，全局 route/miss.php 不再为 /api/* 加载，
// 未命中的请求若不兜住会掉进默认控制器解析（app\api\controller\{Xxx} 不存在）。
// 这里统一返回 JSON 404，保持 API 响应体结构 {code, msg, data} 一致。

use think\facade\Route;

Route::miss(function () {
    return json([
        'code' => 404,
        'msg'  => '接口不存在',
        'data' => [],
    ], 404);
});
