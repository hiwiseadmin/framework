<?php

use think\facade\Route;

Route::miss(function () {
    return json([
        'code' => 404,
        'msg'  => '接口不存在',
        'data' => [],
    ], 404);
});
