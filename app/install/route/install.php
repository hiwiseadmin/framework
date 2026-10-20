<?php

use think\facade\Route;

// 安装模块路由
Route::group('install', function () {
    Route::get('/', 'index/index');
    Route::get('index', 'index/index');
    Route::get('license', 'index/license');
    Route::get('check', 'index/check');
    Route::get('config', 'index/config');
    Route::post('config', 'index/config');
    Route::get('install', 'index/install');
    Route::post('install', 'index/install');
    Route::get('complete', 'index/complete');
    Route::get('reinstall', 'index/reinstall');
})->prefix('install/')->middleware(\app\install\middleware\InstallCheck::class);