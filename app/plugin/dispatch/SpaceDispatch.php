<?php

namespace app\plugin\dispatch;

use think\exception\HttpException;
use think\route\Dispatch;
use wise\plugin\PluginContainerKeys;
use wise\plugin\PluginManager;
use wise\url\core\SpaceResolver;
use wise\url\http\SpaceDispatcher;
use wise\url\Url;

/**
 * 空间前台调度器
 *
 * 全站「未命中路由」的唯一入口（替代 IndexDispatch）：
 *   Host + Path → Space → 插件 → 固定入口 / wise-url 解析 → 处理器
 *
 * 设计约束：
 *   - `wise-url.space.enable=false` 时逐字节回落 IndexDispatch（运行时开关，不影响路由缓存）
 *   - 空间未命中同样回落 IndexDispatch，保证未配置空间的站点行为不变
 *   - 不接管 /admin、/api、/member、/plugin 等已命中应用，只处理全局 miss
 */
class SpaceDispatch extends Dispatch
{
    /**
     * 框架在 `Route::dispatch()` 中对所有调度器统一调用 init()
     *
     * 基类 `think\route\Dispatch` 没有该方法，IndexDispatch 自行实现的是完整解析。
     * 这里只注入应用容器：参数解析必须留到 exec()，否则开关关闭时
     * 也会提前触发插件解析，破坏「逐字节回落 IndexDispatch」的约定。
     */
    public function init($app = null)
    {
        $this->app = $app ?? app();
    }

    public function exec()
    {
        if (!(bool) config('wise-url.space.enable', false)) {
            return $this->delegate();
        }

        $config = (array) config('wise-url.space', []);
        $scheme = (string) ($config['scheme'] ?? 'https');
        $host   = (string) $this->request->host();
        $path   = (string) $this->request->pathinfo();

        if (!$this->hostAllowed($host, $config)) {
            return $this->fallback('host_not_allowed');
        }

        $spaces = Url::space();
        $space  = $spaces->resolve($host, $path);

        if ($space === null) {
            return $this->fallback('space_not_found');
        }

        $dispatcher = new SpaceDispatcher(Url::dispatcher(), Url::handlers());

        if (in_array(strtoupper($this->request->method()), ['GET', 'HEAD'], true)) {
            $target = $dispatcher->canonicalTarget($space, $host, $path, $spaces->list(), $scheme);
            if ($target !== null) {
                try {
                    \think\facade\Log::info('[url-space] canonical redirect', [
                        'from' => $host,
                        'to'   => $target,
                        'path' => $path,
                    ]);
                } catch (\Throwable) {
                }

                return redirect($target, 301);
            }
        }

        $this->bootPlugin($space->app);

        $this->request->view_scope    = $space->app;
        $this->request->url_space     = $space->toArray();
        $this->request->space_site_id = $space->siteId;

        $rest   = SpaceResolver::rest($space, $path) ?? '';
        $action = $dispatcher->dispatch($space, $rest, $space->siteId);

        if ($action->isRedirect()) {
            $target = (string) $action->redirect;

            if (!$this->isSafeRedirect($target)) {
                try {
                    \think\facade\Log::warning('[url-space] blocked unsafe redirect', [
                        'target' => $target,
                        'host'   => (string) $this->request->host(),
                        'path'   => (string) $this->request->pathinfo(),
                    ]);
                } catch (\Throwable) {
                }

                throw new HttpException(404, '页面不存在');
            }

            return redirect($target, $action->status);
        }

        if ($action->isGone()) {
            throw new HttpException(410, '页面已删除');
        }

        if (!$action->isFound()) {
            throw new HttpException(404, '页面不存在');
        }

        $this->request->setRoute($action->params);

        return $this->invokeHandler($action->handler);
    }

    /**
     * 回落默认插件入口（关闭开关、空间未命中时保持现状）
     */
    protected function delegate()
    {
        return (new IndexDispatch(
            $this->request,
            $this->rule,
            $this->dispatch,
            $this->param,
            $this->option,
            $this->miss
        ))->exec();
    }

    /**
     * 回落默认插件入口，并标记 `X-Url-Space: fallback`
     */
    protected function fallback(string $reason)
    {
        try {
            \think\facade\Log::info('[url-space] fallback: ' . $reason, [
                'host' => (string) $this->request->host(),
                'path' => (string) $this->request->pathinfo(),
            ]);
        } catch (\Throwable) {
        }

        $response = $this->delegate();

        if ($response instanceof \think\Response) {
            $response->header(['X-Url-Space' => 'fallback']);
        }

        return $response;
    }

    /**
     * Host 白名单判定
     *
     * `allowed_hosts` 为空 = 不限制（保持现状）；配置后支持 `*.example.com`。
     * `allow_wildcard=false` 时通配项被忽略；此时若白名单只剩通配项，
     * 全部请求都会回落（配置矛盾时安全降级）。
     *
     * @param array<string, mixed> $config
     */
    protected function hostAllowed(string $host, array $config): bool
    {
        $allowed = array_values(array_filter(
            (array) ($config['allowed_hosts'] ?? []),
            static fn ($item): bool => is_string($item) && trim($item) !== ''
        ));

        if ($allowed === []) {
            return true;
        }

        if (empty($config['allow_wildcard'])) {
            $allowed = array_values(array_filter(
                $allowed,
                static fn (string $item): bool => !str_starts_with(\wise\url\core\Normalizer::host($item), '*.')
            ));
        }

        return \wise\url\core\SpaceValidator::hostAllowed($host, $allowed);
    }

    /**
     * 校验并初始化空间所属插件
     */
    protected function bootPlugin(string $plugin): void
    {
        $manager = $this->app->get(PluginContainerKeys::MANAGER);

        try {
            $meta = $manager->getPlugin($plugin);
        } catch (\Throwable) {
            throw new HttpException(404, "Plugin '{$plugin}' not found");
        }

        if (!$meta->isDevelop() && !$meta->isEnabled()) {
            throw new HttpException(404, "Plugin '{$plugin}' is not enabled");
        }

        $manager->initServiceProvider($plugin);

        $this->request->plugin = array_merge($meta->toArray(), [
            'defaultPlugin' => true,
            'config'        => $meta->getConfigSchema(),
        ]);
    }

    /**
     * 301 目标安全校验：仅允许 http(s) 绝对地址或站内相对路径
     */
    protected function isSafeRedirect(string $target): bool
    {
        $target = trim($target);
        if ($target === '') {
            return false;
        }

        if (preg_match('#^https?://#i', $target) === 1) {
            return true;
        }

        return str_starts_with($target, '/') && !str_starts_with($target, '//');
    }

    /**
     * 调用处理器：`Class@method` 或 callable
     */
    protected function invokeHandler(mixed $handler)
    {
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            $instance = $this->app->make($class);
            $this->request->setAction($method);

            $reflect = new \ReflectionMethod($instance, $method);
            $vars    = $this->request->param();

            return $this->autoResponse($this->app->invokeReflectMethod($instance, $reflect, $vars));
        }

        if (is_callable($handler)) {
            return $this->autoResponse($this->app->invoke($handler));
        }

        throw new HttpException(500, 'No URL handler registered');
    }
}
