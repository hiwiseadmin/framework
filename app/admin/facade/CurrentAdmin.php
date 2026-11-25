<?php
namespace app\admin\facade;

use think\Facade;

/**
 * 当前登录管理员Facade类
 * 提供静态方式快速获取当前登录管理员的相关信息
 *
 * @method static int getAdminId() 获取当前登录管理员ID
 * @method static \app\admin\model\Admin getInfo() 获取当前登录管理员完整信息
 * @method static string getUsername() 获取当前登录管理员名
 * @method static string getNickname() 获取当前登录管理员昵称
 * @method static array getRoles() 获取当前登录管理员所属角色
 * @method static array getRoleIds() 获取当前登录管理员所属角色ID集合
 * @method static array getPermissions() 获取当前登录管理员拥有的权限
 * @method static array getPermissionIds() 获取当前登录管理员拥有的权限ID集合
 * @method static bool hasPermission($permissionId) 检查当前登录管理员是否有指定权限
 * @method static bool isLogin() 检查管理员是否已登录
 * @method static void login(\app\admin\model\Admin $admin) 建立登录会话
 * @method static bool logout() 退出登录
 * @method static bool isLockScreen() 检查当前登录管理员是否已锁定屏幕
 * @method static bool isSuperAdmin() 检查当前登录管理员是否为超级管理员
 */
class CurrentAdmin extends Facade
{
    /**
     * 获取当前Facade对应类名（或者已经绑定的容器对象标识）
     *
     * @return string
     */
    protected static function getFacadeClass(): string
    {
        return \app\admin\service\AdminService::class;
    }

}
