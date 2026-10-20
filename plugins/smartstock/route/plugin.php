<?php
/**
 * SmartStock 插件后端路由定义
 *
 * 所有路由显式注册，支持 RBAC 中间件和权限控制。
 * 路由前缀：/plugin/smartstock/
 * 注意：使用字符串形式 FQCN 避免与 model 类名冲突。
 */

use think\facade\Route;

// ================================================================
//  仪表盘
// ================================================================
Route::get('dashboard', '\plugin\smartstock\controller\Dashboard@index');
Route::get('dashboard/getStats', '\plugin\smartstock\controller\Dashboard@getStats');

// ================================================================
//  商品管理
// ================================================================
Route::get('product', '\plugin\smartstock\controller\Product@index');
Route::post('product/getList', '\plugin\smartstock\controller\Product@getList');
Route::post('product/save', '\plugin\smartstock\controller\Product@save');
Route::post('product/delete', '\plugin\smartstock\controller\Product@delete');
Route::get('product/search', '\plugin\smartstock\controller\Product@search');

// ================================================================
//  商品分类
// ================================================================
Route::get('product_category', '\plugin\smartstock\controller\ProductCategory@index');
Route::get('product_category/tree', '\plugin\smartstock\controller\ProductCategory@tree');
Route::post('product_category/getList', '\plugin\smartstock\controller\ProductCategory@getList');
Route::post('product_category/save', '\plugin\smartstock\controller\ProductCategory@save');
Route::post('product_category/delete', '\plugin\smartstock\controller\ProductCategory@delete');

// ================================================================
//  仓库管理
// ================================================================
Route::get('warehouse', '\plugin\smartstock\controller\Warehouse@index');
Route::post('warehouse/getList', '\plugin\smartstock\controller\Warehouse@getList');
Route::post('warehouse/save', '\plugin\smartstock\controller\Warehouse@save');
Route::post('warehouse/delete', '\plugin\smartstock\controller\Warehouse@delete');
Route::get('warehouse/getEnabled', '\plugin\smartstock\controller\Warehouse@getEnabled');

// ================================================================
//  计量单位
// ================================================================
Route::get('unit', '\plugin\smartstock\controller\Unit@index');
Route::post('unit/getList', '\plugin\smartstock\controller\Unit@getList');
Route::post('unit/save', '\plugin\smartstock\controller\Unit@save');
Route::post('unit/delete', '\plugin\smartstock\controller\Unit@delete');

// ================================================================
//  规格模板
// ================================================================
Route::get('spec', '\plugin\smartstock\controller\Spec@index');
Route::post('spec/getList', '\plugin\smartstock\controller\Spec@getList');
Route::post('spec/save', '\plugin\smartstock\controller\Spec@save');
Route::post('spec/delete', '\plugin\smartstock\controller\Spec@delete');

// ================================================================
//  库存查询
// ================================================================
Route::get('stock', '\plugin\smartstock\controller\Stock@index');
Route::post('stock/getList', '\plugin\smartstock\controller\Stock@getList');
Route::post('stock/adjust', '\plugin\smartstock\controller\Stock@adjust');

// ================================================================
//  库存流水
// ================================================================
Route::get('inventory_log', '\plugin\smartstock\controller\InventoryLog@index');
Route::post('inventory_log/getList', '\plugin\smartstock\controller\InventoryLog@getList');

// ================================================================
//  入库管理
// ================================================================
Route::get('stock_in', '\plugin\smartstock\controller\StockIn@index');
Route::post('stock_in/getList', '\plugin\smartstock\controller\StockIn@getList');
Route::post('stock_in/save', '\plugin\smartstock\controller\StockIn@save');
Route::get('stock_in/detail', '\plugin\smartstock\controller\StockIn@detail');
Route::get('stock_in/getTransitions', '\plugin\smartstock\controller\StockIn@getTransitions');
Route::get('stock_in/getHistory', '\plugin\smartstock\controller\StockIn@getHistory');
Route::post('stock_in/applyTransition', '\plugin\smartstock\controller\StockIn@applyTransition');

