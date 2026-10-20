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

    // 引入trait
    use JsonResponse;
    
    /**
     * 初始化方法
     */
    protected function initialize()
    {
        // 分配后台通用的数据
        $this->assignData();

        // 调用子类初始化方法
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
        // 配置信息
        $config = [
            'site'          => array_intersect_key($site, array_flip(['name', 'cdnurl', 'version', 'timezone', 'languages'])),
            // 'upload'     => $upload,
            'modulename'    => $modulename,
            'controller'    => $controller,
            'action'        => $action,
            // 'language'      => $lang,
            'referer'       => Session::get("referer")
        ];

        // Config::set('upload', array_merge(Config::get('upload'), $upload));

        //渲染站点配置
        View::assign('site', $site);

        //渲染配置信息
        View::assign('config', $config);
    }
}