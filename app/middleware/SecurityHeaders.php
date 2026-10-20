<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;
use app\common\BaseMiddleware;

/**
 * 全局安全响应头中间件
 *
 * 在所有应用 / 所有路由上统一补充浏览器侧安全响应头，并移除 X-Powered-By 指纹：
 *   - X-Content-Type-Options: nosniff  禁止 MIME 嗅探，防止上传文件被当作 HTML 执行
 *   - X-Frame-Options: SAMEORIGIN       防点击劫持
 *   - Referrer-Policy                   限制来源地址外泄
 *   - Strict-Transport-Security         仅 HTTPS 请求下发（本地 HTTP 调试不受影响）
 *
 * 说明：CSP 因各端（admin / plugin / 前台）策略存在差异、且涉及内联脚本 nonce 适配，
 * 仍由 XssFilterMiddleware 按需下发，此处不强加，避免打断既有页面脚本。
 *
 * @package app\common\middleware
 */
class SecurityHeaders extends BaseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->header([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        ]);

        // HSTS：仅 HTTPS 生效（避免本地 HTTP 被浏览器缓存强制跳转）
        if ($request->isSsl()) {
            $response->header([
                'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            ]);
        }

        // 移除 X-Powered-By 技术栈/版本指纹
        // 生产环境建议同时在 php.ini 中关闭 expose_php（PHP_INI_SYSTEM，无法运行时修改）
        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');
        }

        return $response;
    }
}
