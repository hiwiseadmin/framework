<?php
namespace app\common\controller;

use wise\support\traits\JsonResponse;

/**
 * 通用基础控制器
 *
 * 提供所有应用（admin / api / plugin）共享的底层能力：
 * - JSON 响应（JsonResponse）
 * - 请求参数构建（RequestQueryBuild）
 */
abstract class BaseController extends \app\BaseController
{
    /**
     * 控制器级中间件
     *
     * @var array
     */
    protected $middleware = [];

    use JsonResponse;
    use traits\RequestQueryBuild;

    /**
     * 初始化方法
     */
    protected function initialize()
    {
        $cspNonce = request()->cspNonce ?? '';
        \think\facade\View::assign('csp_nonce', $cspNonce);

        $this->_initialize();
    }

    /**
     * 子类初始化钩子（按需覆盖）
     */
    protected function _initialize()
    {
    }
}