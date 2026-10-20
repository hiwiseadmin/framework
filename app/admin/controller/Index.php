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
        // 获取当前ADMIN信息
        $admin = CurrentAdmin::getInfo();
        $menus = Menu::getMenuByRoleId(CurrentAdmin::getRoleIds());

        // 按展示区块分类菜单树（已含 RBAC 过滤）
        $layout = MenuLauncherService::classifyMenuLayout($menus);

        // 获取当前和上次登录记录
        $loginLog = LoginLog::where('admin_id', $admin['id'])->limit(2)->order('id', 'desc')->select();

        return $this->assign([
            'menus'           => $menus,                    // 原始树，兜底
            'consoleMenus'    => $layout['consoleMenus'],   // 顶部：控制台区块（system 顶级）
            'extendRoot'      => $layout['extendRoot'],     // 顶部：插件扩展容器（含单顶级插件入口）
            'pinnedPlugins'   => $layout['pinnedPlugins'],  // 顶部：常用插件区（pinned 多顶级插件）
            'launcherPlugins' => $layout['launcherPlugins'],// 启动台：全部插件入口
            'admin' => $admin,
            'current_login_log' => $loginLog[0] ?? [],
            'last_login_log' => $loginLog[1] ?? [],
        ])->fetch();
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
            // 清空配置缓存
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

        // 兼容 application/json 与 application/x-www-form-urlencoded 两种请求体。
        // 注意：ThinkPHP 的 $request->post() 只解析表单，JSON body 不会进入 $_POST，
        // 必须从原始 body 手动解码（前端 wise-theme.js 以 JSON 方式提交）。
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

        // 仅提取主题三维度，杜绝写入其他配置
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
        // 获取当前ADMIN信息
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
        // 处理解锁请求
        $password = $this->request->post('password', '', 'strip_tags');
        
        // 获取当前ADMIN信息
        $admin = CurrentAdmin::getInfo();

        // 检查ADMIN是否存在
        if (!$admin) {
            return $this->error('解锁失败，未找到已登录ADMIN', [], 404);
        }

        // 验证密码逻辑
        if (password_verify($password, $admin['password'])) {
            // 密码验证成功，清除锁屏状态
            session('admin_lockscreen', null);

            // 解锁成功，返回成功状态
            return $this->success('解锁成功');
        } else {
            // 解锁失败
            return $this->error('密码错误');
        }
    }
}
