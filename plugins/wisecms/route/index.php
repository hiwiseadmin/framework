<?php
/**
 * CMS 插件前台路由
 *
 * URL映射: /cms/{controller}/{action}
 *
 * 说明：插件被设为「默认前端插件」时由 RouteLoader 加载该文件，
 * 规则不再追加 plugin/ 前缀，故此处统一使用 cms/ 前缀。
 *
 * 命名路由 + 伪静态：URL 统一由 service/url/UrlService 生成，
 * 末条 catch-all 交由 controller/index/Url@dispatch 处理伪静态路径
 * （须保持为最后一条，避免抢占上方精确路由）。
 */

use think\facade\Route;

// 前台首页
Route::get('cms', 'plugin\\wisecms\\controller\\index\\Index@index')->name('cms.home');
Route::get('cms/index/index', 'plugin\\wisecms\\controller\\index\\Index@index');

// 栏目页
Route::get('cms/category/index', 'plugin\\wisecms\\controller\\index\\Category@index')->name('cms.category.index');

// 内容详情页
Route::get('cms/content/detail', 'plugin\\wisecms\\controller\\index\\Content@detail')->name('cms.content.detail');

// 搜索页
Route::get('cms/search/index', 'plugin\\wisecms\\controller\\index\\Search@index')->name('cms.search.index');

// 标签页
Route::get('cms/tag/index', 'plugin\\wisecms\\controller\\index\\Tag@index')->name('cms.tag.index');

// 单页
Route::get('cms/page/detail', 'plugin\\wisecms\\controller\\index\\Page@detail')->name('cms.page.detail');

// 内容订阅（RSS/Atom）
// 顺序要求：必须位于下方伪静态 catch-all 之前，否则会被 Url@dispatch 抢占。
Route::get('cms/feed/index', 'plugin\\wisecms\\controller\\index\\Feed@index')->name('cms.feed.index');
Route::get('cms/feed/category', 'plugin\\wisecms\\controller\\index\\Feed@category')->name('cms.feed.category');
Route::get('cms/feed/tag', 'plugin\\wisecms\\controller\\index\\Feed@tag')->name('cms.feed.tag');

// 内容预览（带签名令牌，禁用收录；必须在伪静态 catch-all 之前）
Route::get('cms/preview/index', 'plugin\\wisecms\\controller\\index\\Preview@index')->name('cms.preview.index');

// 友情链接（B-1）：独立友链页 + 点击计数跳转，须在伪静态 catch-all 之前
Route::get('cms/link/index', 'plugin\\wisecms\\controller\\index\\Link@index')->name('cms.link.index');
Route::get('cms/link/go', 'plugin\\wisecms\\controller\\index\\Link@go')->name('cms.link.go');

// 伪静态统一入口（保持最后注册）
Route::get('cms/<path>', 'plugin\\wisecms\\controller\\index\\Url@dispatch')
    ->pattern(['path' => '[^/]+(?:/[^/]+)*']);
