<?php
namespace app\middleware;

use think\Request;
use think\facade\Cache;
use think\facade\Log;
use app\common\BaseMiddleware;

/**
 * 管理员入口中间件
 *
 * 作用：通过一个"安全入口"隐藏后台真实地址。启用后，访问 /admin 下的任意
 * 地址（GET/POST 均拦截）前，必须先访问 entry_list 中配置的入口地址，
 * 未通过入口一律返回 404（伪装页面不存在）。
 *
 * 与旧版（仅校验 GET）的区别：
 *  - 旧版只拦截 GET，POST /admin/login/doLogin 可直接绕过入口完成认证；
 *  - 本版本对 admin 应用的请求方法不再区分，杜绝上述绕过。
 *
 * 安全设计：
 *  - 未通过入口时统一返回 404，提示页不暴露后台存在与配置文件位置；
 *  - 拦截请求写审计日志；同一 IP 直接探测 /admin 达到阈值后临时封禁；
 *  - 入口命中后带有效期（config('entry.ttl')），过期需重新访问入口；
 *  - 已登录管理员不受影响（auth_id('admin') 非空即放行）。
 *
 * @package app\middleware
 */
class EntryVerify extends BaseMiddleware
{
    /**
     * 缓存键前缀（IP 探测限流）
     */
    protected const CACHE_PREFIX = 'entry_guard:';

    /**
     * 当前请求实例
     */
    protected Request $request;

    public function handle(Request $request, \Closure $next)
    {
        if (!config('entry.enabled', false)) {
            return $next($request);
        }

        $this->request = $request;

        $pathinfo = explode('.', $request->pathinfo())[0];
        $app      = strtolower(explode('/', $pathinfo)[0]);

        if ($this->isExemptApp($app)) {
            return $next($request);
        }

        if ($this->checkAdminEntry($app)) {
            $this->resetProbe();
            $this->markEntryPassed($app);

            return $app === 'admin'
                ? $next($request)
                : redirect((string) url('/admin/login'));
        }

        if ($app === 'admin' && auth_id('admin') === null) {
            if (!$this->isEntryPassed()) {
                $this->recordBlocked($request);

                return $this->deny($request);
            }

            return $next($request);
        }

        if ($app === 'admin' && !empty(session('admin_entry'))) {
            $this->touchEntryPassed();
        }

        return $next($request);
    }

    /**
     * 是否属于无需入口校验的公共应用（快速路径）
     *
     * 注意：与早期实现里"空转的 except_list"不同，这里仅用作性能短路——
     * 命中豁免的应用直接放行，不再参与后续任何入口判断。
     *
     * @param string $app 应用名（已转小写）
     * @return bool
     */
    protected function isExemptApp(string $app): bool
    {
        if ($app === 'admin') {
            return false;
        }

        $default = ['api', 'plugin', 'member', 'index', 'install'];
        $except  = config('entry.except_list', $default);

        if (!is_array($except) || empty($except)) {
            $except = $default;
        }

        $except = array_values(array_unique(array_map(
            fn ($v) => strtolower(trim((string) $v)),
            array_merge($default, $except)
        )));

        $except = array_values(array_diff($except, ['admin']));

        return in_array($app, $except, true);
    }

