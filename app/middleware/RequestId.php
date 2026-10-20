<?php
declare(strict_types=1);

namespace app\middleware;

use think\Request;
use think\Response;
use wise\support\context\RequestContext;

/**
 * 请求 ID 中间件
 *
 * 每请求生成或从上游继承 request_id，写入 RequestContext，
 * 供下游（控制器、AuthManager、各 wise-* 包、ContextualLogger、
 * JsonResponse、wise-api）统一读取；并在拿到最终 Response 后
 * 将其写回 X-Request-Id 响应头，确保全链路可追踪。
 *
 * 为什么不放 Service::boot()：
 *   boot() 每个 worker 进程仅执行一次，会导致后续请求共享首个
 *   request_id，且 Swoole 常驻下被 HttpEnd 的 clear() 清掉后丢失。
 *   下沉到全局中间件（第一位）后，每个请求重新生成，并受益于
 *   RequestContext 的协程隔离，并发请求互不串扰。
 *
 * 注意：不能在中间件内用 response() 助手函数设置响应头，
 * 因为 response() 每次都会新建一个被丢弃的临时 Response 实例；
 * 应直接操作 $next 返回的 $response 对象。
 */
class RequestId
{
    public function handle(Request $request, \Closure $next)
    {
        // 上游优先：继承网关/上游传入的 X-Request-Id，否则自动生成
        $requestId = $request->header('X-Request-Id') ?: bin2hex(random_bytes(16));
        RequestContext::setRequestId($requestId);

        $response = $next($request);

        if ($response instanceof Response) {
            $response->header(['X-Request-Id' => RequestContext::getRequestId()]);
        }

        return $response;
    }
}
