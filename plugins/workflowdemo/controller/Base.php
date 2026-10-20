<?php
namespace plugin\workflowdemo\controller;

use plugin\BaseController;

class Base extends BaseController
{
    protected $noNeedLogin      = [];   // 无需登录，也无需鉴权的方法名列表
    protected $noNeedAuth       = [];   // 需要登录，但是无需鉴权的方法名列表
    protected $noNeedSelfAuthId = [];   // 不需要鉴别自己权限的角色ID

    /**
     * 初始化方法
     */
    protected function initialize()
    {
        parent::initialize();
    }
}
