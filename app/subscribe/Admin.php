<?php
declare(strict_types=1);

namespace app\subscribe;

use app\admin\model\LoginLog;
use app\event\AdminLogin;
use app\event\AdminLogout;

/**
 * 管理员事件订阅者
 */
class Admin
{
    public function onAdminLogin(AdminLogin $event)
    {
        $this->addLoginLog($event->admin, $event->success, $event->message);
    }

    public function onAdminLogout(AdminLogout $event)
    {
        $this->addLoginLog($event->admin, true, '退出登录');
    }

    /**
     * 记录登录日志
     * @param array $admin 管理员信息
     * @param bool $success 是否成功
     * @param string $message 消息内容
     */
    protected function addLoginLog(array $admin, bool $success = true, string $message = '')
    {
        if($success){
            LoginLog::record($admin['id'], $admin['username'], LoginLog::STATUS_SUCCESS, $message);
        }else{
            LoginLog::record($admin['id'], $admin['username'], LoginLog::STATUS_FAILED, $message);
        }
    }
}
