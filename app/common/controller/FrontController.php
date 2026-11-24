<?php
namespace app\common\controller;

use think\facade\Session;
use think\facade\Config;
use think\facade\View;
use think\facade\Request;
use wise\support\traits\JsonResponse;

class FrontController extends \app\BaseController
{
    /**
     * 中间件
     * @var array
     */
    protected $middleware = [

    ];

    use JsonResponse;
    
    /**
     * 初始化方法
     */
    protected function initialize()
    {
        $this->assignData();

        $this->_initialize();
    }
    
    /**
     * 调用子类初始化方法
     */
    protected function _initialize()
    {

    }

    /**
     * 分派后台通用的数据
     * @return null
     */
    protected function assignData(){
        $modulename = app('http')->getName();
        $controller = parse_name(Request::controller());
        $action     = strtolower(Request::action());

        $site = Config::get("site");
        $config = [
            'site'          => array_intersect_key($site, array_flip(['name', 'cdnurl', 'version', 'timezone', 'languages'])),
            'modulename'    => $modulename,
            'controller'    => $controller,
            'action'        => $action,
            'referer'       => Session::get("referer")
        ];


        View::assign('site', $site);

        View::assign('config', $config);
    }
}