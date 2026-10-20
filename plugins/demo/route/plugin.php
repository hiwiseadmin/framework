<?php
use think\facade\Route;

// 注意：框架会自动包裹 /plugin/demo 前缀，此处无需再声明 group
Route::get('index', 'plugin\\demo\\controller\\Index@index')->middleware([\think\middleware\SessionInit::class]);
