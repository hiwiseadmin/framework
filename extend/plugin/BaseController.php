<?php
namespace plugin;

use app\common\middleware\VerifyCsrfToken;
use think\facade\Config;
use think\facade\Route;
use think\facade\View;
use app\common\controller\BaseController as CommonBaseController;
use app\common\service\ConfigService;
use app\common\service\ThemeService;
use plugin\traits\PluginTrait;

/**
 * 插件后台控制器基类（合并版）
 *
 * 所有插件**后台管理**控制器应继承此类，自动获得：
 * - AdminTrait（管理权限校验、Subframe 布局）
 * - plugin\PluginTrait 全部能力（ident 解析、视图路径、配置访问、模板变量）
 * - 插件内部 URL 生成与重定向
 * - 插件路由注册
 * - JS/视图路径自动配置（视图按 scope 解析，空参数 fetch() 自动落位）
 * - **CSRF 防护**（见下方 $middleware）
 *
 * 前端/API 场景请使用 plugin\FrontController 或 plugin\ApiController。
 *
 * 继承链: app\BaseController → app\common\controller\BaseController → plugin\BaseController
 */
abstract class BaseController extends CommonBaseController
{
    use PluginTrait;

    /**
     * 控制器级中间件（框架默认）
     *
     * ── 插件后台 CSRF 防护的「统一三件套」────────────────────────────
     *
     * 与 admin 应用（app/admin/middleware.php）等价，插件后台由框架统一提供，
     * **插件自身无需实现任何 CSRF 代码**：
     *
     *   ① 校验层（本属性）：`VerifyCsrfToken` 由 PluginDispatch 读取并注册为
     *      控制器级中间件 —— 写请求（POST/PUT/DELETE/PATCH）校验令牌，只读请求
     *      仅补齐令牌，令牌校验通过后轮换（防 BREACH）。
     *   ② 令牌来源（视图层）：`extend/plugin/view/base.html` 已输出 `{:csrf_meta()}`，
     *      插件后台页面只要继承该布局即自动带上 `<meta name="csrf-token">`。
     *   ③ 自动携带（前端层）：`wise-framework.js` 的 `$(document).ajaxSend` 为全部
     *      jQuery 请求注入 `X-CSRF-TOKEN`，令牌优先读 Cookie（始终是最新值）。
     *      插件用 AJAX 提交写请求 **无需任何额外代码**。
     *
     * 仅当使用**原生 `<form method="post">`** 提交时，需在表单内加一行 `{:csrf_field()}`
     * （AJAX 提交不需要；这是浏览器原生表单无法自动携带请求头的固有限制）。
     *
     * ── 为什么放在控制器基类，而不是 app/plugin/middleware.php ──────────
     *
     * `plugin` 应用同时承载「插件后台」（PluginDispatch → /plugin/*）与
     * 「默认前端插件」（IndexDispatch → 前台任意 URL，见 src/route/miss.php）。
     * 若挂到应用级中间件，匿名访客访问前台任意页面都会被写入 csrf_token Cookie
     * （`ensureToken()` 首次访问即 Set-Cookie），破坏全页缓存/CDN，且前台只读
     * 场景根本不需要。
     *
     * 放在本基类则天然收敛：仅后台控制器生效（`plugin\FrontController` 与
     * `plugin\ApiController` 不在本继承链上），前台与 JWT API 均不受影响。
     *
     * ── 子类覆盖须知（重要）─────────────────────────────────────────
     *
     * PHP 属性覆盖是**替换**而非合并：子类一旦声明 `$middleware`，本属性即失效。
     * 因此需要追加自定义中间件的插件基类，必须把 `VerifyCsrfToken` 一并写回，例如
     * `plugins/wisedocs/controller/Base.php` 的 `[DocsAuth::class, VerifyCsrfToken::class]`。
     */
    protected $middleware = [
        VerifyCsrfToken::class,
    ];

    /**
     * 初始化
     */
    protected function initialize()
    {
        // 1. 插件上下文初始化（PluginTrait：解析 ident、注入模板变量）
        $this->pluginInitialize();

        // 2. 配置 JS 控制器路径（ESM 模式，相对于 wise-bootstrap.js）
        $this->setupJsPath();

        // 3. 声明本插件的视图 scope（空参数 fetch() 据此自动落位）
        $this->bindViewScope();

        // 4. 模板变量注入（pluginName、pluginBaseView）
        View::assign([
            'pluginName'     => $this->pluginIdent,
            'pluginBaseView' => root_path('extend') . 'plugin/view/base.html',
        ]);

        // 5. 触发子类 initPlugin 钩子
        $this->initPlugin();

        // 6. 父类初始化（触发 _initialize 钩子）
        parent::initialize();
    }

    /**
     * 插件自定义初始化钩子（子类可重写）
     */
    protected function initPlugin(): void
    {
    }

    // ── JS 路径配置 ──────────────────────────────────────

