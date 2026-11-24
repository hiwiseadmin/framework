<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\facade\Config;
use think\Request;
use think\Response;
use app\common\BaseMiddleware;

/**
 * 全局安全响应头中间件（唯一出口）
 *
 * 在**所有应用、所有路由**上统一下发浏览器侧安全响应头，并移除 X-Powered-By 指纹：
 *   - Content-Security-Policy       含每请求 nonce；模板经 `$csp_nonce` 写入 <script nonce>
 *   - X-Content-Type-Options        nosniff，禁止 MIME 嗅探
 *   - X-Frame-Options               防点击劫持（CSP 未声明 frame-ancestors 时）
 *   - X-XSS-Protection              浏览器内置过滤器兜底
 *   - Referrer-Policy               限制来源地址外泄
 *   - Permissions-Policy            收敛浏览器特性权限
 *   - Strict-Transport-Security     仅 HTTPS 请求下发（本地 HTTP 调试不受影响）
 *
 * 归属说明（C5-1 / C5-2）：
 *   - 本中间件是安全响应头的**唯一出口**；其他中间件不得重复下发同名头。
 *     改造前 `XssFilterMiddleware` 在 admin 应用重复下发 X-Content-Type-Options /
 *     X-Frame-Options / Referrer-Policy，同名头出现两份且两处策略各自演进。
 *   - CSP 由本中间件（全局）主导后，api / member / plugin 应用也得到同等保护；
 *     改造前只有 admin 应用注册了 `XssFilterMiddleware`，其余应用没有 CSP。
 *   - 静态文件不经过 PHP，CSP 由 Nginx 侧补齐；但不得重复设置主头，
 *     否则两处策略不一致时浏览器按「取交集」生效，容易误伤。
 *
 * 配置：`config/xss.php` 的 `csp` 覆盖项继续生效（迁移期不引入第二套配置文件）。
 *
 * @package app\common\middleware
 */
class SecurityHeaders extends BaseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = bin2hex(random_bytes(16));
        $request->cspNonce = $nonce;

        $response = $next($request);

        $config    = (array) Config::get('xss', []);
        $cspConfig = (array) ($config['csp'] ?? []);

        $headers = [
            'Content-Security-Policy' => $this->buildCsp($nonce, $cspConfig),
            'X-Content-Type-Options'  => 'nosniff',
            'X-XSS-Protection'        => '1; mode=block',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
            'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=()',
        ];

        if (empty($cspConfig['frame-ancestors'])) {
            $headers['X-Frame-Options'] = 'SAMEORIGIN';
        }

        if ($request->isSsl()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        $response->header($headers);

        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');
        }

        return $response;
    }

    /**
     * 构建 Content-Security-Policy
     *
     * 默认策略：同源资源 + unsafe-inline（兼容内联事件处理器）+ nonce 白名单。
     *
     * script-src 同时包含 unsafe-inline 与 nonce 的原因：
     *   nonce 机制仅覆盖 <script> 标签，无法用于 onclick 等内联事件处理器；
     *   浏览器优先匹配 nonce，不匹配时回退 unsafe-inline，确保兼容性。
     *
     * @param string $nonce    每请求 nonce
     * @param array  $override 用户覆盖项（config/xss.php 的 csp）
     * @return string CSP 头值
     */
    protected function buildCsp(string $nonce, array $override = []): string
    {
        $directives = [
            'default-src'     => "'self'",
            'script-src'      => "'self' 'unsafe-inline'",
            'style-src'       => "'self' 'unsafe-inline'",
            'img-src'         => "'self' data: blob:",
            'font-src'        => "'self'",
            'connect-src'     => "'self'",
            'media-src'       => "'self'",
            'object-src'      => "'none'",
            'frame-ancestors' => "'self'",
            'form-action'     => "'self'",
            'base-uri'        => "'self'",
        ];

        if ($nonce !== '') {
            $directives['script-src'] = "'self' 'unsafe-inline' 'nonce-" . $nonce . "'";
        }

        if ($override !== []) {
            $directives = array_merge($directives, $override);
        }

        if ($nonce !== '' && !empty($override['script-src'])) {
            $scriptSrc = (string) ($directives['script-src'] ?? '');

            if ($scriptSrc !== '' && !str_contains($scriptSrc, "'nonce-") && !str_contains($scriptSrc, "'unsafe-inline'")) {
                $directives['script-src'] = $scriptSrc . " 'nonce-" . $nonce . "'";
            }
        }

        $parts = [];
        foreach ($directives as $key => $value) {
            $parts[] = $key . ' ' . $value;
        }

        return implode('; ', $parts);
    }
}
