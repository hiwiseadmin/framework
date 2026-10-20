<?php
namespace app\middleware;

use think\Request;
use wise\support\tools\DirFile;
use think\facade\Config;
use think\facade\Cache;
use wise\support\config\ConfigCheckCollector;

/**
 * 初始化应用配置环境（全局中间件，作用于 admin/api/member/index/install 等所有应用）
 *
 * 为避免"全局一刀切"带来的无效开销，按用途与代价分区执行：
 *
 *  A. 模板占位符 + 插件服务地址 —— 前置、全局执行：
 *     成本 O(1)，且任何应用都可能渲染视图（后台/前台/错误页/插件模板），
 *     必须在控制器渲染前注入，不适合按应用裁剪。
 *
 *  B. 包配置健康探测（ConfigCheckCollector::report）—— 请求收尾执行：
 *     report() 自带冷却（同项 5 分钟内只打印一次），不属于首屏关键路径，
 *     故放到 finally 中执行并静默失败，避免 Redis/DB 探测拖慢每个请求；
 *     后台「安全体检」页可随时主动查看明细（detail()）。
 *
 *  C. 静态资源版本号 —— 仅 GET + text/html 响应执行：
 *     调试模式下需递归扫描 statics 目录取最新 mtime 作为资源版本，并对 HTML
 *     中 <img/script src>、<link href> 注入 ?_v=。原先该逻辑对所有请求
 *     （含 API JSON、AJAX、POST）都会执行目录扫描与正则，属明显浪费，
 *     现收敛到真正需要版本化的 HTML 响应上，非 HTML 请求完全跳过。
 */
class Environment
{
    /** 需要做静态资源版本化的响应 Content-Type 子串 */
    protected const HTML_CONTENT_TYPE = 'text/html';

    public function handle(Request $request, \Closure $next)
    {
        // A. 全局低成本配置：模板占位符 + 插件服务地址（必须早于视图渲染）
        $this->setupTemplateReplace($request);
        $this->setupPluginUrls();

        try {
            $response = $next($request);
        } finally {
            // B. 包环境健康探测：请求收尾上报，不阻塞主流程，失败静默
            $this->reportEnvironmentChecks();
        }

        // C. 静态资源版本化：仅对实际返回 HTML 的 GET 响应执行
        if ($request->method() === 'GET'
            && $response instanceof \think\Response
            && strpos((string) $response->getHeader('Content-Type'), self::HTML_CONTENT_TYPE) !== false
        ) {
            $this->injectAssetVersion($response);
        }

        return $response;
    }

    /**
     * 模板占位符：__CDN__ / __PUBLIC__ / __ROOT__（由模板引擎渲染时消费）
     */
    protected function setupTemplateReplace(Request $request): void
    {
        $baseUrl = preg_replace('/\/[^\/]+\.php$/i', '', $request->baseFile(true));
        $baseUrl = rtrim($baseUrl, '/');
        $replaceStr = Config::get('view.tpl_replace_string');

        Config::set([
            'tpl_replace_string' => array_merge([
                '__CDN__'    => $replaceStr['__CDN__'] ?? $baseUrl,
                '__PUBLIC__' => $replaceStr['__PUBLIC__'] ?? $baseUrl . '/',
                '__ROOT__'   => $replaceStr['__ROOT__'] ?? preg_replace('/\/public$/', '', $baseUrl),
            ], $replaceStr),
        ], 'view');
    }

