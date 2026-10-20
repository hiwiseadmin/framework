<?php
namespace app\admin\service;

use think\Facade;
use think\facade\Session;
use app\admin\model\Admin as AdminModel;
use wise\auth\facade\Rbac;

/**
 * 当前登录管理员Facade类
 * 提供静态方式快速获取当前登录管理员的相关信息
 *
 * @method int getAdminId() 获取当前登录管理员ID
 * @method ?AdminModel getInfo() 获取当前登录管理员完整信息
 * @method string getUsername() 获取当前登录管理员名
 * @method string getNickname() 获取当前登录管理员昵称
 * @method array getRoles() 获取当前登录管理员所属角色
 * @method array getRoleIds() 获取当前登录管理员所属角色ID集合
 * @method array getPermissions() 获取当前登录管理员拥有的权限
 * @method array getPermissionIds() 获取当前登录管理员拥有的权限ID集合
 * @method bool hasPermission($permissionId) 检查当前登录管理员是否有指定权限
 * @method bool isLogin() 检查管理员是否已登录
 * @method bool logout() 退出登录
 * @method bool isLockScreen() 检查当前登录管理员是否已锁定屏幕
 * @method bool isSuperAdmin() 检查当前登录管理员是否为超级管理员
 *
 */
class AdminService
{
    /**
     * 当前登录管理员id
     * @var int|null
     */
    protected $adminId;

    /**
     * 当前登录管理员信息
     * @var AdminModel|null
     */
    protected $adminInfo;

    /**
     * 当前管理员角色缓存
     * @var array|null
     */
    protected $roles;

    /**
     * 当前管理员权限缓存
     * @var array|null
     */
    protected $permissions;

    /**
     * token管理对象
     * @var object|null
     */
    protected $tokenManager;

    public function __construct()
    {
        // 从 Session 加载管理员 ID（Session Facade 在 Swoole 下上下文安全）
        $this->adminId = Session::get('admin_id') ?: null;
        if ($this->adminId) {
            $this->loadAdminInfo();
        }
    }

    /**
     * 获取当前登录管理员id
     * @return int|null
     */
    public function getAdminId()
    {
        return $this->adminId;
    }

    /**
     * 获取当前登录管理员完整信息
     * @param bool $refresh 是否强制刷新管理员信息
     * @return AdminModel|null
     */
    public function getInfo($refresh = false)
    {
        $this->loadAdminInfo($refresh);

        return $this->adminInfo ?: null;
    }

    /**
     * 获取当前登录管理员名
     * @return string
     */
    public function getUsername()
    {
        $this->loadAdminInfo();
        return $this->adminInfo->username ?? '';
    }

    /**
     * 获取当前登录管理员昵称
     * @return string
     */
    public function getNickname()
    {
        $this->loadAdminInfo();
        return $this->adminInfo->nickname ?? '';
    }

    /**
     * 获取当前登录管理员所属角色
     * @return array
     */
    public function getRoles()
    {
        if (empty($this->roles)) {
            $this->loadAdminInfo();
            $roles = !empty($this->adminInfo) ? $this->adminInfo->getRoles() : [];
            $this->roles = is_array($roles) ? $roles : ($roles ? $roles->toArray() : []);
        }
        return $this->roles;
    }

    /**
     * 获取当前登录管理员所属角色id集合
     * @return array
     */
    public function getRoleIds()
    {
        $roles = $this->getRoles();
        return !empty($roles) ? array_column($roles, 'id') : [];
    }

    /**
     * 获取当前登录管理员拥有的权限
     * @return array
     */
    public function getPermissions()
    {
        if (is_null($this->permissions)) {
            $this->loadAdminInfo();

            if ($this->adminInfo) {
                $this->permissions = $this->adminInfo->getAllPermissions();
            } else {
                $this->permissions = [];
            }
        }
        return $this->permissions ?: [];
    }

    /**
     * 获取当前登录管理员拥有的权限id集合
     * @return array
     */
    public function getPermissionIds()
    {
        $permissions = $this->getPermissions();
        return array_column($permissions, 'id');
    }

    /**
     * 检查当前登录管理员是否有指定权限
     *
     * @param int $permissionId 权限id
     * @return bool
     */
    public function hasPermission(int $permissionId): bool
    {
        $this->loadAdminInfo();
        if (!empty($this->adminInfo) && $this->adminInfo->isSuperAdmin()) {
            return true;
        }

        return in_array($permissionId, $this->getPermissionIds());
    }

    /**
     * 建立登录会话
     *
     * 供「账号密码登录」与「保持登录自动恢复」共用，避免两处写入
     * 不同的 Session 键而导致状态不一致。
     *
     * @param AdminModel $admin 管理员模型
     * @return void
     */
    public function login(AdminModel $admin): void
    {
        Session::set('admin_id', $admin['id']);
        Session::set('admin_username', $admin['username']);
        Session::set('admin_nickname', $admin['nickname']);
        Session::set('admin_last_login_time', date('Y-m-d H:i:s'));

        // 同步实例缓存，避免同请求内继续读到旧的登录态
        $this->adminId     = (int) $admin['id'];
        $this->adminInfo   = $admin;
        $this->roles       = null;
        $this->permissions = null;
    }

    /**
     * 检查管理员是否已登录
     * @param bool $autoRenew 是否自动续期登录时间
     * @return bool
     */
    public function isLogin(bool $autoRenew = false): bool
    {
        $this->loadAdminInfo();

        if ($autoRenew && $this->adminId) {
            Session::set('admin_id', $this->adminId);
            Session::set('admin_username', $this->adminInfo['username']);
            Session::set('admin_nickname', $this->adminInfo['nickname']);
            Session::set('admin_last_login_time', Session::get('admin_last_login_time'));
        }
        return !empty($this->adminId);
    }

    /**
     * 检查管理员是否为锁屏状态
     * @return bool
     */
    public function isLockScreen()
    {
        return $this->adminId && $this->adminId === Session::get('admin_lockscreen');
    }

    /**
     * 退出登录
     * @param array|string $keepSessions 要保留的session键名数组或单个键名
     * @return bool
     */
    public function logout($keepSessions = [])
    {
        // 清除session，保留特定session
        $keepKeys = array_merge(
            is_array($keepSessions) ? $keepSessions : [$keepSessions],
            ['admin_lockscreen', 'needcaptcha', 'admin_entry']
        );
        $keepValues = [];
        foreach ($keepKeys as $key) {
            $keepValues[$key] = Session::get($key);
        }
        Session::clear();

        // 恢复保留的session
        foreach ($keepValues as $key => $value) {
            Session::set($key, $value);
        }

        // 重置属性
        $this->adminId = null;
        $this->adminInfo = null;
        $this->roles = null;
        $this->permissions = null;

        return true;
    }

    /**
     * 判断管理员是否为超级管理员
     * @return bool
     */
    public function isSuperAdmin()
    {
        $this->loadAdminInfo();
        return !empty($this->adminInfo) && $this->adminInfo->isSuperAdmin();
    }

    /**
     * 加载管理员信息
     * @param bool $refresh 是否强制刷新管理员信息
     */
    protected function loadAdminInfo($refresh = false)
    {
        $this->adminId = $this->adminId ?? Session::get('admin_id') ?: null;
        if (!empty($this->adminId) && (empty($this->adminInfo) || $refresh)) {
            $admin = AdminModel::find($this->adminId);
            if ($admin) {
                $this->adminInfo = $admin;
            } else {
                $this->logout();
            }
        }
    }
};
