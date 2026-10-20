<?php
declare(strict_types=1);

namespace app\common\middleware;

use Closure;
use Composer\InstalledVersions;
use think\facade\Cookie;
use think\facade\Config;
use think\facade\View;
use think\Request;
use app\common\BaseMiddleware;

/**
 * CSRF 令牌中间件
 *
 * 全权管理 Token 生命周期：生成 → 写入 Cookie → 注入模板变量 → 校验 → 刷新。
 *
 * 完整链路：
 *   [GET 请求]  ensureToken() → Cookie.set + View.assign('__csrf__')
 *   [HTML 渲染]  <meta name="csrf-token" content="{$__csrf__}">
 *   [JS AJAX]    _getCSRFToken() 读取 <meta> → X-CSRF-TOKEN Header
 *   [POST 请求]  getTokenFromRequest() → tokensMatch(Cookie) → refreshToken()
 */
class VerifyCsrfToken extends BaseMiddleware
{
    /**
     * 配置参数
     * @var array
     */
    protected array $config = [];

    /**
     * 排除的URL
     * @var array
     */
    protected array $except = [];

    /**
     * Cookie 键名
     * @var string
     */
    protected string $cookieKey = 'csrf_token';

    /**
     * 请求参数字段名 / HTTP 头字段名
     * @var string
     */
    protected string $paramKey = '_token';

    /**
     * Cookie 有效期（秒），默认 2 小时
     * @var int
     */
    protected int $expire = 7200;

    public function __construct()
    {
        $this->config    = Config::get('csrf', []);
        $this->cookieKey = $this->config['cookie_key'] ?? $this->cookieKey;
        $this->paramKey  = $this->config['param_key']  ?? $this->paramKey;
        $this->except    = $this->config['except']     ?? $this->except;
        $this->expire    = $this->config['expire']     ?? $this->expire;
    }

    // ==================== 中间件入口 ====================

    public function handle(Request $request, Closure $next)
    {
        // 命令行模式直接放行：CSRF 是 Web 专属攻击，CLI 无浏览器/Cookie/Session
        if ($request->isCli()) {
            return $next($request);
        }

        if (empty($this->config['enabled'])) {
            return $next($request);
        }

        // 只读请求 + 白名单：确保 Token 存在后放行
        if ($this->isReading($request) || $this->inExceptArray($request)) {
            $response = $next($request);
            $this->ensureToken();
            return $response;
        }

        // 写请求：校验 Token
        $token = $this->getTokenFromRequest($request);
        if (is_string($token) && $this->tokensMatch($token)) {
            $response = $next($request);
            $this->refreshToken(); // 校验通过即刷新（防 BREACH）
            return $response;
        }

        return $this->error(
            $this->config['error_msg'] ?? 'CSRF令牌验证失败，请刷新页面重试',
            [],
            419
        );
    }

    // ==================== Token 生命周期 ====================

    /**
     * 确保 Token 存在（首次访问或过期后生成）
     */
    protected function ensureToken(): void
    {
        $token = Cookie::get($this->cookieKey, '');

        if (!$token) {
            // 首次访问或 Cookie 丢失：生成新 token 并写入响应 Cookie。
            // 注意：setTokenCookie() 内部已通过 request->setCookie() 同步内存，
            // 不要再用 Cookie::set() 二次写入 —— 那会以默认 config（httponly=true）
            // 覆盖掉 setTokenCookie 里的 httponly=false，导致 JS 读不到 token。
            $token = $this->generateToken();
            $this->setTokenCookie($token);
        }

        // 注出当前 Token 到模板变量，供 View 层使用
        if (class_exists(View::class)) {
            View::assign('__csrf__', $token);
        }
    }

    /**
     * 刷新 Token（每次写请求校验通过后轮换，防 BREACH 攻击）
     */
    protected function refreshToken(): void
    {
        // setTokenCookie() 已同步内存，不要再二次 Cookie::set()，
        // 否则会用默认 config 覆盖 httponly=false，导致 JS 读不到新 token。
        $this->setTokenCookie($this->generateToken());
    }

    /**
     * 写入 Token 到 Cookie
     */
    protected function setTokenCookie(string $token): void
    {
        Cookie::set($this->cookieKey, $token, [
            'expire'   => $this->expire,
            'httponly' => false,   // 必须 false：JS 无法读取 HttpOnly Cookie
            'samesite' => 'Lax',
            'path'     => '/',
            // 跟随全局 cookie.secure：AppService::initCookieSecureFlag() 已按请求协议
            // 动态修正（HTTP→false / HTTPS→true）。若仍用 config/csrf.php 里写死的
            // secure=true，HTTP 下 csrf_token 会被标为 Secure，浏览器拒绝保存该 Cookie，
            // 服务端 tokensMatch() 读到空 token，导致「CSRF令牌验证失败」。
            'secure'   => Config::get('cookie.secure', $this->config['secure'] ?? false),
        ]);
    }

