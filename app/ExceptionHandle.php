<?php
namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;
use wise\support\exception\DefaultExceptionRenderer;
use wise\support\exception\WiseException;

/**
 * 应用异常处理类
 *
 * 所有未捕获异常在此统一渲染为 {code, msg, data, request_id, timestamp} JSON。
 * 委托 wise-support 的 DefaultExceptionRenderer 做异常→数组转换，
 * 此处补充 HTTP 上下文后输出。
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        parent::report($exception);
    }

    /**
     * 将任意异常渲染为响应（内容协商）
     *
     * 流程：
     *   1. HttpResponseException → 已构造好的 Response，原样返回
     *   2. 非 JSON 请求（浏览器直接访问 / HTML 后台）→ 回退 TP8 默认 HTML 错误页
     *   3. JSON 请求 → 委托 DefaultExceptionRenderer::render() 转为 {code, msg, data}
     *   4. 补充 request_id + timestamp
     *   5. 根据异常类型确定 HTTP 状态码后 json() 输出
     *
     * 说明：
     *   WiseException 继承 \RuntimeException，是标准 Throwable，TP8 能正常处理。
     *   此处仅在 JSON 场景覆盖渲染逻辑，HTML 场景完全交给父类，
     *   从而保留 think_exception.tpl 的 HTML 错误页能力。
     *
     * @param \think\Request $request
     * @param Throwable      $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        if (!$request->isJson()) {
            return parent::render($request, $e);
        }

        $renderer = new DefaultExceptionRenderer();
        $payload  = $renderer->render($e);
        $payload['request_id'] = $this->getRequestId();
        $payload['timestamp']  = time();

        $httpCode = $this->httpCodeFor($e, $payload['code']);

        return json($payload, $httpCode);
    }

    /**
     * 获取本次请求的 request_id
     *
     * 优先从 wise-support 的 RequestContext 读取（由 app\middleware\RequestId 中间件设置），
     * 不可用时自动生成。
     */
    private function getRequestId(): string
    {
        if (class_exists(\wise\support\context\RequestContext::class)) {
            $id = \wise\support\context\RequestContext::getRequestId();
            if ($id && $id !== 'unknown') {
                return $id;
            }
        }
        return bin2hex(random_bytes(8));
    }

    /**
     * 确定输出响应的 HTTP 状态码
     *
     * 优先级：异常自带 > HttpException statusCode > 渲染器号段映射
     */
    private function httpCodeFor(Throwable $e, int $businessCode): int
    {
        if ($e instanceof WiseException) {
            return $e->getHttpCode();
        }
        if ($e instanceof HttpException) {
            return $e->getStatusCode();
        }
        return DefaultExceptionRenderer::errorCodeToHttpCode($businessCode);
    }
}
