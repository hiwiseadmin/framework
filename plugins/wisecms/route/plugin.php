<?php
/**
 * CMS 插件后台路由
 * URL映射: /plugin/wisecms/{controller}/{action}
 * 
 * 所有后台控制器继承 BaseController，自带 RBAC 权限验证
 */

use think\facade\Route;

// 栏目管理
Route::get('category/index', 'plugin\\wisecms\\controller\\Category@index');
Route::get('category/create', 'plugin\\wisecms\\controller\\Category@create');
Route::post('category/create', 'plugin\\wisecms\\controller\\Category@create');
Route::get('category/edit', 'plugin\\wisecms\\controller\\Category@edit');
Route::post('category/edit', 'plugin\\wisecms\\controller\\Category@edit');
Route::post('category/delete', 'plugin\\wisecms\\controller\\Category@delete');
Route::post('category/toggleStatus', 'plugin\\wisecms\\controller\\Category@toggleStatus');
Route::post('category/batchToggle', 'plugin\\wisecms\\controller\\Category@batchToggle');
// 后台栏目树（全状态 + model_name）—— UIUX-P0-47：「栏目管理」树表与「内容管理」左树共用
Route::get('category/tree', 'plugin\\wisecms\\controller\\Category@tree');

// 内容管理
Route::get('content/index', 'plugin\\wisecms\\controller\\Content@index');
// 内容导出 CSV（UIUX-P1-22）
Route::get('content/export', 'plugin\\wisecms\\controller\\Content@export');
// 自动草稿（UIUX-P1-03：轻量保存，不触发审核推导 / 不刷版本快照）
Route::post('content/autosave', 'plugin\\wisecms\\controller\\Content@autosave');
Route::get('content/create', 'plugin\\wisecms\\controller\\Content@create');
Route::post('content/create', 'plugin\\wisecms\\controller\\Content@create');
// 按模型返回自定义字段表单（UIUX-P1-02：切换模型 AJAX 局部重载字段区）
Route::get('content/customFields', 'plugin\\wisecms\\controller\\Content@customFields');
Route::get('content/edit', 'plugin\\wisecms\\controller\\Content@edit');
Route::post('content/edit', 'plugin\\wisecms\\controller\\Content@edit');
Route::post('content/delete', 'plugin\\wisecms\\controller\\Content@delete');
Route::post('content/batchDelete', 'plugin\\wisecms\\controller\\Content@batchDelete');
Route::post('content/toggleStatus', 'plugin\\wisecms\\controller\\Content@toggleStatus');
// 内容标志位（置顶/推荐）行内开关 —— UIUX-P0-46：toggleStatus 只处理 status，
// is_top / is_recommend 此前无任何切换入口
Route::post('content/toggleFlags', 'plugin\\wisecms\\controller\\Content@toggleFlags');
Route::post('content/moveCategory', 'plugin\\wisecms\\controller\\Content@moveCategory');
// 内容版本历史 / 对比 / 回滚（A-1）
Route::get('content/versions', 'plugin\\wisecms\\controller\\Content@versions');
Route::get('content/versionDiff', 'plugin\\wisecms\\controller\\Content@versionDiff');
Route::post('content/rollback', 'plugin\\wisecms\\controller\\Content@rollback');
Route::post('content/versionDelete', 'plugin\\wisecms\\controller\\Content@versionDelete');
// 内容预览令牌签发（B-3，自动继承后台 RBAC）
Route::post('content/previewToken', 'plugin\\wisecms\\controller\\Content@previewToken');

// 模型管理
Route::get('model/index', 'plugin\\wisecms\\controller\\Model@index');
Route::get('model/create', 'plugin\\wisecms\\controller\\Model@create');
Route::post('model/create', 'plugin\\wisecms\\controller\\Model@create');
Route::get('model/edit', 'plugin\\wisecms\\controller\\Model@edit');
Route::post('model/edit', 'plugin\\wisecms\\controller\\Model@edit');
Route::post('model/delete', 'plugin\\wisecms\\controller\\Model@delete');
Route::post('model/toggleStatus', 'plugin\\wisecms\\controller\\Model@toggleStatus');
Route::get('model/export', 'plugin\\wisecms\\controller\\Model@export');
Route::post('model/import', 'plugin\\wisecms\\controller\\Model@import');

