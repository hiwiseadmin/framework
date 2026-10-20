<?php

declare(strict_types=1);

namespace app\plugin\middleware;

use Closure;
use think\facade\Log;
use think\helper\Str;
use think\Request;
use wise\plugin\manifest\MenuPathNormalizer;

/**
 * 插件后台鉴权中间件（P0-1 · 框架层）
 *
 * 背景：`plugin` 应用原本没有挂载任何认证/授权中间件（`RbacMiddleware` 仅在
 * `admin` 应用注册，且 `EntryVerify` 将 `plugin` 列为免校验应用），
 * 导致所有插件后台接口（如 `/plugin/wisecms/newsletter/delete`、
 * `/plugin/wisedocs/settings/index`）可被匿名调用。详见 `docs/pluginhub-安全审计报告.md` C1、
 * `docs/wisedocs-功能与安全审计报告.md` C1。
 *
 * ⚠️ 注意守护边界（2026-10 迁包后新增的典型情形）：原 `pluginhub` 插件已迁为
 * `wiseadmin/wise-market` **库包**，其后台页面经 `ExtPoints::ROUTE_ADMIN` 投递到
 * **admin 应用**（由 `RbacMiddleware` 保护），**不在本中间件守护范围内** ——
 * 也正因如此，包的后台路由**绝不可**误投 `ROUTE_PLUGIN`（会绕过 RBAC 变成匿名可调）。
 *
 * 守护范围（三重收敛，避免误伤）：
 *   1. 仅守护继承 `plugin\BaseController` 的**后台**控制器；
 *      `plugin\FrontController`（前台）与 `plugin\ApiController`（JSON API）
 *      与其继承链不相交，天然不被守护；
 *   2. 支持 `config('plugin.admin_auth.except_routes')` 路由级豁免。
 *
 * 校验策略：
 *   - 登录：`auth_id('admin')`，未登录一律拒绝；
 *   - 权限：该路由存在 route→permission 映射时校验（超管由 RbacManager 内部放行）；
 *     鉴权子系统异常时**拒绝**（fail-closed）；
 *   - 无权限映射：默认放行（避免误伤尚未注册权限节点的插件），
 *     但列入 `config('plugin.admin_auth.fail_closed_plugins')` 的插件改为**拒绝**
 *     （审计 P0-2：否则任意最低权限账号可调用未登记的高危动作）。
 *   - 路由形态：候选 route 按「前导斜杠有/无」×「等价写法短式/长式」展开投递，
 *     因为 `auth_permissions.route` 存的是菜单 `path` 原样（可能是
 *     `/plugin/x/y` 也可能是 `/plugin/x/y/index`）—— 详见 {@see requiredPermissions()}。
 *
 * 回滚开关：`config('plugin.admin_auth.enabled')`；
 * 灰度开关：`config('plugin.admin_auth.fail_closed_plugins')`。
 */
class PluginAdminAuth
{
    /** 后台登录地址 */
    protected const ADMIN_LOGIN_URL = '/admin/login';

    /** 菜单 path 归一器（延迟构造：读取 route 默认动作 / 默认控制器） */
    protected ?MenuPathNormalizer $normalizer = null;

    public function normalizer(): MenuPathNormalizer
    {
        return $this->normalizer ??= MenuPathNormalizer::fromConfig();
    }

