<?php
namespace plugin\demo\controller\api;

use plugin\ApiController;

/**
 * demo 插件 API 默认控制器
 *
 * 经全局路由 route/api.php → ApiDispatch catch-all 调度，URL 形如：
 *   GET /api/demo            （默认控制器/操作）
 *   GET /api/demo/index/ping
 *   GET /api/demo/v1/index/ping   （显式版本 v1）
 *
 * 继承 plugin\ApiController：
 *   - success() / error() 统一 {code, msg, data} JSON 响应
 *   - $this->request / $this->app / $this->pluginIdent 便捷属性
 */
class Index extends ApiController
{
    /**
     * 默认操作：返回插件 API 基本信息与可用测试接口导航。
     *
     * GET /api/demo
     */
    public function index()
    {
        return $this->success('demo plugin api is ready', [
            'plugin'    => 'demo',
            'api'       => $this->getApiVersion(),
            'framework' => 'WiseAdmin',
            'actions'   => [
                'GET /api/demo/index/ping'  => '连通性测试',
                'GET /api/demo/test/echo'   => '参数回显测试',
                'GET /api/demo/test/method' => '请求信息调试',
                'GET /api/demo/test/errors' => '统一错误响应演示',
            ],
        ]);
    }

    /**
     * 连通性测试。
     *
     * GET /api/demo/index/ping
     */
    public function ping()
    {
        return $this->success('pong', [
            'time'    => time(),
            'plugin'  => $this->pluginIdent,
            'version' => $this->getApiVersion(),
        ]);
    }
}