    /**
     * 生成加密安全随机 Token（256-bit）
     */
    protected function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ==================== Token 校验 ====================

    /**
     * 从请求中获取 Token（优先 Header → 表单参数）
     */
    protected function getTokenFromRequest(Request $request): ?string
    {
        // 使用 config/csrf.php 的 header_name 配置（默认 X-CSRF-TOKEN），
        // 避免写死导致配置项失效。
        $headerName = $this->config['header_name'] ?? 'X-CSRF-TOKEN';

        // 1) 优先从 Request 对象读取（大小写已归一化，可容错）
        $token = $request->header($headerName);

        // 2) 兜底：某些 SAPI（内置服务器 / apache_request_headers 行为异常）下
        //    think\Request 的 header 解析可能丢失自定义头，但原始 server 变量中仍
        //    保留完整的 HTTP_X_CSRF_TOKEN。
        //    注意：务必用 $request->server() 而非超全局 $_SERVER ——
        //    Swoole 等常驻进程下 $_SERVER 是跨请求共享的，会读到上一个请求的脏数据；
        //    $request->server() 由 Swoole 适配层正确映射，每请求独立，FPM/Swoole 均安全。
        if ($token === null || $token === '') {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
            $serverToken = $request->server($serverKey);
            if (is_string($serverToken) && $serverToken !== '') {
                $token = $serverToken;
            }
        }

        // 3) 再兜底到请求参数（表单字段）
        if ($token === null || $token === '') {
            $token = $request->param($this->paramKey);
        }

        return $token ?: null;
    }

    /**
     * 对比请求 Token 与 Cookie Token（时序安全比较）
     */
    protected function tokensMatch(string $token): bool
    {
        $cookieToken = Cookie::get($this->cookieKey, '');
        return hash_equals($cookieToken, $token);
    }

    // ==================== 工具方法 ====================

    /**
     * 判断是否为安全方法（只读请求）
     */
    protected function isReading(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD', 'OPTIONS']);
    }

    /**
     * 判断请求路径是否在白名单中
     *
     * 支持精确匹配和通配符 `*` 匹配：
     *   'api/callback'   → 精确匹配 /api/callback
     *   'api/webhook/*'  → 匹配 /api/webhook/xxx
     *   '/'              → 匹配首页
     */
    protected function inExceptArray(Request $request): bool
    {
        $path = strtolower('/' . app('http')->getName() . '/' . trim($request->pathinfo(), '/'));
        $path = preg_replace('/\/+/', '/', $path);

        foreach ($this->except as $except) {
            if ($except === '/') {
                if ($path === '/') {
                    return true;
                }
                continue;
            }

            $except = trim($except, '/');

            if ($this->pathMatch($path, $except)) {
                return true;
            }

            // 通配符正则匹配
            if (str_contains($except, '*')) {
                if ($this->wildcardMatch($path, $except)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 精确路径匹配（大小写不敏感 + 自动忽略扩展名）
     */
    protected function pathMatch(string $path, string $except): bool
    {
        $pathLower = strtolower($path);
        $exceptLower = strtolower($except);
        $fullExcept = '/' . $exceptLower;

        // 直接匹配
        if ($pathLower === $fullExcept) {
            return true;
        }

        // 去掉 path 扩展名后再匹配（如 /admin/login.html → /admin/login）
        $pathNoExt = preg_replace('/\.[^.]+$/', '', $pathLower);
        $fullExcept = preg_replace('/\.[^.]+$/', '', $fullExcept);
        if ($pathNoExt !== $pathLower && $pathNoExt === $fullExcept) {
            return true;
        }

        return false;
    }

    /**
     * 通配符路径匹配（大小写不敏感 + 自动忽略扩展名）
     */
    protected function wildcardMatch(string $path, string $except): bool
    {
        $pathLower = strtolower($path);
        $exceptLower = strtolower($except);

        $pattern = '#^/' . preg_quote($exceptLower, '#') . '$#';
        $pattern = str_replace('\*', '[^/]*', $pattern);

        if (preg_match($pattern, $pathLower)) {
            return true;
        }

        // 去掉 path 扩展名后再尝试通配符匹配
        $pathNoExt = preg_replace('/\.[^.]+$/', '', $pathLower);
        if ($pathNoExt !== $pathLower && preg_match($pattern, $pathNoExt)) {
            return true;
        }

        return false;
    }
}