// 字段管理
Route::get('modelField/index', 'plugin\\wisecms\\controller\\ModelField@index');
Route::get('modelField/create', 'plugin\\wisecms\\controller\\ModelField@create');
Route::post('modelField/create', 'plugin\\wisecms\\controller\\ModelField@create');
Route::get('modelField/edit', 'plugin\\wisecms\\controller\\ModelField@edit');
Route::post('modelField/edit', 'plugin\\wisecms\\controller\\ModelField@edit');
Route::post('modelField/delete', 'plugin\\wisecms\\controller\\ModelField@delete');
// UIUX-P0-45：以下三个方法**早已存在于控制器**，但从未登记路由 —— 此前访问是 404。
// 注意：manifest.json 里 `plugin.wisecms.action.modelField`（/modelField/*，method:*）只是
// RBAC 权限声明，不构成 HTTP 路由，所以「有权限」并不代表「可访问」。
Route::post('modelField/sort', 'plugin\\wisecms\\controller\\ModelField@sort');            // 入参 sort[{字段ID}]=排序值
Route::get('modelField/export', 'plugin\\wisecms\\controller\\ModelField@export');           // 入参 ?modelId=N
Route::get('modelField/getAddForm', 'plugin\\wisecms\\controller\\ModelField@getAddForm');   // 入参 ?field_type=xxx，返回裸 HTML

// 标签管理
Route::get('tag/index', 'plugin\\wisecms\\controller\\Tag@index');
Route::post('tag/create', 'plugin\\wisecms\\controller\\Tag@create');
Route::post('tag/edit', 'plugin\\wisecms\\controller\\Tag@edit');
Route::post('tag/delete', 'plugin\\wisecms\\controller\\Tag@delete');
Route::post('tag/batchDelete', 'plugin\\wisecms\\controller\\Tag@batchDelete');

// 友情链接（B-1）
Route::get('friendLink/index', 'plugin\\wisecms\\controller\\FriendLink@index');
Route::get('friendLink/create', 'plugin\\wisecms\\controller\\FriendLink@create');
Route::post('friendLink/create', 'plugin\\wisecms\\controller\\FriendLink@create');
Route::get('friendLink/edit', 'plugin\\wisecms\\controller\\FriendLink@edit');
Route::post('friendLink/edit', 'plugin\\wisecms\\controller\\FriendLink@edit');
Route::post('friendLink/delete', 'plugin\\wisecms\\controller\\FriendLink@delete');
Route::post('friendLink/batchDelete', 'plugin\\wisecms\\controller\\FriendLink@batchDelete');
Route::post('friendLink/toggleStatus', 'plugin\\wisecms\\controller\\FriendLink@toggleStatus');
Route::post('friendLink/sort', 'plugin\\wisecms\\controller\\FriendLink@sort');

// 单页管理
Route::get('page/index', 'plugin\\wisecms\\controller\\Page@index');
Route::get('page/create', 'plugin\\wisecms\\controller\\Page@create');
Route::post('page/create', 'plugin\\wisecms\\controller\\Page@create');
Route::get('page/edit', 'plugin\\wisecms\\controller\\Page@edit');
Route::post('page/edit', 'plugin\\wisecms\\controller\\Page@edit');
Route::post('page/delete', 'plugin\\wisecms\\controller\\Page@delete');
Route::post('page/restore', 'plugin\\wisecms\\controller\\Page@restore');

// 内容模板（UIUX-P2-03：TemplateService 的入口补齐）
Route::get('template/index', 'plugin\\wisecms\\controller\\Template@index');
Route::get('template/create', 'plugin\\wisecms\\controller\\Template@create');
Route::post('template/create', 'plugin\\wisecms\\controller\\Template@create');
Route::get('template/edit', 'plugin\\wisecms\\controller\\Template@edit');
Route::post('template/edit', 'plugin\\wisecms\\controller\\Template@edit');
Route::post('template/delete', 'plugin\\wisecms\\controller\\Template@delete');
Route::post('template/installDefaults', 'plugin\\wisecms\\controller\\Template@installDefaults');

// 回收站
Route::get('recycle/index', 'plugin\\wisecms\\controller\\Recycle@index');
Route::post('recycle/restore', 'plugin\\wisecms\\controller\\Recycle@restore');
Route::post('recycle/destroy', 'plugin\\wisecms\\controller\\Recycle@destroy');
Route::post('recycle/clear', 'plugin\\wisecms\\controller\\Recycle@clear');
Route::post('recycle/batchRestore', 'plugin\\wisecms\\controller\\Recycle@batchRestore');
Route::post('recycle/batchDestroy', 'plugin\\wisecms\\controller\\Recycle@batchDestroy');

// CMS配置：由后台插件中心统一处理（菜单指向 /admin/plugin/configIndex/name/wisecms）
// 原先指向不存在的 plugin\wisecms\controller\Config，已移除，避免 500

