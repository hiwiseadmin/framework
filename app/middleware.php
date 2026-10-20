<?php
// 全局中间件定义文件
return [
    // 请求 ID 追踪（输出 X-Request-Id 响应头）
    \app\middleware\RequestId::class,
    // 全局安全响应头（X-Content-Type-Options / X-Frame-Options / Referrer-Policy / HSTS / 移除 X-Powered-By）
    \app\middleware\SecurityHeaders::class,
    // Cookie secure 动态判定（必须在 SessionInit 之前）
    \app\middleware\InitCookieSecure::class,
    // 允许跨域请求
    think\middleware\AllowCrossDomain::class,
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    
    // 多语言加载（按 detect_var / header / cookie / 浏览器 侦测当前语言）
    //
    // 依赖链已打通：
    //   1. lang.allow_lang_list 非空（config/lang.php）—— 否则侦测结果一律被拒；
    //   2. 包 / 插件 / 宿主语言包由 LangPackService 在 register() 阶段注册进
    //      lang.extend_list（目录不会被自动加载: Lang::load() 只认文件）。
    // 本中间件早于 think-multi-app（后者在 HttpRun 事件后才追加），
    // 但 extend_list 登记的是绝对文件路径、与当前应用无关，故不受影响。
    //
    // ⚠️ 但语言本身会受影响：think-multi-app 切换应用时，MultiApp::loadApp()
    //    末行会调用 App::loadLangPack() 把当前语言**重置为 default_lang**，
    //    覆盖掉这里的侦测结果（表现为 `?lang=en-us` 在控制器/视图里仍是中文）。
    //    因此 admin / api / member / plugin 四个应用的 middleware.php 首位
    //    都重新挂载了一次 LoadLangPack（应用级流水线在应用切换之后执行）。
    //    本处保留：用于覆盖「未发生应用切换」的路径（如默认应用目录不存在、
    //    走 app_express 的场景），两处互为补充。
    \think\middleware\LoadLangPack::class,
    // Session初始化
    \think\middleware\SessionInit::class,
    // 环境配置中间件
    \app\middleware\Environment::class,
    // 插件加载中间件（初始化所有已启用插件）
    \wise\plugin\middleware\PluginLoader::class,
    // 管理员入口中间件
    \app\middleware\EntryVerify::class,
    
];
