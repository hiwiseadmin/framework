<?php

/**
 * 消消乐插件后台路由定义
 *
 * 路由前缀：/plugin/match3game/（由 WiseBootServiceProvider::registerRoute 自动添加）
 * 所有路由显式注册，支持 RBAC 中间件与权限控制。
 */

use think\facade\Route;

// ================================================================
//  运营概览
// ================================================================
Route::get('dashboard', '\plugin\match3game\controller\Dashboard@index');
Route::get('dashboard/getStats', '\plugin\match3game\controller\Dashboard@getStats');

// ================================================================
//  关卡管理
// ================================================================
Route::get('level', '\plugin\match3game\controller\Level@index');
Route::post('level/getList', '\plugin\match3game\controller\Level@getList');
Route::post('level/save', '\plugin\match3game\controller\Level@save');
Route::post('level/delete', '\plugin\match3game\controller\Level@delete');
Route::get('level/getMeta', '\plugin\match3game\controller\Level@getMeta');
Route::get('level/detail', '\plugin\match3game\controller\Level@detail');
Route::post('level/check', '\plugin\match3game\controller\Level@check');

// ================================================================
//  章节/地图
// ================================================================
Route::get('chapter', '\plugin\match3game\controller\Chapter@index');
Route::post('chapter/getList', '\plugin\match3game\controller\Chapter@getList');
Route::post('chapter/moveLevels', '\plugin\match3game\controller\Chapter@moveLevels');

// ================================================================
//  元素字典
// ================================================================
Route::get('element', '\plugin\match3game\controller\Element@index');
Route::post('element/getList', '\plugin\match3game\controller\Element@getList');
Route::post('element/save', '\plugin\match3game\controller\Element@save');
Route::post('element/delete', '\plugin\match3game\controller\Element@delete');

// ================================================================
//  道具配置
// ================================================================
Route::get('prop', '\plugin\match3game\controller\Prop@index');
Route::post('prop/getList', '\plugin\match3game\controller\Prop@getList');
Route::post('prop/save', '\plugin\match3game\controller\Prop@save');
Route::post('prop/delete', '\plugin\match3game\controller\Prop@delete');

// ================================================================
//  遗物配置（肉鸽，Phase 3）
// ================================================================
Route::get('relic', '\plugin\match3game\controller\Relic@index');
Route::post('relic/getList', '\plugin\match3game\controller\Relic@getList');
Route::post('relic/save', '\plugin\match3game\controller\Relic@save');
Route::post('relic/delete', '\plugin\match3game\controller\Relic@delete');
Route::post('relic/check', '\plugin\match3game\controller\Relic@check');

// ================================================================
//  事件配置（肉鸽，Phase 4 补齐）
// ================================================================
Route::get('event', '\plugin\match3game\controller\Event@index');
Route::post('event/getList', '\plugin\match3game\controller\Event@getList');
Route::post('event/save', '\plugin\match3game\controller\Event@save');
Route::post('event/delete', '\plugin\match3game\controller\Event@delete');
Route::post('event/check', '\plugin\match3game\controller\Event@check');

// ================================================================
//  赛季管理（Phase 5 · 05 §A7.1）
// ================================================================
Route::get('season', '\plugin\match3game\controller\Season@index');
Route::post('season/getList', '\plugin\match3game\controller\Season@getList');
Route::get('season/detail', '\plugin\match3game\controller\Season@detail');
Route::post('season/save', '\plugin\match3game\controller\Season@save');
Route::post('season/switchSeason', '\plugin\match3game\controller\Season@switchSeason');

// ================================================================
//  作弊复核（Phase 5 · 05 §A8.1 第 5 条）
// ================================================================
Route::get('cheat', '\plugin\match3game\controller\Cheat@index');
Route::post('cheat/getList', '\plugin\match3game\controller\Cheat@getList');
Route::post('cheat/review', '\plugin\match3game\controller\Cheat@review');

// ================================================================
//  群擂台（Phase 5 · 05 §A7.3）
// ================================================================
Route::get('group_battle', '\plugin\match3game\controller\GroupBattle@index');
Route::post('group_battle/getList', '\plugin\match3game\controller\GroupBattle@getList');
Route::post('group_battle/periods', '\plugin\match3game\controller\GroupBattle@periods');
Route::get('group_battle/members', '\plugin\match3game\controller\GroupBattle@members');
Route::post('group_battle/recalc', '\plugin\match3game\controller\GroupBattle@recalc');

// ================================================================
//  玩家
// ================================================================
Route::get('player', '\plugin\match3game\controller\Player@index');
Route::post('player/getList', '\plugin\match3game\controller\Player@getList');
Route::get('player/detail', '\plugin\match3game\controller\Player@detail');

// ================================================================
//  GM 道具发放
// ================================================================
Route::get('reward', '\plugin\match3game\controller\Reward@index');
Route::post('reward/send', '\plugin\match3game\controller\Reward@send');
Route::get('reward/items', '\plugin\match3game\controller\Reward@items');
// 背包流水审计（P6-B04）
Route::post('reward/logs', '\plugin\match3game\controller\Reward@logs');

// ================================================================
//  对局日志
// ================================================================
Route::get('battle_log', '\plugin\match3game\controller\BattleLog@index');
Route::post('battle_log/getList', '\plugin\match3game\controller\BattleLog@getList');
Route::get('battle_log/detail', '\plugin\match3game\controller\BattleLog@detail');

// ================================================================
//  玩法参数
// ================================================================
Route::get('settings', '\plugin\match3game\controller\Settings@index');

// ================================================================
//  测试工具
// ================================================================
Route::get('playtest', '\plugin\match3game\controller\Playtest@index');
Route::get('playtest/run', '\plugin\match3game\controller\Playtest@run');
Route::post('playtest/simulate', '\plugin\match3game\controller\Playtest@simulate');
Route::post('playtest/generate', '\plugin\match3game\controller\Playtest@generate');
