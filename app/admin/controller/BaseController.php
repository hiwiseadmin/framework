<?php
namespace app\admin\controller;

use app\common\controller\BaseController as CommonBaseController;
use app\admin\controller\traits\AdminTrait;

/**
 * Admin 应用基础控制器
 *
 * 所有 admin 应用控制器应继承此类。
 * 自动注入后台通用模板变量、子框架模式。
 */
abstract class BaseController extends CommonBaseController
{
    use AdminTrait;

    /**
     * 初始化（覆盖 common BaseController::initialize）
     * 在父类初始化之前注入后台通用数据
     */
    protected function initialize()
    {
        $this->setSubframe(true);

        $this->assignData();

        parent::initialize();

        $this->assignFeatureModules();
    }
}
