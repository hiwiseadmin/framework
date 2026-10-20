<?php
namespace plugin\shortlink\controller;

use plugin\BaseController;
use app\admin\facade\CurrentAdmin;

class Base extends BaseController
{
    protected function getAdminId(): int
    {
        try {
            return (int) CurrentAdmin::getAdminId();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 检查当前管理员是否有权操作指定资源
     */
    protected function canManage(int $resourceAdminId): bool
    {
        if ($this->isSuperAdmin()) return true;
        return $resourceAdminId === $this->getAdminId();
    }

    /**
     * 判断是否为超级管理员
     */
    protected function isSuperAdmin(): bool
    {
        $adminId = $this->getAdminId();
        if ($adminId <= 0) return false;
        $superIds = \think\facade\Config::get('app.super_admin_ids', []);
        return in_array($adminId, $superIds);
    }
}
