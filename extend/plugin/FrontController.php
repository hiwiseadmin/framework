<?php
namespace plugin;

use app\common\controller\FrontController as CommonFrontController;
use plugin\traits\PluginTrait;

/**
 * 插件前端控制器基类
 *
 * 面向公众/非后台访问的插件控制器应继承此类。
 * 与 BaseController（后台）的区别：无 AdminTrait、无管理权限中间件。
 *
 * 继承链: app\BaseController → app\common\controller\FrontController → plugin\FrontController
 */
abstract class FrontController extends CommonFrontController
{
    use PluginTrait;

    /**
     * 初始化
     */
    protected function initialize()
    {
        // 1. 插件上下文初始化（PluginTrait：解析 ident、注入模板变量）
        $this->pluginInitialize();

        // 2. 声明本插件的视图 scope（空参数 fetch() 据此自动落位）
        $this->bindViewScope();

        // 3. 触发子类钩子
        $this->initPlugin();

        // 4. 父类初始化（site 配置注入等）
        parent::initialize();
    }

    /**
     * 插件自定义初始化钩子（子类可重写）
     */
    protected function initPlugin(): void
    {
    }

}
