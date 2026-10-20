<?php

declare(strict_types=1);

namespace plugin\wisedocs\middleware;

use Closure;
use think\facade\Log;
use think\helper\Str;
use think\Request;

/**
 * WiseDocs 后台鉴权中间件（P0-2 · 插件层兜底）
 *
 * 背景：`plugin` 应用原本没有任何登录/RBAC 校验（审计问题 C1），
 * 导致 `/plugin/wisedocs/{settings,stats,tag,...}` 可被匿名调用。
 * 本中间件只守护「本插件的后台控制器」，与框架层方案（P0-1）互为双保险。
 *
 * 守护范围（三重收敛，避免误伤）：
 *   1. 仅 `wisedocs` 插件请求（本中间件可能被全局注册）；
 *   2. 仅后台控制器 —— 即继承 `plugin\wisedocs\controller\Base` 的类；
 *      前台控制器（`plugin\FrontController` 体系）与不存在的类一律放行；
 *   3. 支持控制器 `noNeedLogin / noNeedAuth / noNeedRight` 白名单。
 *
 * 权限模型：要求 `wisedocs.{controller}.{action}` 或 `wisedocs.{controller}.*`
 * （action 统一转 snake_case，如 `getList` → `get_list`）。
 * 超级管理员（config `app.super_admin_ids`）直接放行；权限系统不可用时默认拒绝。
 */
class DocsAuth
{
    /** 插件标识 */
    protected const IDENT = 'wisedocs';

    /** 后台登录地址 */
    protected const ADMIN_LOGIN_URL = '/admin/login';

    /**
     * @param Request  $request
     * @param Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        [$ident, $controller, $action] = $this->parse($request);

        // 1) 非本插件请求（全局注册场景）：放行
        if ($ident !== self::IDENT) {
            return $next($request);
        }

        $class = $this->resolveControllerClass($ident, $controller);

        // 2) 只守护后台控制器；前台/未知控制器放行
        if ($class === null || !is_subclass_of($class, \plugin\wisedocs\controller\Base::class)) {
            return $next($request);
        }

        // 3) 控制器声明的白名单动作
        if ($this->isWhitelisted($class, $action)) {
            return $next($request);
        }

        $adminId = auth_id('admin');
        if (empty($adminId)) {
            return $this->denyUnauthenticated($request);
        }

        if (!$this->hasPermission($adminId, $controller, $action)) {
            return $this->denyForbidden($request, $controller, $action);
        }

        return $next($request);
    }

    /**
     * 解析请求中的插件/控制器/操作。
     *
     * pathinfo 形如 `wisedocs/settings/index`（插件应用内）；
     * 兼容可能带 `plugin/` 前缀的写法。
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
     * 由插件/控制器段解析出控制器类名（不存在返回 null）。
     */
    protected function resolveControllerClass(string $ident, string $controller): ?string
    {
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
     * 动作是否被控制器显式放行（`noNeedLogin / noNeedAuth / noNeedRight`，支持 `*`）。
     */
    protected function isWhitelisted(string $class, string $action): bool
    {
        try {
            $defaults = (new \ReflectionClass($class))->getDefaultProperties();
        } catch (\Throwable $e) {
            return false;
        }

        $action = strtolower($action);

        foreach (['noNeedLogin', 'noNeedAuth', 'noNeedRight'] as $key) {
            $list = $defaults[$key] ?? [];
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $item) {
                $item = strtolower((string) $item);
                if ($item === '*' || $item === $action) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 校验后台权限（超管放行；权限系统不可用时默认拒绝）。
     *
     * @param int|string $adminId
     */
    protected function hasPermission($adminId, string $controller, string $action): bool
    {
        $superIds = array_map('intval', (array) config('app.super_admin_ids', [1]));
        if (in_array((int) $adminId, $superIds, true)) {
            return true;
        }

        $controllerKey = Str::snake(str_replace('\\', '_', $controller));
        $actionKey     = Str::snake($action);

        $candidates = [
            self::IDENT . '.' . $controllerKey . '.' . $actionKey,
            self::IDENT . '.' . $controllerKey . '.*',
        ];

        try {
            /** @var \wise\auth\service\RbacManager $rbac */
            $rbac = app(\wise\auth\service\RbacManager::class);

            return (bool) $rbac->checkAny($adminId, 'admin', $candidates);
        } catch (\Throwable $e) {
            Log::warning('[wisedocs] 权限校验失败，默认拒绝：' . $e->getMessage());

            return false;
        }
    }

    /**
     * 未登录：JSON 请求返回 401，页面请求跳转后台登录。
     */
    protected function denyUnauthenticated(Request $request)
    {
        if ($this->wantsJson($request)) {
            return json(['code' => 0, 'msg' => '请先登录后再操作', 'data' => null], 401);
        }

        return redirect(self::ADMIN_LOGIN_URL);
    }

    /**
     * 无权限：JSON 请求返回 403，页面请求返回 403 文本。
     */
    protected function denyForbidden(Request $request, string $controller, string $action)
    {
        $msg = '没有访问权限（缺少权限：' . self::IDENT . '.' . Str::snake($controller) . '.' . Str::snake($action) . '）';

        if ($this->wantsJson($request)) {
            return json(['code' => 0, 'msg' => $msg, 'data' => null], 403);
        }

        return response($msg, 403, [], 'html');
    }

    /**
     * 是否为期望 JSON 响应的请求。
     */
    protected function wantsJson(Request $request): bool
    {
        return $request->isAjax() || $request->isJson() || $request->isPjax();
    }
}