// ================================================================
//  出库管理
// ================================================================
Route::get('stock_out', '\plugin\smartstock\controller\StockOut@index');
Route::post('stock_out/getList', '\plugin\smartstock\controller\StockOut@getList');
Route::post('stock_out/save', '\plugin\smartstock\controller\StockOut@save');
Route::get('stock_out/detail', '\plugin\smartstock\controller\StockOut@detail');
Route::post('stock_out/validateStock', '\plugin\smartstock\controller\StockOut@validateStock');
Route::get('stock_out/getTransitions', '\plugin\smartstock\controller\StockOut@getTransitions');
Route::get('stock_out/getHistory', '\plugin\smartstock\controller\StockOut@getHistory');
Route::post('stock_out/applyTransition', '\plugin\smartstock\controller\StockOut@applyTransition');

// ================================================================
//  调拨管理
// ================================================================
Route::get('transfer', '\plugin\smartstock\controller\Transfer@index');
Route::post('transfer/getList', '\plugin\smartstock\controller\Transfer@getList');
Route::post('transfer/save', '\plugin\smartstock\controller\Transfer@save');
Route::get('transfer/detail', '\plugin\smartstock\controller\Transfer@detail');
Route::get('transfer/getTransitions', '\plugin\smartstock\controller\Transfer@getTransitions');
Route::get('transfer/getHistory', '\plugin\smartstock\controller\Transfer@getHistory');
Route::post('transfer/applyTransition', '\plugin\smartstock\controller\Transfer@applyTransition');

// ================================================================
//  盘点管理
// ================================================================
Route::get('check', '\plugin\smartstock\controller\Check@index');
Route::post('check/getList', '\plugin\smartstock\controller\Check@getList');
Route::post('check/save', '\plugin\smartstock\controller\Check@save');
Route::get('check/detail', '\plugin\smartstock\controller\Check@detail');
Route::get('check/execute', '\plugin\smartstock\controller\Check@execute');
Route::post('check/submitResult', '\plugin\smartstock\controller\Check@submitResult');
Route::get('check/getTransitions', '\plugin\smartstock\controller\Check@getTransitions');
Route::get('check/getHistory', '\plugin\smartstock\controller\Check@getHistory');
Route::post('check/applyTransition', '\plugin\smartstock\controller\Check@applyTransition');

// ================================================================
//  预警中心
// ================================================================
Route::get('alert/ruleIndex', '\plugin\smartstock\controller\Alert@ruleIndex');
Route::get('alert/ruleForm', '\plugin\smartstock\controller\Alert@ruleForm');
Route::post('alert/getRuleList', '\plugin\smartstock\controller\Alert@getRuleList');
Route::post('alert/saveRule', '\plugin\smartstock\controller\Alert@saveRule');
Route::post('alert/deleteRule', '\plugin\smartstock\controller\Alert@deleteRule');
Route::post('alert/toggleRule', '\plugin\smartstock\controller\Alert@toggleRule');
Route::get('alert/getRuleDetail', '\plugin\smartstock\controller\Alert@getRuleDetail');
Route::get('alert/logIndex', '\plugin\smartstock\controller\Alert@logIndex');
Route::post('alert/getLogList', '\plugin\smartstock\controller\Alert@getLogList');
Route::post('alert/markRead', '\plugin\smartstock\controller\Alert@markRead');
Route::post('alert/batchRead', '\plugin\smartstock\controller\Alert@batchRead');
Route::get('alert/getUnreadCount', '\plugin\smartstock\controller\Alert@getUnreadCount');

// ================================================================
//  数据报表
// ================================================================
Route::get('report/inOut', '\plugin\smartstock\controller\Report@inOut');
Route::post('report/getInOutData', '\plugin\smartstock\controller\Report@getInOutData');
Route::get('report/turnover', '\plugin\smartstock\controller\Report@turnover');
Route::post('report/getTurnoverData', '\plugin\smartstock\controller\Report@getTurnoverData');

// ================================================================
//  系统配置（已迁移至插件中心统一配置页 /admin/plugin/configIndex/name/smartstock）
// ================================================================
