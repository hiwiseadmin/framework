<?php
declare(strict_types=1);

namespace app\middleware;

use think\facade\Config;
use think\Request;

/**
 * Cookie secure 标志初始化中间件
 *
 * 按当前请求协议动态设置 config('cookie.secure')。
 *
 * 原逻辑位于 AppService::boot()，但 boot() 在 Swoole 常驻下每个 worker 进程仅执行一次，
 * 且执行时 request 尚未就绪（$_SERVER 为空），HTTPS 判断恒为 false，
 * 导致 cookie.secure 被错误固定为 false——HTTPS 部署下 Cookie 丢失 secure 标志。
 *
 * 下沉到全局中间件后：
 *  - 每个请求基于当前请求协议重新判断（$request->isSsl() 覆盖 HTTPS、
 *    REQUEST_SCHEME、X-Forwarded-Proto、Front-End-Https 等反代场景）；
 *  - Swoole 多协程并发下，Config::set 是纯内存原子操作、无协程让出，
 *    且写入值由协议决定、确定性一致，故安全；
 *  - 必须早于 SessionInit 执行：SessionInit 会实例化 Session/Cookie 并
 *    快照 secure 值，若在其后设置将不生效。
 */
class InitCookieSecure
{
    public function handle(Request $request, \Closure $next)
    {
        if (Config::get('cookie.secure', false)) {
            Config::set(['secure' => $request->isSsl()], 'cookie');
        }

        return $next($request);
    }
}