// 评论管理 (Phase 3)
Route::get('comment/index', 'plugin\\wisecms\\controller\\Comment@index');
Route::post('comment/approve', 'plugin\\wisecms\\controller\\Comment@approve');
Route::post('comment/reject', 'plugin\\wisecms\\controller\\Comment@reject');
Route::post('comment/delete', 'plugin\\wisecms\\controller\\Comment@delete');
Route::post('comment/batchApprove', 'plugin\\wisecms\\controller\\Comment@batchApprove');
Route::post('comment/batchReject', 'plugin\\wisecms\\controller\\Comment@batchReject');

// AI生成 (Phase 3)
Route::get('generation/index', 'plugin\\wisecms\\controller\\Generation@index');
Route::get('generation/natural', 'plugin\\wisecms\\controller\\Generation@natural');
Route::post('generation/natural', 'plugin\\wisecms\\controller\\Generation@natural');
Route::get('generation/batch', 'plugin\\wisecms\\controller\\Generation@batch');
Route::post('generation/batch', 'plugin\\wisecms\\controller\\Generation@batch');
Route::get('generation/task', 'plugin\\wisecms\\controller\\Generation@task');
Route::post('generation/cancel', 'plugin\\wisecms\\controller\\Generation@cancel');

// 排期管理 (Phase 3)
Route::get('schedule/calendar', 'plugin\\wisecms\\controller\\Schedule@calendar');
Route::post('schedule/batch', 'plugin\\wisecms\\controller\\Schedule@batch');
Route::get('schedule/suggest', 'plugin\\wisecms\\controller\\Schedule@suggest');

// 工作流 (Phase 4)
Route::get('workflow/panel', 'plugin\\wisecms\\controller\\Workflow@panel');
Route::get('workflow/status', 'plugin\\wisecms\\controller\\Workflow@status');
Route::post('workflow/transition', 'plugin\\wisecms\\controller\\Workflow@transition');
Route::get('workflow/available', 'plugin\\wisecms\\controller\\Workflow@available');