    /**
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // 总开关：便于整体回滚
        if (!config('plugin.admin_auth.enabled', true)) {
            return $next($request);
        }

        [$ident, $controller, $action] = $this->parse($request);
        if ($ident === '') {
            return $next($request);
        }

        // 路由级豁免
        if ($this->isExemptRoute($ident, $controller, $action)) {
            return $next($request);
        }

        $class = $this->resolveControllerClass($ident, $controller);
        if ($class === null) {
            // 无法解析（未注册命名空间 / 包投递路由）：放行，保持向后兼容
            return $next($request);
        }

        // 仅守护后台控制器基类
        if (!is_subclass_of($class, \plugin\BaseController::class)) {
            return $next($request);
        }

        $adminId = auth_id('admin');
        if (empty($adminId)) {
            $this->logDenied($request, 'unauthenticated', $ident, $controller, $action);

            return $this->denyUnauthenticated($request);
        }

        $permissions = $this->requiredPermissions($request, $ident, $controller, $action);

        // 无权限映射：默认放行（避免误伤尚未注册权限节点的插件）；
        // 列入 `fail_closed_plugins` 的插件则 fail-closed —— 未登记权限节点一律拒绝，
        // 否则「任何最低权限账号都能调用未登记的高危动作」（越权，审计 P0-2）。
        if (empty($permissions)) {
            if ($this->isFailClosed($ident)) {
                $this->logDenied($request, 'unmapped', $ident, $controller, $action);

                return $this->denyUnmapped($request, $ident, $controller, $action);
            }

            return $next($request);
        }

        if (!$this->checkPermission($adminId, $permissions)) {
            $this->logDenied($request, 'forbidden', $ident, $controller, $action);

            return $this->denyForbidden($request, $ident, $controller, $action);
        }

        return $next($request);
    }

    /**
     * 解析插件/控制器/操作。
     *
     * @return array{0:string,1:string,2:string}
     */
    protected function parse(Request $request): array
    {
        $path = trim((string) $request->pathinfo(), '/');
        $segments = $path === '' ? [] : explode('/', $path);

        if (isset($segments[0]) && strtolower($segments[0]) === 'plugin') {
            array_shift($segments);
        }

        return [
            (string) ($segments[0] ?? ''),
            (string) ($segments[1] ?? 'index'),
            (string) ($segments[2] ?? 'index'),
        ];
    }