    /**
     * 插件服务端地址（插件市场/下载/注册/登录）
     *
     * 市场地址（T27 / C1）：由原插件 `pluginhub` 迁至 wise-market 包后，
     * 公开接口前缀从 `/plugin/pluginhub/*` 改为 `/api/market/*`（投递 ExtPoints::ROUTE_API）。
     *
     * ⚠️ 契约不变部分：`list_url` 的响应必须是 `{code:0,msg,data:{category,list,total,page,limit}}`
     * —— `PluginManager::getOnlinePluginList()`（`extend/wise/plugin/PluginManager.php:1033`）
     * 明确校验 `code === 0` 且 `data.list` 存在，缺一即静默返回空列表。
     *
     * 安装/升级契约（阶段 1）：
     *   - `download_url` 以 **GET + query(ident, version[, license_key])** 调用；
     *   - `update_url`（`/api/market/index/checkUpdate`）返回
     *     `{code:0,data:{has_update,is_charge,latest_version,changelog,dist_url,min_framework_version}}`，
     *     由客户端在安装/升级前先确认版本并取 `dist_url`。
     *   目标市场服务端若仍是旧契约（POST + plugin/version），客户端会自动回退到旧流程。
     */
    protected function setupPluginUrls(): void
    {
        $pluginServiceUrl = rtrim(config('plugin.service_url'), '/');

        Config::set([
            'list_url'      => $pluginServiceUrl . '/api/market/index/list',
            'download_url'  => $pluginServiceUrl . '/api/market/index/download',
            'update_url'    => $pluginServiceUrl . '/api/market/index/checkUpdate',
            'register_url'  => $pluginServiceUrl . '/member/register',
            'login_url'     => $pluginServiceUrl . '/member/dologin',
        ], 'plugin');
    }

    /**
     * 包配置健康探测（收尾执行，内部冷却抑制重复日志；异常不影响业务）
     */
    protected function reportEnvironmentChecks(): void
    {
        try {
            app()->make(ConfigCheckCollector::class)->report();
        } catch (\Throwable $e) {
            // 健康探测失败不应影响业务请求
        }
    }

    /**
     * 静态资源版本号注入：为 HTML 中尚未带版本参数的静态资源附加 ?_v= 缓存参数
     */
    protected function injectAssetVersion(\think\Response $response): void
    {
        $version = $this->resolveAssetVersion();

        $content = $response->getContent();

        $content = preg_replace_callback('/(<(img|script)[^>]+src|<link[^>]+href)=["\'](?!data:)(.*?)["\']/i',
            function ($matches) use ($version) {
                // 已有 v 参数则跳过，避免二次追加
                if (strpos($matches[3], '?v=') !== false || strpos($matches[3], '&v=') !== false) {
                    return $matches[0];
                }
                if (strpos($matches[3], '?') === false) {
                    return $matches[1] . '="' . $matches[3] . '?_v=' . $version . '"';
                }
                return $matches[1] . '="' . $matches[3] . '&_v=' . $version . '"';
            },
            $content
        );

        $response->content($content);
    }

    /**
     * 解析当前静态资源版本号
     *
     *  - 调试模式：取 statics 下 js/css 最新修改时间作为版本增量，使开发改动
     *    即时反映到浏览器缓存（并切换到框架默认异常模板）；
     *  - 生产模式：直接沿用 site.version + module_version（热修复版本）。
     *
     * @return string 形如 "1.0.1.3"
     */
    protected function resolveAssetVersion(): string
    {
        $version = config('site.version');

        if (config('app.debug')) {
            $dirs = ['statics/modules/', 'statics/admin/', 'statics/css/'];
            foreach ($dirs as $dir) {
                $files = DirFile::recurDir(public_path() . $dir, ['*'], ['js', 'css']);
                foreach ($files as $file) {
                    $version = max($version, filemtime($file['fullName']) ?? 0);
                }
            }

            // 注：原先此处 Config::set('exception_tmpl', think_exception.tpl) 试图在调试模式下
            // 切换到框架异常页，但该语句执行时异常响应早已在 Handle::renderExceptionContent()
            // 中渲染完毕（中间件链在异常被捕获之后才走到这里），对当前请求永远无效。
            // 调试模式下的异常模板选择已改由 config/app.php 按 APP_DEBUG 静态决定。
        }

        $hotfix = Cache::store('shared_file')->get('module_version', '1');

        return "{$version}.{$hotfix}";
    }
}
