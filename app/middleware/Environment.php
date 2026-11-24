<?php
namespace app\middleware;

use app\common\service\AssetVersion;
use think\Request;
use wise\support\tools\DirFile;
use think\facade\Config;
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

    /** 参与版本化的静态资源扩展名（只处理 /statics/ 下的文件） */
    protected const VERSIONABLE_EXT = [
        'js', 'css', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp', 'woff', 'woff2', 'ttf',
    ];

    /** 站点静态资源根路径：用户上传（/uploads）与外链一律不改写 */
    protected const STATIC_PATH_PREFIX = '/statics/';

    /**
     * 生产模式下参与版本号计算的内核构建产物（相对 public 目录）
     *
     * 只列内核入口产物，不递归扫描整个 statics（避免每请求全目录 IO）：
     * 这些文件一旦更新，`?_v=` 随之变化，浏览器不会继续命中旧缓存。
     * 背景：生产模式原先只用 site.version + module_version，二者不变时
     * 改过的 enhanced-table.js / base.css 等永远不会被浏览器重新拉取，
     * 表现为「代码已合并、页面样式仍是旧的」。
     *
     * 新资源**不需要**往这里加：全局计数器（AssetVersion）已覆盖所有包/插件
     * 前端资源；本清单只承担「内核改了但没人点清理」的兜底。
     */
    protected const ASSET_VERSION_FILES = [
        'statics/modules/enhanced-table.js',
        'statics/modules/wise-table.js',
        'statics/modules/wise-bundle.js',
        'statics/modules/wise-bundle.min.js',
        'statics/css/base.css',
        'statics/css/wise-bundle.css',
        'statics/css/wise-bundle.min.css',
        'statics/plugins/wiseeditor/js/wiseeditor.bundle.js',
        'statics/plugins/wiseeditor/css/wiseeditor.css',
        'statics/plugins/wiseeditor/js/vendor/tiptap.bundle.js',
    ];

    public function handle(Request $request, \Closure $next)
    {
        $this->setupTemplateReplace($request);
        $this->setupPluginUrls();

        try {
            $response = $next($request);
        } finally {
            $this->reportEnvironmentChecks();
        }

        if ($request->method() === 'GET'
            && $response instanceof \think\Response
            && strpos((string) $response->getHeader('Content-Type'), self::HTML_CONTENT_TYPE) !== false
        ) {
            $this->injectAssetVersion($response, $request);
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
        }
    }

    /**
     * 静态资源版本号注入：为 HTML 中尚未带版本参数的静态资源附加 ?_v= 缓存参数
     *
     * 按「整个标签」匹配再定位 src/href，而不是直接对 `src="..."` 正则替换：
     *   1. 只有整标签才能看见 href 之后的 rel（`<link href="x" rel="canonical">`），
     *      旧写法漏判会把 canonical 也加版本参数，污染 SEO；
     *   2. 只改 /statics/ 下白名单扩展名的同站资源 —— 用户上传（/uploads）、
     *      外链 CDN/OSS 签名地址不追参数（签名会被 query 变化直接打回 403）；
     *   3. 属性名要求非标识符边界，避免命中 data-src / xlink:href；
     *   4. 内联 <script> 的脚本体整体跳过：里面可能有 `'<img src="...">'` 这类
     *      模板字符串，改写会破坏业务 JS（等于往用户代码里塞缓存参数）。
     */
    protected function injectAssetVersion(\think\Response $response, Request $request): void
    {
        $content = $response->getContent();
        if ($content === '') {
            return;
        }

        $version = $this->resolveAssetVersion();
        $hosts   = $this->assetHosts($request);

        $rewrite = function (array $matches) use ($version, $hosts): string {
            $tag  = $matches[0];
            $name = strtolower($matches[1]);
            $attr = $name === 'link' ? 'href' : 'src';

            if (preg_match('/(?<![-\w:])' . $attr . '\s*=\s*(["\'])(.*?)\1/is', $tag, $found, PREG_OFFSET_CAPTURE) !== 1) {
                return $tag;
            }

            $full   = $found[0][0];
            $offset = $found[0][1];
            $quote  = $found[1][0];
            $url    = trim($found[2][0]);

            if ($name === 'link'
                && preg_match('/\brel\s*=\s*["\']?(canonical|alternate|prev|next)\b/i', $tag) === 1
            ) {
                return $tag;
            }

            if ($url === ''
                || stripos($url, 'data:') === 0
                || preg_match('/(^|[?&])_v=/', $url) === 1
                || !$this->isVersionableAsset($url, $hosts)
            ) {
                return $tag;
            }

            $newAttr = $attr . '=' . $quote . AssetVersion::appendTo($url, $version) . $quote;

            return substr($tag, 0, $offset) . $newAttr . substr($tag, $offset + strlen($full));
        };

        $parts = preg_split('/(<script\b[^>]*>.*?<\/script>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (!is_array($parts)) {
            return;
        }

        foreach ($parts as $index => $part) {
            $parts[$index] = $index % 2 === 1
                ? (string) preg_replace_callback('/<(img|script|link)\b[^>]*>/i', $rewrite, $part, 1)
                : (string) preg_replace_callback('/<(img|script|link)\b[^>]*>/i', $rewrite, $part);
        }

        $response->content(implode('', $parts));
    }

    /**
     * 允许加版本参数的主机名集合（当前请求 host + site.cdnurl host）
     *
     * @return string[]
     */
    protected function assetHosts(Request $request): array
    {
        $hosts = [];

        try {
            $hosts[] = strtolower((string) $request->host(true));
        } catch (\Throwable) {
        }

        $cdn = (string) Config::get('site.cdnurl', '');
        if ($cdn !== '') {
            $cdnHost = parse_url($cdn, PHP_URL_HOST);
            if (is_string($cdnHost) && $cdnHost !== '') {
                $hosts[] = strtolower($cdnHost);
            }
        }

        return array_values(array_unique(array_filter($hosts, static fn ($h) => $h !== '')));
    }

    /**
     * 是否是需要版本化的静态资源
     *
     * 判定顺序：同站（相对路径 / 当前 host / CDN host）→ /statics/ 前缀 → 扩展名白名单。
     */
    protected function isVersionableAsset(string $url, array $hosts): bool
    {
        if ($url === '' || $url[0] === '#' || str_starts_with($url, '//')) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        if (isset($parts['host']) && !in_array(strtolower($parts['host']), $hosts, true)) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        if (!str_starts_with($path, self::STATIC_PATH_PREFIX)) {
            return false;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, self::VERSIONABLE_EXT, true);
    }

    /**
     * 解析当前静态资源版本号
     *
     * 组成：site.version [.内核产物 mtime] . 全局计数器
     *  - 内核产物 mtime（生产）与全目录扫描（调试）：改完代码即失效，作为兜底；
     *  - 全局计数器：runtime/asset_version，只有「清理缓存 → 组件缓存」自增，
     *    覆盖所有动态加载的包/插件 js（见 AssetVersion::appendTo() 的消费方）。
     *
     * @return string 形如 "1.0.1.1763000000.17"
     */
    protected function resolveAssetVersion(): string
    {
        $version = (string) config('site.version');

        $latest = 0;

        if (config('app.debug')) {
            foreach (['statics/modules/', 'statics/admin/', 'statics/css/'] as $dir) {
                foreach (DirFile::recurDir(public_path() . $dir, ['*'], ['js', 'css']) as $file) {
                    $mtime  = @filemtime($file['fullName']);
                    $latest = max($latest, $mtime === false ? 0 : (int) $mtime);
                }
            }
        } else {
            foreach (self::ASSET_VERSION_FILES as $relative) {
                $file = public_path() . $relative;
                if (is_file($file)) {
                    $latest = max($latest, (int) filemtime($file));
                }
            }
        }

        if ($latest > 0) {
            $version .= '.' . $latest;
        }

        return $version . '.' . AssetVersion::get();
    }
}
