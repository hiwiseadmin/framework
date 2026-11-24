<?php
declare (strict_types=1);

namespace app\plugin\middleware;

use Closure;
use think\App;
use think\Request;
use app\plugin\dispatch\IndexDispatch;
use think\facade\Route;

/**
 * 默认前端插件路由中间件
 * 用于处理前端默认插件路由，将请求分发到对应的插件控制器
 * @package app\plugin\middleware
 */
class IndexPluginRoute
{
    /** @var App */
    protected $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 处理请求
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $pathinfo = $request->pathinfo();
        $parts = explode('/', $pathinfo);
        $controller = strtolower($parts[0] ?? '');

        if (empty($controller)) {
            $role = Route::any('', IndexDispatch::class);

            $dispatch = new IndexDispatch($request, $role, self::class, ['controller' => 'index']);
            $dispatch->init($this->app);
            return $dispatch->run();
        }

        if (!$this->isIgnoreRoute($controller) && !$this->isValidAppRoute($controller) && !$this->isMatchRoute($request)) {
            $role = Route::any("{$controller}/:action", IndexDispatch::class)->pattern(['action' => '[\w]+']);

            $dispatch = new IndexDispatch($request, $role, self::class, ['controller' => null]);
            $dispatch->init($this->app);
            return $dispatch->run();
        }

        return $next($request);
    }

    protected function isMatchRoute(Request $request): bool
    {
        return Route::check();
    }

    /**
     * 判断是否是一个可以忽略的路由
     * @param string $controller
     * @return bool
     */
    protected function isIgnoreRoute(string $controller): bool
    {
        return in_array($controller, ['admin', 'api', 'captcha', 'install', 'member', 'plugin']);
    }

    /**
     * 判断是否是一个有效的应用路由目录
     * @param string $appName
     * @return bool
     */
    protected function isValidAppRoute(string $appName): bool
    {
        $appPath = base_path() . $appName . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR;
        return is_dir($appPath);
    }
}