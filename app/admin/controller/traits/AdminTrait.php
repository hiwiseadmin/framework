<?php
namespace app\admin\controller\traits;

use think\facade\Session;
use think\facade\Config;
use think\facade\View;
use think\facade\Request;
use app\common\service\AssetVersion;
use app\common\service\ConfigService;
use app\common\service\ThemeService;
use app\common\slot\SlotManager;
use app\common\dashboard\DashboardRegistry;

/**
 * Admin 应用专属 Trait
 *
 * 提供后台管理界面通用能力：模板数据注入、子框架模式、初始化钩子。
 *
 * 使用方式：
 * - 被 app\admin\controller\BaseController 自动引入
 * - 所有 admin 控制器继承 BaseController 即可获得这些能力
 */
trait AdminTrait
{
    /** 本次请求的资源版本号缓存（同一页面 assignData / assignFeatureModules 各读一次文件不划算） */
    protected ?string $assetVersionCache = null;

    /**
     * 当前静态资源版本号
     *
     * 与 HTML 里的 ?_v= 同一真源（见 AssetVersion），因此点一次「清理缓存 → 组件缓存」
     * 后，服务端渲染的 src/href 与前端动态 import 的 URL 会同步换版本。
     */
    protected function assetVersion(): string
    {
        return $this->assetVersionCache ??= (string) AssetVersion::get();
    }

    /**
     * 设置是否为子框架模式
     * @param bool $subframe
     * @return void
     */
    protected function setSubframe(bool $subframe = true): void
    {
        Session::flash('isSubframe', $subframe ? '1' : '0');
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
     * 前端 wise-theme.js 在每次切换后立即写入 ws_theme Cookie（encodeURIComponent 后的 JSON），
     * 而服务端 ext_fields 是防抖（800ms）后才落库。iframe 单独刷新恰好落在防抖窗口内时，
     * 读 ext_fields 会拿到旧值；优先读 Cookie 可消除该时序窗口。
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

    /**
     * 分配后台通用的模板变量
     * @return void
     */
    protected function assignData(): void
    {
        $modulename = app('http')->getName();
        $controller = parse_name(Request::controller());
        $action     = strtolower(Request::action());

        $controllerJs = str_replace('.', '/', $controller);
        $pageControllerJs = '';
        $pageControllerVersion = '';
        if ($controllerJs !== '' && !str_contains($controllerJs, '..')) {
            $jsFile = public_path('statics') . DIRECTORY_SEPARATOR . 'admin'
                . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $controllerJs) . '.js';
            if (is_file($jsFile)) {
                $pageControllerJs = '../admin/' . $controllerJs;
                $pageControllerVersion = (string) filemtime($jsFile);
            }
        }

        $site = Config::get("site");
        $site['name'] = \wise\config\facade\WiseConfig::get('system:site:site_name');
        
        $upload = (array) Config::get('wise-attachment.upload', []);
        $legacyUpload = Config::get('attachment.upload', []);
        if (is_array($legacyUpload) && $legacyUpload) {
            $upload = array_merge($upload, $legacyUpload);
        }
        if (!isset($upload['thumbstyle'])) {
            $upload['thumbstyle'] = '';
        }

        $config = [
            'site'       => array_intersect_key($site, array_flip(['name', 'host', 'cdnurl', 'version', 'timezone', 'languages'])),
            'upload'     => $upload,
            'modulename' => $modulename,
            'controller' => $controller,
            'action'     => $action,
            'js'         => $pageControllerJs,
            'jsv'        => $pageControllerVersion,
            'moduleurl'  => rtrim(url("/{$modulename}", [], false), '/'),
            'assetVersion' => $this->assetVersion(),
        ];

        $theme = ThemeService::resolve($this->clientThemeFromCookie());
        $attrs = ThemeService::toHtmlAttributes($theme);

        $site = array_merge($site, [
            'theme'   => $attrs['bs_theme'],
            'scheme'  => $attrs['color_scheme'],
            'sidebar' => $attrs['ws_sidebar'],
        ]);

        View::assign('wssite', $site);

        View::assign('wstheme', ThemeService::toBootstrapContext());

        View::assign('wsconfig', $config);

        View::assign('wsurl', 'https://www.wiseadmin.top');

        if (class_exists(\app\admin\service\AdminService::class)) {
            $permissionChecker = function (string $slug): bool {
                try {
                    $adminService = app(\app\admin\service\AdminService::class);
                    if ($adminService->isSuperAdmin()) {
                        return true;
                    }
                    $permissions = $adminService->getPermissions();
                    foreach ($permissions as $perm) {
                        if (($perm['slug'] ?? $perm['name'] ?? '') === $slug) {
                            return true;
                        }
                    }
                    return false;
                } catch (\Throwable $e) {
                    return true;
                }
            };
            SlotManager::setPermissionChecker($permissionChecker);
            DashboardRegistry::setPermissionChecker($permissionChecker);
        }

        View::assign('wsslots', SlotManager::toFrontendData($controller));
    }

    /**
     * 分配后台前端功能模块（包/插件通过 ExtPoint 注册，如 AI 助手）
     *
     * 调用时机：必须晚于 parent::initialize()。子类的 _initialize() 在那里才被触发，
     * 而 Index / Login 正是在其中把 isSubframe 覆盖为 false（其余控制器保持默认 true）。
     * 功能模块属于「后台主框架的全局浮层」，判定依赖该值，放在 assignData() 里会读到
     * 尚未被覆盖的默认 true，从而对所有页面一视同仁地注入。
     *
     * 注入条件（需同时满足）：
     *   - 非子框架页：iframe 内页不承载全局浮层（模块内部也会再次判断，这里直接不注入，
     *     避免每个 iframe 内页都白下载、白解析一遍）；
     *   - 已登录：登录页同样 extend public/base，未登录时注入只会留下一个点了没反应的
     *     浮动按钮。
     *
     * @return void
     */
    protected function assignFeatureModules(): void
    {
        $featureModules = [];

        if ($this->shouldLoadFeatureModules()) {
            try {
                foreach (\wise\support\ExtPoint::all(\wise\support\ExtPoints::ADMIN_FRONTEND_MODULE) as $name => $item) {
                    $concrete = $item['concrete'] ?? null;
                    $cfg = is_callable($concrete) ? $concrete() : $concrete;
                    if (is_array($cfg) && !empty($cfg['url'])) {
                        $featureModules[] = [
                            'key' => $cfg['key'] ?? $name,
                            'url' => AssetVersion::appendTo((string) $cfg['url'], $this->assetVersion()),
                        ];
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        View::assign('wsFeatureModules', $featureModules);
    }

    /**
     * 当前请求是否应注入后台前端功能模块
     * @return bool
     */
    protected function shouldLoadFeatureModules(): bool
    {
        if (Session::get('isSubframe') === '1') {
            return false;
        }

        return $this->currentAdminId() !== null;
    }
}
