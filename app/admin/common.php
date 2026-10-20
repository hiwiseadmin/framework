<?php
// 这是系统自动生成的公共文件
use wise\auth\facade\Rbac;
use app\admin\facade\CurrentAdmin;

    function check_auth($name){
        $userId = CurrentAdmin::getAdminId();
        return Rbac::check($userId, 'admin', $name);
    }