    /**
     * 解析控制器类名（不存在返回 null）。
     */
    protected function resolveControllerClass(string $ident, string $controller): ?string
    {
        // 插件命名空间在 PluginDispatch 阶段才注册，而本中间件执行得更早。
        // 若不先确保注册，class_exists() 必为 false：
        //   - 判定失败会让「仅守护后台控制器」的三重收敛失去意义（鉴权被绕过）；
        //   - 该失败还会被 Composer 记入负缓存，连累 PluginDispatch 报 403。
        $this->ensurePluginNamespace($ident);

        $namespace = "plugin\\{$ident}\\controller\\";

        if (str_contains($controller, '.')) {
            $pos = strrpos($controller, '.');
            $base = substr($controller, 0, $pos);
            $class = $namespace . $base . '\\' . Str::studly(substr($controller, $pos + 1));
        } else {
            $class = $namespace . Str::studly($controller);
        }

        foreach ([$class, $class . 'Controller'] as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 确保目标插件的 PSR-4 命名空间已注册（幂等、失败静默）。
     *
     * 只注册命名空间、不触发插件完整 boot，因此不会在请求中期引入
     * 路由/中间件「半路生效」的副作用。
     */
    protected function ensurePluginNamespace(string $ident): void
    {
        try {
            /** @var \wise\plugin\PluginManager $manager */
            $manager = app(\wise\plugin\PluginContainerKeys::MANAGER);

            $manager->ensureNamespace($ident);
        } catch (\Throwable $e) {
            // 插件系统不可用 / 插件名非法：保持与原先一致的宽松行为
        }
    }

    /**
     * 路由级豁免判定（`plugin.admin_auth.except_routes`）。
     */
    protected function isExemptRoute(string $ident, string $controller, string $action): bool
    {
        $rules = (array) config('plugin.admin_auth.except_routes', []);
        if (empty($rules)) {
            return false;
        }

        $targets = [
            $ident,
            $ident . '/' . $controller,
            $ident . '/' . $controller . '/' . $action,
        ];

        foreach ($rules as $rule) {
            $rule = trim(strtolower((string) $rule), '/');
            if ($rule === '') {
                continue;
            }
            $regex = '#^' . str_replace('\*', '.*', preg_quote($rule, '#')) . '$#';
            foreach ($targets as $target) {
                if (preg_match($regex, strtolower($target))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 解析该路由在权限系统中的映射（无映射返回空数组）。
     *
     * ⚠️ 候选 route 必须「多形态投递」，因为 `auth_permissions.route` 存的是
     * **菜单 `path` 原样**（`MenuPermissionBridge::transform()`），而
     * `RbacManager::routeMatch()` 是精确/通配匹配。历史上有两个维度都曾漏投：
     *
     * 1. **前导斜杠**：权限表存的是带斜杠的菜单 path，本方法曾只构造无前导斜杠的候选，
     *    导致插件侧 route→permission **恒不匹配**（权限节点形同虚设，接口只校验登录）。
     *    现同时投递两种写法；`RbacManager::routeMatch()` 亦已加入前导斜杠归一
     *    （2026-09-24），故此处属**纵深防御**。
     * 2. **等价写法（短式 / 长式）**：菜单 path 可能是 `/plugin/x/y` 也可能是
     *    `/plugin/x/y/index`（两者运行时等价，见 {@see MenuPathNormalizer}）。若只投请求
     *    命中的那一种写法，声明了另一种写法的权限就匹配不到 —— 而插件侧
     *    「未匹配到权限 → 放行」，等于放行了一条「在 URL 末尾多写个 `/index`」的绕过路径。
     *    现按等价写法全集展开（`MenuPathNormalizer::expandCandidates()`）。
     *
     * ⚠️ 行为变化提示：候选变多 → 会有更多插件路由**真正参与鉴权**（fail-closed）。
     * 原本因写法不匹配而被放行的路由，现在可能对未授权角色返回 403，上线前请核对角色授权。
     *
     * @return string[]
     */
    protected function requiredPermissions(Request $request, string $ident, string $controller, string $action): array
    {
        try {
            /** @var \wise\auth\service\RbacManager $rbac */
            $rbac = app(\wise\auth\service\RbacManager::class);
        } catch (\Throwable $e) {
            return [];
        }

        $candidates = [
            'plugin/' . $ident . '/' . $controller . '/' . $action,
            trim((string) $request->pathinfo(), '/'),
        ];

        // 展开为「多形态候选集合」，两个维度：
        //   1. 前导斜杠有/无（§8.2：权限表存带斜杠的菜单 path，请求侧曾只投无斜杠形态）；
        //   2. 等价写法短式/长式（`MenuPathNormalizer::variants()`）：权限表存的是菜单
        //      path 原样，`/plugin/x/y` 与 `/plugin/x/y/index` 都可能存在。只投一种写法
        //      会让另一种「匹配不到 → 未声明 → 放行」，即「URL 末尾加个 /index」即可绕过权限。
        $routes = $this->normalizer()->expandCandidates($candidates);

        $permissions = [];
        foreach ($routes as $route) {
            try {
                $permissions = array_merge($permissions, $rbac->matchRouteToPermissions($route, $request->method()));
            } catch (\Throwable $e) {
                // 忽略单条路由匹配异常
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * 权限校验
     *
     * 超管由 `checkAny()` 内部直接放行；鉴权子系统异常时**拒绝**（fail-closed）。
     * 原实现异常即放行，等于「鉴权组件一旦故障，所有插件后台动作对任意登录账号开放」
     * （审计 P0-2），故改为拒绝并告警。
     *
     * @param int|string $adminId
     * @param string[]   $permissions
     */
    protected function checkPermission($adminId, array $permissions): bool
    {
        try {
            /** @var \wise\auth\service\RbacManager $rbac */
            $rbac = app(\wise\auth\service\RbacManager::class);

            return (bool) $rbac->checkAny($adminId, 'admin', $permissions);
        } catch (\Throwable $e) {
            Log::warning('[plugin-admin-auth] 权限校验异常，按 fail-closed 拒绝：' . $e->getMessage());

            return false;
        }
    }

    /**
     * 该插件是否采用 fail-closed（未匹配到权限节点即拒绝）。
     *
     * 配置：`plugin.admin_auth.fail_closed_plugins`（插件 ident 列表，支持 `*` 表示全部）。
     * 默认空数组 = 保持历史「无映射则放行」行为，避免一次性影响全部插件；
     * 逐插件纳入后，需同步在该插件 manifest 的 `permissions` 中登记后台动作权限节点。
     */
    protected function isFailClosed(string $ident): bool
    {
        $plugins = (array) config('plugin.admin_auth.fail_closed_plugins', []);

        foreach ($plugins as $plugin) {
            $plugin = strtolower(trim((string) $plugin));
            if ($plugin === '') {
                continue;
            }
            if ($plugin === '*' || $plugin === strtolower($ident)) {
                return true;
            }
        }

        return false;
    }

    /**
     * fail-closed 拒绝：该路由在权限系统中没有对应的权限节点。
     */
    protected function denyUnmapped(Request $request, string $ident, string $controller, string $action)
    {
        $msg = '该操作尚未登记权限节点，已按安全策略拒绝（' . $ident . '/' . $controller . '/' . $action . '）'
            . '；请为对应角色授予权限，或在插件 manifest 的 permissions 中补充该路由。';

        if ($this->wantsJson($request)) {
            return json(['code' => 0, 'msg' => $msg, 'data' => null], 403);
        }

        return response(
            $this->deniedHtml('该页面尚未登记权限节点', $msg, '补登记后即可访问；如需临时放行，请把该插件移出 fail_closed_plugins。'),
            403,
            [],
            'html'
        );
    }

    /**
     * 拒绝页 HTML（自带样式，不依赖后台壳与任何静态资源）。
     *
     * 为什么不用 `response($msg, 403)` 裸文本：用户在后台点菜单进来时，裸文本页
     * 既没有导航也没有返回入口，等同于「点进去就一片白板」，无法自助脱困
     * （实机复核见 docs/wisecms-后台UIUX-评审建议.md §9.1 L2）。
     *
     * @param string $title 主标题
     * @param string $detail 原因/技术细节（会做 HTML 转义）
     * @param string $hint 处置建议
     */
    protected function deniedHtml(string $title, string $detail, string $hint = ''): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $e($title) . '</title><style>'
            . ':root{color-scheme:light dark}'
            . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'font:14px/1.6 system-ui,-apple-system,"Segoe UI","Microsoft YaHei",sans-serif;'
            . 'background:#f6f7f9;color:#1f2329}'
            . '.card{max-width:560px;margin:24px;padding:28px 28px 24px;border-radius:12px;background:#fff;'
            . 'box-shadow:0 2px 16px rgba(0,0,0,.08)}'
            . '.icon{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;'
            . 'background:#fdecea;color:#d93025;font-size:22px;margin-bottom:14px}'
            . 'h1{font-size:18px;margin:0 0 8px}'
            . 'p{margin:0 0 10px;color:#5f6368}'
            . 'code{background:#f1f3f4;padding:1px 5px;border-radius:4px;font-size:12px;word-break:break-all}'
            . '.actions{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap}'
            . 'a{display:inline-block;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:13px}'
            . '.primary{background:#1a73e8;color:#fff}.ghost{border:1px solid #dadce0;color:#3c4043}'
            . '@media (prefers-color-scheme:dark){body{background:#17181a;color:#e8eaed}'
            . '.card{background:#202124;box-shadow:0 2px 16px rgba(0,0,0,.5)}'
            . '.icon{background:#3c1f1c;color:#f28b82}p{color:#9aa0a6}'
            . 'code{background:#2d2e30}.ghost{border-color:#5f6368;color:#e8eaed}}'
            . '</style></head><body><div class="card">'
            . '<div class="icon">!</div>'
            . '<h1>' . $e($title) . '</h1>'
            . '<p>' . $e($detail) . '</p>'
            . ($hint !== '' ? '<p style="font-size:12px">' . $e($hint) . '</p>' : '')
            . '<div class="actions">'
            . '<a class="primary" href="/admin/index">返回控制台</a>'
            . '<a class="ghost" href="javascript:history.back()">返回上一页</a>'
            . '</div></div></body></html>';
    }

    /**
     * 未登录：JSON 返回 401，页面跳转后台登录。
     */
    protected function denyUnauthenticated(Request $request)
    {
        if ($this->wantsJson($request)) {
            return json(['code' => 0, 'msg' => '请先登录后再操作', 'data' => null], 401);
        }

        return redirect(self::ADMIN_LOGIN_URL);
    }

    /**
     * 无权限：JSON 返回 403，页面返回带样式的 403 页（含返回入口）。
     */
    protected function denyForbidden(Request $request, string $ident, string $controller, string $action)
    {
        $msg = '没有访问权限（' . $ident . '/' . $controller . '/' . $action . '）';

        if ($this->wantsJson($request)) {
            return json(['code' => 0, 'msg' => $msg, 'data' => null], 403);
        }

        return response(
            $this->deniedHtml('没有访问权限', $msg, '请联系管理员为当前角色授予该权限节点。'),
            403,
            [],
            'html'
        );
    }

    /**
     * 记录拒绝日志，便于 P0-1 灰度期排查受影响插件。
     */
    protected function logDenied(Request $request, string $reason, string $ident, string $controller, string $action): void
    {
        try {
            Log::warning('[plugin-admin-auth] 拦截插件后台请求', [
                'reason'     => $reason,
                'plugin'     => $ident,
                'controller' => $controller,
                'action'     => $action,
                'ip'         => $request->ip(),
                'url'        => $request->url(),
            ]);
        } catch (\Throwable $e) {
            // 日志失败不影响主流程
        }
    }

    /**
     * 是否为期望 JSON 响应的请求。
     */
    protected function wantsJson(Request $request): bool
    {
        return $request->isAjax() || $request->isJson() || $request->isPjax();
    }
}
