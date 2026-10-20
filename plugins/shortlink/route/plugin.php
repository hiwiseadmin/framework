<?php
use think\facade\Route;

Route::get('link', '\plugin\shortlink\controller\Link@index');
Route::get('link/index', '\plugin\shortlink\controller\Link@index');
Route::post('link/getList', '\plugin\shortlink\controller\Link@getList');
Route::get('link/form', '\plugin\shortlink\controller\Link@form');
Route::post('link/save', '\plugin\shortlink\controller\Link@save');
Route::post('link/delete', '\plugin\shortlink\controller\Link@delete');
Route::post('link/toggle', '\plugin\shortlink\controller\Link@toggle');
Route::get('link/getGroups', '\plugin\shortlink\controller\Link@getGroups');
Route::post('link/batchCreate', '\plugin\shortlink\controller\Link@batchCreate');

Route::get('group', '\plugin\shortlink\controller\Group@index');
Route::get('group/index', '\plugin\shortlink\controller\Group@index');
Route::post('group/getList', '\plugin\shortlink\controller\Group@getList');
Route::post('group/save', '\plugin\shortlink\controller\Group@save');
Route::post('group/delete', '\plugin\shortlink\controller\Group@delete');
Route::get('group/getAll', '\plugin\shortlink\controller\Group@getAll');

Route::post('api/create', '\plugin\shortlink\controller\Api@create');
Route::get('api/info', '\plugin\shortlink\controller\Api@info');
Route::post('api/delete', '\plugin\shortlink\controller\Api@delete');
Route::get('api/stats', '\plugin\shortlink\controller\Api@stats');

Route::get('stat', '\plugin\shortlink\controller\Stat@index');
Route::get('stat/index', '\plugin\shortlink\controller\Stat@index');
Route::post('stat/getList', '\plugin\shortlink\controller\Stat@getList');
Route::post('stat/getTrend', '\plugin\shortlink\controller\Stat@getTrend');
Route::post('stat/getTop', '\plugin\shortlink\controller\Stat@getTop');