    /**
     * 配置前端运行时（对标 admin 的 AdminTrait::assignData）
     *
     * 插件请求不经由 admin 的 BaseController，AdminTrait 不会执行；
     * 若不在此补齐，插件页面会出现：站点名为空、主题恒为 dark、
     * wsconfig.action 缺失导致 wise-bootstrap 只能回退到 index 方法。
     *
     * 已存在的键不会被覆盖（保持上层已注入的值优先），
     * 但 js 路径始终由插件自身决定（ESM 模式，相对于 wise-bootstrap.js）。
     */
    protected function setupJsPath(): void
    {
        if (!$this->pluginIdent) {
            return;
        }

        $modulename = app('http')->getName();
        $controller = $this->getControllerShortName();
        $action     = $this->request->action();

        $site   = Config::get('site', []);
        $upload = Config::get('attachment.upload', []);
        $upload['thumbstyle'] = $upload['thumbstyle'] ?? '';

        $defaults = [
            'site'       => array_intersect_key((array) $site, array_flip(['name', 'host', 'cdnurl', 'version', 'timezone', 'languages'])),
            'upload'     => $upload,
            'modulename' => $modulename,
            'controller' => $controller,
            'action'     => $action,
            'moduleurl'  => rtrim((string) url("/{$modulename}", [], false), '/'),
        ];

        $wsconfig = View::get('wsconfig') ?: [];
        View::assign('wsconfig', array_merge($defaults, (array) $wsconfig, [
            'js' => '../plugins/' . $this->pluginIdent . '/js/' . $controller,
        ]));

        // 主题配置（优先级：浏览器 Cookie 最新选择 > 用户级 ext_fields > 网站默认）
        $theme = ThemeService::resolve($this->clientThemeFromCookie());
        $attrs = ThemeService::toHtmlAttributes($theme);

        $wssite = View::get('wssite') ?: [];
        View::assign('wssite', array_merge(
            (array) $site,
            [
                'theme'   => $attrs['bs_theme'],
                'scheme'  => $attrs['color_scheme'],
                'sidebar' => $attrs['ws_sidebar'],
            ],
            (array) $wssite
        ));

        // 主题上下文（供防闪烁引导脚本读取）
        View::assign('wstheme', ThemeService::toBootstrapContext());
    }

    /**
     * 获取当前登录管理员ID
     *
     * 未登录或不可用时返回 null，配置读取自动降级为网站级。
     *
     * @return int|null
     */
    protected function currentAdminId(): ?int
    {
        try {
            $id = (int) \app\admin\facade\CurrentAdmin::getAdminId();
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 从本浏览器 Cookie 读取客户端最新主题选择
     *
     * 前端 wise-theme.js 在每次切换后立即写入 ws_theme Cookie，
     * 而服务端 ext_fields 是防抖后才落库。优先读 Cookie 可消除
     * 「iframe 单独刷新落在防抖窗口内读到旧值」的时序问题。
     *
     * @return array{mode?:string, scheme?:string, sidebar?:string}|null
     */
    protected function clientThemeFromCookie(): ?array
    {
        try {
            $raw = \think\facade\Cookie::get(ThemeService::COOKIE_KEY);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $json = json_decode(rawurldecode($raw), true);
            if (!is_array($json)) {
                $json = json_decode($raw, true);
            }
            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── URL 工具 ─────────────────────────────────────────

    /**
     * 生成插件内部 URL
     *
     * 自动补全 /plugin/{ident}/{controller}/{action} 前缀。
     *
     * @param string $route  控制器/操作（如 "index" 或 "article/detail"）
     * @param array  $params URL 参数
     * @param bool   $full   是否返回完整域名 URL
     */
    protected function pluginUrl(string $route = '', array $params = [], bool $full = false): string
    {
        if ($route === '') {
            $route = $this->request->action();
        }

        if (strpos($route, '/') === 0) {
            $url = $route;
        } else {
            $parts = explode('/', $route);
            if (count($parts) === 1) {
                $controller = $this->getControllerShortName();
                $action     = $parts[0];
            } else {
                $controller = $parts[0];
                $action     = $parts[1] ?? 'index';
            }
            $url = "/plugin/{$this->pluginIdent}/{$controller}/{$action}";
        }

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        if ($full) {
            $url = $this->request->domain() . $url;
        }

        return $url;
    }

    /**
     * 重定向到插件内部 URL
     *
     * @param string $route  路由
     * @param array  $params 参数
     * @param int    $code   HTTP 状态码
     */
    protected function pluginRedirect(string $route = '', array $params = [], int $code = 302)
    {
        return redirect($this->pluginUrl($route, $params), $code);
    }

    // ── 路由注册 ─────────────────────────────────────────

    /**
     * 注册插件内部路由
     *
     * 自动补全 /plugin/{ident} 前缀。
     *
     * @param string $rule   路由规则（不含前缀）
     * @param string $route  目标（Controller@action）
     * @param string $method HTTP 方法，默认 *
     */
    protected function registerPluginRoute(string $rule, string $route, string $method = '*')
    {
        $prefix = "plugin/{$this->pluginIdent}";
        $rule = ltrim($rule, '/');
        $fullRule = $rule !== '' ? "{$prefix}/{$rule}" : $prefix;

        return Route::rule($fullRule, $route, $method);
    }

    // ── 工具方法 ─────────────────────────────────────────

    /**
     * 调用插件公共函数
     *
     * 按约定 {ident}_{function} 查找并调用。
     *
     * @throws \Exception
     */
    protected function callPluginFunction(string $function, ...$args)
    {
        if (!$this->pluginIdent) {
            throw new \Exception('无法确定插件标识');
        }

        // 防止函数名注入，仅允许合法 PHP 函数名格式
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $function)) {
            throw new \Exception("插件函数名格式无效: {$function}");
        }

        $funcName = $this->pluginIdent . '_' . $function;
        if (!function_exists($funcName)) {
            throw new \Exception("插件函数 {$funcName} 不存在");
        }

        return $funcName(...$args);
    }
}
