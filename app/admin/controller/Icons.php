<?php
namespace app\admin\controller;


class Icons extends BaseController
{
    /**
     * 首页
     */
    public function index()
    {
        $module = $this->request->param('module', '', 'trim');
        if($module == 'bootstrap'){
            return $this->fetch('module');
        }else{
            return $this->fetch();
        }
    }
}
