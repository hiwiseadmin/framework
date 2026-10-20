<?php
namespace app\admin\controller\traits;

use think\facade\Session;
use think\facade\Config;
use think\facade\View;
use think\facade\Request;
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
            // 登录页等未登录场景，Auth 服务不可用
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
            // 前端 encodeURIComponent 编码，兼容已解码/未解码两种情况
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

        // 页面控制器 JS 路径：admin 应用的控制器脚本发布在 statics/admin/ 下。
        // 它是「可选增强」——并不要求每个控制器都有同名脚本，因此**仅当文件真实存在时**
        // 才下发 `js`；否则 wise-bootstrap 会对不存在的文件发起一次必然 403 的动态 import，
        // 在控制台留下「[Wise:core] 控制器加载失败」报错（如 system_message、market/plugin）。
        $controllerJs = str_replace('.', '/', $controller);
        $pageControllerJs = '';
        $pageControllerVersion = '';
        if ($controllerJs !== '' && !str_contains($controllerJs, '..')) {
            $jsFile = public_path('statics') . DIRECTORY_SEPARATOR . 'admin'
                . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $controllerJs) . '.js';
            if (is_file($jsFile)) {
                $pageControllerJs = '../admin/' . $controllerJs;
                // 逐文件版本：wise-bootstrap 动态 import 时追加 ?v=，避免浏览器
                // 启发式缓存导致「JS 已更新、页面仍执行旧逻辑」
                $pageControllerVersion = (string) filemtime($jsFile);
            }
        }

        $path = $controllerJs . '/' . $action;

        $site = Config::get("site");

        // 上传配置
        $upload = Config::get('attachment.upload', []);
        if (!isset($upload['thumbstyle'])) {
            $upload['thumbstyle'] = '';
        }

        // 配置信息
        $config = [
            'site'       => array_intersect_key($site, array_flip(['name', 'host', 'cdnurl', 'version', 'timezone', 'languages'])),
            'upload'     => $upload,
            'modulename' => $modulename,
            'controller' => $controller,
            'action'     => $action,
            // 页面控制器脚本不存在时置空：wise-bootstrap 见空值即跳过 import（见上方说明）
            'js'         => $pageControllerJs,
            // 控制器脚本版本（文件 mtime）：wise-bootstrap 动态 import 时追加 ?v=
            'jsv'        => $pageControllerVersion,
            'moduleurl'  => rtrim(url("/{$modulename}", [], false), '/'),
        ];

        // 主题配置（优先级：浏览器 Cookie 最新选择 > 用户级 ext_fields > 网站默认）
        $theme = ThemeService::resolve($this->clientThemeFromCookie());
        $attrs = ThemeService::toHtmlAttributes($theme);

        // 合并主题配置到站点变量，供模板输出 <html> data-* 属性
        $site = array_merge($site, [
            'theme'   => $attrs['bs_theme'],
            'scheme'  => $attrs['color_scheme'],
            'sidebar' => $attrs['ws_sidebar'],
        ]);

        // 渲染站点配置
        View::assign('wssite', $site);

        // 渲染主题上下文（供防闪烁引导脚本读取）
        View::assign('wstheme', ThemeService::toBootstrapContext());

        // 渲染配置信息
        View::assign('wsconfig', $config);

        // 官网 url
        View::assign('wsurl', 'https://www.wiseadmin.top');

        // 插槽系统 / Dashboard 面板：注入同一套权限检查器
        // 回调语义：fn(string $slug): bool —— 超管放行，否则匹配权限 slug/name
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
                    // 权限服务不可用时默认放行
                    return true;
                }
            };
            SlotManager::setPermissionChecker($permissionChecker);
            DashboardRegistry::setPermissionChecker($permissionChecker);
        }

        // 插槽系统：按页面注入插槽元数据
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
                            'url' => $cfg['url'],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // 扩展点不可用时跳过
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
        // 子框架（iframe 内页）不注入
        if (Session::get('isSubframe') === '1') {
            return false;
        }

        // 未登录（登录页 / 锁屏 / 各类验证页）不注入
        return $this->currentAdminId() !== null;
    }
}
