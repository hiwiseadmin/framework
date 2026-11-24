<?php
/**
 * ============================================================
 * 中间件链路契约校验（C5-1 / C5-2 / C5-3）
 * ============================================================
 *
 * 锁住三件已经收敛的事，防止后续新增中间件时回退：
 *
 *   1. 安全响应头唯一出口（C5-1）
 *      —— `app\middleware\SecurityHeaders` 下发全套安全头；
 *         `XssFilterMiddleware` 不得再写同名头（改造前两处各写一份）。
 *   2. CSP 全应用覆盖（C5-2）
 *      —— admin / api / member / plugin 的路径都要拿到 CSP + nonce；
 *         改造前只有 admin 应用注册了带 CSP 的中间件。
 *   3. 登录端点 IP 检查不重复（C5-3）
 *      —— 登录请求由 LoginGuard 管道的 IpGuardAction 负责，
 *         admin 应用级 IpGuardMiddleware 让位；但管道关闭 IP 检查时不得让位。
 *
 * 用法：`php build/verify-middleware.php`（或 `npm run verify:middleware`）
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

echo "[verify-middleware] 中间件链路契约\n";

$fail = 0;

/**
 * @param string $name
 * @param bool   $ok
 * @param string $detail
 */
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '[PASS] ' : '[FAIL] '), $name, $detail === '' ? '' : (' :: ' . $detail), "\n";
}


/**
 * 用指定 pathinfo 跑一次 SecurityHeaders，返回 [response, request]
 *
 * @return array{0: think\Response, 1: think\Request}
 */
$runSecurityHeaders = static function (string $pathinfo = 'admin/login/index'): array {
    $request = (new think\Request())->withServer([
        'REQUEST_METHOD' => 'GET',
        'HTTP_HOST'      => 'localhost',
        'SERVER_NAME'    => 'localhost',
        'REQUEST_URI'    => '/' . $pathinfo,
    ])->setPathinfo($pathinfo);

    $middleware = new app\middleware\SecurityHeaders();
    $response   = $middleware->handle($request, static fn($req) => think\Response::create('ok', 'html', 200));

    return [$response, $request];
};

[$response, $request] = $runSecurityHeaders();
$headers              = $response->getHeader();

foreach ([
    'Content-Security-Policy',
    'X-Content-Type-Options',
    'X-XSS-Protection',
    'Referrer-Policy',
    'X-Frame-Options',
    'Permissions-Policy',
] as $name) {
    check('安全头下发：' . $name, isset($headers[$name]) && $headers[$name] !== '');
}

$csp = (string) ($headers['Content-Security-Policy'] ?? '');

check('CSP 含 nonce', str_contains($csp, "'nonce-"));
check('CSP 含 frame-ancestors', str_contains($csp, 'frame-ancestors'));
check('Request->cspNonce 已写入（模板可用）', ($request->cspNonce ?? '') !== '');
check(
    'CSP nonce 与 Request 一致',
    ($request->cspNonce ?? '') !== '' && str_contains($csp, "'nonce-" . $request->cspNonce . "'")
);

$plainRequest = (new think\Request())->withServer(['REQUEST_METHOD' => 'GET'])->setPathinfo('admin/login/index');
$plainResponse = (new app\common\middleware\XssFilterMiddleware())->handle(
    $plainRequest,
    static fn($req) => think\Response::create('<html></html>', 'html', 200)
);

foreach ([
    'Content-Security-Policy',
    'X-Content-Type-Options',
    'Referrer-Policy',
    'X-Frame-Options',
    'Permissions-Policy',
] as $name) {
    check('XssFilter 不再下发：' . $name, $plainResponse->getHeader($name) === null);
}

foreach (['admin/login/index', 'api/ping', 'member/index/index', 'plugin/demo/index'] as $path) {
    [$resp] = $runSecurityHeaders($path);
    check('CSP 覆盖应用：' . $path, (string) $resp->getHeader('Content-Security-Policy') !== '');
}

think\facade\Config::set(['csp' => ['script-src' => "'self' https://cdn.example.com"]], 'xss');

[$resp]       = $runSecurityHeaders();
$cspOverride  = (string) $resp->getHeader('Content-Security-Policy');

check(
    '覆盖 script-src 时自动补 nonce',
    str_contains($cspOverride, 'https://cdn.example.com') && str_contains($cspOverride, "'nonce-")
);

think\facade\Config::set(['csp' => []], 'xss');

$ref = new ReflectionClass(\wise\login\middleware\IpGuardMiddleware::class);
$mw  = $ref->newInstanceWithoutConstructor();
$m   = $ref->getMethod('delegatedToLoginPipeline');
$m->setAccessible(true);

$delegates = static fn(string $path): bool
    => (bool) $m->invoke($mw, (new think\Request())->setPathinfo($path));

foreach (['login/doLogin', 'admin/login/doLogin', '/login/doLogin/'] as $path) {
    check('登录端点让位给守卫管道：' . $path, $delegates($path));
}

foreach (['login/index', 'admin/login/index', 'wise_config/index', 'login/doLoginX'] as $path) {
    check('非登录端点仍由中间件检查：' . $path, $delegates($path) === false);
}

$__authCfg = (array) config('wise-auth');
$__authCfg['login']['middleware'] = ['login_guard' => ['enabled' => true, 'check_ip_list' => false]];
think\facade\Config::set($__authCfg, 'wise-auth');

check('check_ip_list=false 时不让位', $delegates('login/doLogin') === false);

$__authCfg = (array) config('wise-auth');
$__authCfg['login']['middleware'] = ['login_guard' => ['enabled' => false, 'check_ip_list' => true]];
think\facade\Config::set($__authCfg, 'wise-auth');

check('login_guard.enabled=false 时不让位', $delegates('login/doLogin') === false);

echo $fail === 0 ? "\n[verify-middleware] 全部通过\n" : "\n[verify-middleware] 失败 {$fail} 项\n";

exit($fail === 0 ? 0 : 1);
