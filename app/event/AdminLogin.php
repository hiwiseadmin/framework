<?php
namespace app\event;

/**
 * 管理员登录事件
 */
class AdminLogin
{
    public array $admin;
    public string $message;
    public bool $success;

    public function __construct(array $admin, string $message, bool $success = true)
    {
        $this->admin = $admin;
        $this->message = $message;
        $this->success = $success;
    }
}
