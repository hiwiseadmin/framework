<?php
namespace app\event;

/**
 * 管理员退出事件
 */
class AdminLogout
{
    public array $admin;

    public function __construct(array $admin)
    {
        $this->admin = $admin;
    }
}
