<?php
namespace app\admin\middleware;

use app\admin\service\RememberLoginService;
use think\facade\Log;
use think\facade\Session;
use think\Request;
use think\Response;

/**
 * 保持登录恢复中间件
 *
 * 位于 RbacMiddleware 之前：当 Session 中已无 admin_id（Session 过期、
 * 浏览器重启丢弃会话 Cookie）而持久化 Cookie 仍有效时，静默重建登录态，
 * 使用户无需重新输入账号密码。
 *
 * 令牌校验失败一律按未登录处理，不抛错、不阻断请求。
 */
class RememberLogin
{
    public function handle(Request $request, \Closure $next): Response
    {
        if (!$this->isLoginSubmit($request) && !Session::get('admin_id')) {
            try {
                app(RememberLoginService::class)->restore();
            } catch (\Throwable $e) {
                Log::warning('[保持登录] 登录态恢复失败: ' . $e->getMessage());
            }
        }

        return $next($request);
    }

    /**
     * 是否为登录提交请求
     *
     * 应用级中间件早于路由分派执行，此时只能依据 pathinfo 判断：
     * 多应用模式下 pathinfo 已被剥离应用名（login/doLogin），
     * 域名绑定应用等场景可能仍带前缀（admin/login/doLogin）。
     */
    protected function isLoginSubmit(Request $request): bool
    {
        if (!$request->isPost()) {
            return false;
        }

        return preg_match('#^(admin/)?login(/|$)#i', trim($request->pathinfo(), '/')) === 1;
    }
}
