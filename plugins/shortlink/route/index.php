<?php
/**
 * 短链接插件 - 前台公开路由
 * 无需登录即可访问
 */
use think\facade\Route;

// 短链接跳转（公开访问）
Route::get('s/<code>', '\plugin\shortlink\controller\Jump@redirect');
Route::get('jump/<code>', '\plugin\shortlink\controller\Jump@redirect');