    /**
     * 检查当前应用是否为配置的安全入口
     *
     * @param string $app 应用名（已转小写）
     * @return bool
     */
    protected function checkAdminEntry(string $app): bool
    {
        $entryList = config('entry.entry_list', []);

        if (!is_array($entryList) || empty($entryList)) {
            return false;
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($v) => trim(strtolower((string) $v), '/ '),
            $entryList
        ))));

        return in_array($app, $normalized, true);
    }

    /**
     * 标记已通过入口校验（含时间戳，用于有效期判断）
     */
    protected function markEntryPassed(string $app): void
    {
        session('admin_entry', [
            'app' => $app,
            'at'  => time(),
        ]);
    }

    /**
     * 续期入口有效时间
     */
    protected function touchEntryPassed(): void
    {
        $entry = session('admin_entry');

        if (is_array($entry)) {
            $entry['at'] = time();
            session('admin_entry', $entry);
        }
    }

    /**
     * 判断当前是否已通过入口校验（且未过期）
     */
    protected function isEntryPassed(): bool
    {
        $entry = session('admin_entry');

        if (empty($entry)) {
            return false;
        }

        if (is_string($entry)) {
            return true;
        }

        $ttl = (int) config('entry.ttl', 7200);

        if ($ttl > 0 && (time() - (int) ($entry['at'] ?? 0)) > $ttl) {
            session('admin_entry', null);

            return false;
        }

        return !empty($entry['app']);
    }

    /**
     * 记录一次未通过入口的后台请求（审计 + 探测限流）
     *
     * 缓存/日志异常时静默降级：不阻断请求处理本身。
     */
    protected function recordBlocked(Request $request): void
    {
        if ($this->isProbeBanned()) {
            return;
        }

        try {
            $limit  = (int) config('entry.probe_limit', 10);
            $window = (int) config('entry.probe_window', 300);
            $ban    = (int) config('entry.probe_ban', 900);

            $key   = $this->cacheKey('cnt');
            $count = (int) Cache::get($key, 0) + 1;

            if ($count >= $limit) {
                Cache::delete($key);
                Cache::set($this->cacheKey('ban'), 1, max(60, $ban));
            } else {
                Cache::set($key, $count, max(60, $window));
            }
        } catch (\Throwable $e) {
        }

        $this->logBlocked($request);
    }

    /**
     * 命中入口时解除该 IP 的探测计数与封禁
     */
    protected function resetProbe(): void
    {
        try {
            Cache::delete($this->cacheKey('cnt'));
            Cache::delete($this->cacheKey('ban'));
        } catch (\Throwable $e) {
        }
    }

    /**
     * 当前 IP 是否已被临时封禁
     */
    protected function isProbeBanned(): bool
    {
        try {
            return (bool) Cache::get($this->cacheKey('ban'));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 生成基于 IP 的缓存键
     */
    protected function cacheKey(string $name): string
    {
        return static::CACHE_PREFIX . $name . ':' . md5((string) $this->request->ip());
    }

    /**
     * 记录审计日志（写失败不影响请求结果）
     */
    protected function logBlocked(Request $request): void
    {
        try {
            Log::write('[安全入口] 拦截未通过入口的后台请求', 'warning', [
                'ip'     => $request->ip(),
                'method' => $request->method(),
                'url'    => $request->url(),
                'ua'     => mb_substr((string) $request->server('HTTP_USER_AGENT', ''), 0, 200),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 统一拒绝响应：JSON/接口请求返回 404，HTML 请求返回伪装 404 页面
     *
     * @return \think\Response
     */
    protected function deny(Request $request)
    {
        if ($request->isAjax() || $request->isJson() || $request->isPjax()) {
            return json([
                'code' => 404,
                'msg'  => 'Not Found',
                'data' => null,
            ], 404);
        }

        return $this->securityEntryTip((int) config('entry.code', 404));
    }

    /**
     * 安全入口提示（统一伪装为"页面不存在"，不暴露后台与配置信息）
     *
     * @param int $code HTTP 状态码
     * @return \think\Response
     */
    protected function securityEntryTip(int $code = 404)
    {
        $file = app_path() . 'admin/view/middleware/entry_verify.html';
        $html = is_file($file) ? (string) file_get_contents($file) : $this->defaultTipHtml();

        return response($html, $code, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ], 'html');
    }

    /**
     * 兜底提示页（entry_verify.html 不存在时使用，不经过模板引擎编译）
     */
    protected function defaultTipHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404</title>
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; background: #f5f6f8; color: #333; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .box { text-align: center; }
        .box h1 { font-size: 4rem; margin: 0 0 16px; color: #bbb; font-weight: 300; letter-spacing: 4px; }
        .box p { font-size: 1rem; color: #888; }
    </style>
</head>
<body>
    <div class="box">
        <h1>404</h1>
        <p>您访问的页面不存在或已被移动。</p>
    </div>
</body>
</html>
HTML;
    }
}
