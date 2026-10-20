<?php
namespace app\admin\controller;

use app\common\controller\BaseController as CommonBaseController;
use app\admin\controller\traits\AdminTrait;

/**
 * Admin 应用基础控制器
 *
 * 所有 admin 应用控制器应继承此类。
 * 自动注入后台通用模板变量、子框架模式。
 *
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
        // 网页默认在 iframe 中
        $this->setSubframe(true);

        // 分配后台通用数据
        $this->assignData();

        // 调用父类初始化（触发 _initialize 钩子）
        parent::initialize();

        // 前端功能模块注入依赖 isSubframe（Index / Login 在 _initialize 中才覆盖为 false）
        // 与登录态，故必须放在父类初始化之后
        $this->assignFeatureModules();
    }
}
