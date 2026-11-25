<?php
namespace app\admin\controller;

use app\admin\model\Menu;
use app\admin\model\LoginLog;
use app\admin\facade\CurrentAdmin;
use app\common\service\ConfigService;
use app\common\service\MenuLauncherService;
use app\common\service\ThemeService;

class Index extends BaseController
{
    protected function _initialize(){
        $this->setSubframe(false);
    }
    
    /**
     * 后台首页
     */
    public function index()
    {
        $admin = CurrentAdmin::getInfo();
        $menus = Menu::getMenuByRoleId(CurrentAdmin::getRoleIds());

        $layout = MenuLauncherService::classifyMenuLayout($menus);

        $loginLog = LoginLog::where('admin_id', $admin['id'])->limit(2)->order('id', 'desc')->select();

        return $this->assign([
            'menus'           => $menus,
            'consoleMenus'    => $layout['consoleMenus'],
            'extendRoot'      => $layout['extendRoot'],
            'pinnedPlugins'   => $layout['pinnedPlugins'],
            'launcherPlugins' => $layout['launcherPlugins'],
            'admin' => $admin,
            'current_login_log' => $loginLog[0] ?? [],
            'last_login_log' => $loginLog[1] ?? [],
            'canClearCache' => $this->canClearCache(),
        ])->fetch();
    }

    /**
     * 当前管理员是否可清理缓存
     *
     * 权限标识与 Idler::clearCache() 上的 #[AuthPermission] 保持一致
     * （admin.idler.clearCache）。这里只决定「菜单是否渲染」，
     * 接口侧仍由 RBAC 中间件独立鉴权，不依赖前端隐藏。
     */
    protected function canClearCache(): bool
    {
        try {
            $rbac = app(\wise\auth\service\RbacManager::class);
            $idents = $rbac->getUserPermissions(CurrentAdmin::getAdminId(), 'admin');

            return in_array('*', $idents, true) || in_array('admin.idler.clearCache', $idents, true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 当前登录管理员的权限快照（供前端按钮/元素显隐）
     *
     * GET /admin/index/permissions
     *
     * 设计取舍：
     *   - 权限集合随角色固定，**不需要每个页面注入**（那会让每份 HTML 都背上几百条 ident）；
     *   - 前端首次需要时拉一次，写入 sessionStorage，同标签内所有页面（含 iframe）复用；
     *   - 本路由在 `wise-auth.authenticated_routes` 的 `/admin/index/*` 内，登录即可访问，
     *     无需单独权限节点（否则非超管会因"未匹配节点"被 403）。
     *
     * 安全边界：本接口只影响前端显隐；接口侧仍由 RBAC 中间件独立鉴权（403 兜底）。
     *
     * @return \think\response\Json
     */
    public function permissions()
    {
        $adminId = (int) CurrentAdmin::getAdminId();
        if ($adminId <= 0) {
            return $this->error('未登录', [], 401);
        }

        try {
            $service = app(\app\admin\service\AdminService::class);
            $super   = (bool) $service->isSuperAdmin();

            $perms = $super ? ['*'] : array_values(array_filter(
                array_map('strval', (array) $service->getPermissions()),
                static fn (string $ident): bool => $ident !== ''
            ));

            $version = 0;
            try {
                $version = (int) app(\wise\auth\service\RbacManager::class)
                    ->getUserPermissionVersion($adminId, 'admin');
            } catch (\Throwable $e) {
            }

            return $this->success('ok', [
                'uid'     => $adminId,
                'super'   => $super,
                'perms'   => $perms,
                'version' => $version,
            ]);
        } catch (\Throwable $e) {
            return $this->error('权限加载失败');
        }
    }

    /**
     * 获取「应用启动台」插件入口数据（JSON）。
     *
     * 启动台在首页是服务端直出的 SSR 快照，安装/卸载插件后菜单集合会变化，
     * 但快照不会自动更新。本接口供前端在插件生命周期操作成功后按需重取，
     * 避免"装了插件必须手动刷新整个后台才看得到启动台入口"。
     *
     * @return \think\response\Json
     */
    public function launcher()
    {
        try {
            $menus  = Menu::getMenuByRoleId(CurrentAdmin::getRoleIds());
            $layout = MenuLauncherService::classifyMenuLayout($menus);
        } catch (\Throwable $e) {
            return $this->error('获取启动台数据失败：' . $e->getMessage());
        }

        return $this->success('获取成功', [
            'launcherPlugins' => $layout['launcherPlugins'],
        ]);
    }

    public function config()
    {
        if ($this->request->isPost()) {
            if (!$this->canClearCache()) {
                return $this->error('无权限清理配置缓存', null, 403);
            }

            ConfigService::clearCache();

            return $this->success('配置更新成功');
        }

        $config = [
            'theme' => ThemeService::resolve()
        ];

        return $this->success('获取配置成功', $config);
    }

    /**
     * 保存当前用户的主题偏好
     *
     * 写入 admins.ext_fields.config.*，未登录时降级为纯前端本地存储。
     *
     * @return \think\response\Json
     */
    public function saveTheme()
    {
        $adminId = $this->currentAdminId();
        if (!$adminId) {
            return $this->error('未登录，主题仅保存在本地', [], 401);
        }

        $data = $this->request->post();
        if (!$data) {
            $content = (string) $this->request->getContent();
            if ($content !== '') {
                $parsed = json_decode($content, true);
                if (is_array($parsed)) {
                    $data = $parsed;
                }
            }
        }

        $values = array_intersect_key($data, array_flip(['mode', 'scheme', 'sidebar']));
        if (!$values) {
            return $this->error('没有需要保存的主题配置');
        }

        try {
            $theme = ThemeService::save($values);
        } catch (\Throwable $e) {
            return $this->error('主题保存失败：' . $e->getMessage());
        }

        return $this->success('主题保存成功', ['theme' => $theme]);
    }

    /**
     * 重置当前用户的主题偏好，恢复为网站默认配置
     *
     * @return \think\response\Json
     */
    public function resetTheme()
    {
        $adminId = $this->currentAdminId();
        if (!$adminId) {
            return $this->error('未登录，无需重置', [], 401);
        }

        try {
            $theme = ThemeService::resetUser();
        } catch (\Throwable $e) {
            return $this->error('主题重置失败：' . $e->getMessage());
        }

        return $this->success('主题已恢复为网站默认', ['theme' => $theme]);
    }

    /**
     * 获取当前登录管理员ID（未登录返回 null）
     *
     * @return int|null
     */
    protected function currentAdminId(): ?int
    {
        try {
            $id = (int) CurrentAdmin::getAdminId();
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 锁屏页面
     */
    public function lockscreen()
    {
        $admin = CurrentAdmin::getInfo();

        if (!$admin['id']) {
            return $this->error('锁屏失败，未找到已登录ADMIN');
        }

        session('admin_lockscreen', $admin['id']);
        $this->success('锁屏成功');
    }

    /**
     * 解锁处理
     * @return \think\response\Json
     */
    public function unlock()
    {
        $password = $this->request->post('password', '', 'strip_tags');
        
        $admin = CurrentAdmin::getInfo();

        if (!$admin) {
            return $this->error('解锁失败，未找到已登录ADMIN', [], 404);
        }

        if (password_verify($password, $admin['password'])) {
            session('admin_lockscreen', null);

            return $this->success('解锁成功');
        } else {
            return $this->error('密码错误');
        }
    }
}
