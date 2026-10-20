<?php
namespace plugin\demo\controller\api;

use plugin\ApiController;
use wise\api\response\ErrorCode;

/**
 * demo 插件 API 测试控制器
 *
 * 用于验证"插件 API 访问路由已收口到全局路由（route/api.php → ApiDispatch）"后，
 * 控制器能被正确调度与响应。URL 形如：
 *
 *   GET  /api/demo/test/ping
 *   GET  /api/demo/test/echo?foo=bar
 *   POST /api/demo/test/method
 *   GET  /api/demo/test/errors
 *
 * 版本段 v1 可选（插件未显式声明 api 策略，隐式支持 v1）：
 *   GET  /api/demo/v1/test/ping
 *
 * @see \plugin\ApiController  插件 JSON API 控制器基类
 */
class Test extends ApiController
{
    /**
     * 连通性测试：返回服务端时间与当前插件标识。
     *
     * GET /api/demo/test/ping
     */
    public function ping()
    {
        return $this->success('pong', [
            'plugin'  => $this->pluginIdent,
            'version' => $this->getApiVersion(),
            'time'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 参数回显测试：展示 query 与 body 参数如何被收集。
     *
     * GET /api/demo/test/echo?foo=bar&name=demo
     * POST /api/demo/test/echo （application/x-www-form-urlencoded 或 application/json）
     */
    public function echo()
    {
        $params = array_merge($this->request->get(), $this->request->post());

        return $this->success('echo ok', [
            'params'   => $params,
            'count'    => count($params),
            'method'   => $this->request->method(),
            'protocol' => $this->request->protocol(),
        ]);
    }

    /**
     * 请求信息调试：展示当前请求的关键信息，便于核对路由与中间件是否生效。
     *
     * GET /api/demo/test/method
     */
    public function method()
    {
        return $this->success('ok', [
            'plugin'     => $this->pluginIdent,
            'controller' => $this->request->controller(),
            'action'     => $this->request->action(),
            'pathinfo'   => $this->request->pathinfo(),
            'url'        => $this->request->url(),
            'ip'         => $this->request->ip(),
            'method'     => $this->request->method(),
            'version'    => $this->getApiVersion(),
        ]);
    }

    /**
     * 错误响应演示：返回统一的 {code, msg, data} 错误结构。
     *
     * GET /api/demo/test/errors
     */
    public function errors()
    {
        return $this->error('这是 demo 插件返回的业务错误', [
            'hint' => 'code=4000 表示业务校验失败，配合 msg 与 data 排查',
        ], ErrorCode::VALIDATE_FAILED);
    }
}
