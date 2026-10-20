<?php
use think\facade\Route;

// 插件路由 - 使用自定义调度器   
Route::any('[:plugin]/[:action]', \app\plugin\dispatch\PluginDispatch::class)
    ->pattern(['plugin' => '[\w]+', 'action' => '[\w_]+']);