// Phase 5 增值模块
// 多站点
Route::get('site/index', 'plugin\\wisecms\\controller\\Site@index');
Route::get('site/create', 'plugin\\wisecms\\controller\\Site@create');
Route::post('site/create', 'plugin\\wisecms\\controller\\Site@create');
Route::get('site/edit', 'plugin\\wisecms\\controller\\Site@edit');
Route::post('site/edit', 'plugin\\wisecms\\controller\\Site@edit');
Route::post('site/delete', 'plugin\\wisecms\\controller\\Site@delete');
// 后台站点选择器（P2）：options 供顶栏下拉取数，switch 只切会话上下文、不改数据，
// 二者均为显式登记路由（插件为 fail-closed，未登记路由对非超管直接 403）
Route::get('site/options', 'plugin\\wisecms\\controller\\Site@options');
Route::post('site/switch', 'plugin\\wisecms\\controller\\Site@switch');
// 广告管理
Route::get('ad/index', 'plugin\\wisecms\\controller\\Ad@index');
Route::get('ad/create', 'plugin\\wisecms\\controller\\Ad@create');
Route::post('ad/create', 'plugin\\wisecms\\controller\\Ad@create');
Route::get('ad/edit', 'plugin\\wisecms\\controller\\Ad@edit');
Route::post('ad/edit', 'plugin\\wisecms\\controller\\Ad@edit');
Route::post('ad/delete', 'plugin\\wisecms\\controller\\Ad@delete');
// 内容采集
Route::get('spider/index', 'plugin\\wisecms\\controller\\Spider@index');
Route::get('spider/create', 'plugin\\wisecms\\controller\\Spider@create');
Route::post('spider/create', 'plugin\\wisecms\\controller\\Spider@create');
Route::get('spider/edit', 'plugin\\wisecms\\controller\\Spider@edit');
Route::post('spider/edit', 'plugin\\wisecms\\controller\\Spider@edit');
Route::post('spider/run', 'plugin\\wisecms\\controller\\Spider@run');
Route::post('spider/delete', 'plugin\\wisecms\\controller\\Spider@delete');
// Webhook
Route::get('webhook/index', 'plugin\\wisecms\\controller\\Webhook@index');
Route::get('webhook/create', 'plugin\\wisecms\\controller\\Webhook@create');
Route::post('webhook/create', 'plugin\\wisecms\\controller\\Webhook@create');
Route::get('webhook/edit', 'plugin\\wisecms\\controller\\Webhook@edit');
Route::post('webhook/edit', 'plugin\\wisecms\\controller\\Webhook@edit');
Route::post('webhook/toggleStatus', 'plugin\\wisecms\\controller\\Webhook@toggleStatus');
Route::post('webhook/test', 'plugin\\wisecms\\controller\\Webhook@test');
Route::post('webhook/delete', 'plugin\\wisecms\\controller\\Webhook@delete');
// 邮件订阅
Route::get('newsletter/index', 'plugin\\wisecms\\controller\\Newsletter@index');
Route::post('newsletter/delete', 'plugin\\wisecms\\controller\\Newsletter@delete');
Route::post('newsletter/batchDelete', 'plugin\\wisecms\\controller\\Newsletter@batchDelete');
Route::get('newsletter/export', 'plugin\\wisecms\\controller\\Newsletter@export');
// URL重定向
Route::get('redirect/index', 'plugin\\wisecms\\controller\\Redirect@index');
Route::get('redirect/create', 'plugin\\wisecms\\controller\\Redirect@create');
Route::post('redirect/create', 'plugin\\wisecms\\controller\\Redirect@create');
Route::get('redirect/edit', 'plugin\\wisecms\\controller\\Redirect@edit');
Route::post('redirect/edit', 'plugin\\wisecms\\controller\\Redirect@edit');
Route::post('redirect/toggleStatus', 'plugin\\wisecms\\controller\\Redirect@toggleStatus');
Route::post('redirect/import', 'plugin\\wisecms\\controller\\Redirect@import');
Route::post('redirect/delete', 'plugin\\wisecms\\controller\\Redirect@delete');
Route::post('redirect/checkDead', 'plugin\\wisecms\\controller\\Redirect@checkDead');
// 数据看板
Route::get('dashboard/index', 'plugin\\wisecms\\controller\\Dashboard@index');
// 内容质量评分（UIUX-P2-02：与 seo/audit 同款，GET + 有副作用）
Route::get('content/quality', 'plugin\\wisecms\\controller\\Content@quality');
// 编辑器具内链选择器（UIUX-P1-06：只读，返回本站已发布内容的标题 + 绝对 URL）
Route::get('content/linkOptions', 'plugin\\wisecms\\controller\\Content@linkOptions');
// 搜索日志 / 热搜词（UIUX-P2-04，只读）
Route::get('searchLog/index', 'plugin\\wisecms\\controller\\SearchLog@index');
Route::get('searchLog/hot', 'plugin\\wisecms\\controller\\SearchLog@hot');
// 系统工具（UIUX-P2-06：刷新 CMS 缓存 / P2-05：孤儿数据清理）
Route::get('system/index', 'plugin\\wisecms\\controller\\System@index');
Route::post('system/flushCache', 'plugin\\wisecms\\controller\\System@flushCache');
Route::get('system/orphans', 'plugin\\wisecms\\controller\\System@orphans');
Route::post('system/purgeOrphans', 'plugin\\wisecms\\controller\\System@purgeOrphans');
// SEO 总览（UIUX-P1-20）
Route::get('seo/index', 'plugin\\wisecms\\controller\\Seo@index');
Route::get('seo/audit', 'plugin\\wisecms\\controller\\Seo@audit');
Route::post('seo/push', 'plugin\\wisecms\\controller\\Seo@push');
Route::get('seo/pushLog', 'plugin\\wisecms\\controller\\Seo@pushLog');
// 内链管理（UIUX-P1-21）
Route::get('internalLink/index', 'plugin\\wisecms\\controller\\InternalLink@index');
Route::post('internalLink/create', 'plugin\\wisecms\\controller\\InternalLink@create');
Route::post('internalLink/delete', 'plugin\\wisecms\\controller\\InternalLink@delete');
Route::post('internalLink/rebuild', 'plugin\\wisecms\\controller\\InternalLink@rebuild');

// URL 规则（伪静态模板配置）
Route::get('urlRule/index', 'plugin\\wisecms\\controller\\UrlRule@index');
Route::get('urlRule/presets', 'plugin\\wisecms\\controller\\UrlRule@presets');
Route::get('urlRule/create', 'plugin\\wisecms\\controller\\UrlRule@create');
Route::post('urlRule/create', 'plugin\\wisecms\\controller\\UrlRule@create');
Route::get('urlRule/edit', 'plugin\\wisecms\\controller\\UrlRule@edit');
Route::post('urlRule/edit', 'plugin\\wisecms\\controller\\UrlRule@edit');
Route::post('urlRule/delete', 'plugin\\wisecms\\controller\\UrlRule@delete');
Route::post('urlRule/toggleStatus', 'plugin\\wisecms\\controller\\UrlRule@toggleStatus');
Route::post('urlRule/rebuild', 'plugin\\wisecms\\controller\\UrlRule@rebuild');
