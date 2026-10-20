<?php
namespace app\admin\controller;

use app\admin\model\Admin;
use app\admin\service\AdminService;
use app\admin\service\RememberLoginService;
use app\event\AdminLogin;
use app\event\AdminLogout;
use app\admin\facade\CurrentAdmin;
use think\facade\Event;
use wise\operationlog\attribute\OperationLog;

#[OperationLog(channel: 'admin', tags: ['admin', 'login'])]
class Login extends BaseController
{
    protected function _initialize(){
        $this->setSubframe(false);
    }
    
    /**
     * 登录页面
     * @return string
     */
    public function index()
    {    
        // 如果已登录，跳转到后台首页
        if (CurrentAdmin::isLogin()) {
            $url = $this->request->get('referer') ?? url('/admin/index');
            return redirect($url);
        }
        
        // 检查入口
        if(config('entry.enabled', false) && empty(session('admin_entry'))){
            return abort(404, '页面异常');
        }

        // 使用 wise-login-security 验证码（替代旧 think-captcha）
        $captchaImageUrl = url('/wise/captcha/image');
        $captchaCreateUrl = url('/wise/captcha/create');
        $dologin = url('/admin/login/doLogin');
        $referer = $this->request->get('referer');

        // 保持登录：有效期由配置决定，页面提示文案与真实有效期保持一致
        $rememberService = app(RememberLoginService::class);
        $rememberTtl     = $rememberService->getTtl();

        return $this->assign([
            'dologin_url'          => $dologin,
            'captcha_image_url'    => $captchaImageUrl,
            'captcha_create_url'   => $captchaCreateUrl,
            'captcha_enabled'      => config('wise-login-security.captcha.enabled', true),
            'referer'              => $referer,
            'keep_login_enabled'   => $rememberService->isEnabled(),
            'keep_login_tip'       => '最久保持登录 ' . ($rememberTtl % 86400 === 0
                ? intdiv($rememberTtl, 86400) . ' 天'
                : max(1, (int) round($rememberTtl / 3600)) . ' 小时'),
        ])->fetch();
    }
    
    /**
     * 登录处理
     * 
     * 注意：IP 黑名单、失败锁定、验证码检查已由 LoginGuardMiddleware 前置处理，
     * 失败记录和设备指纹由 LoginResultMiddleware 后置处理。
     * 本方法仅负责：账号存在性、状态、密码验证、会话设置。
     *
     * @return \think\response\Json
     */
    #[OperationLog(template: '管理员 {username} 登录', level: 'INFO')]
    public function doLogin()
    {
        if ($this->request->isPost()) {
            $username = $this->request->post('username');
            $password = $this->request->post('password');

            // 查找管理员
            $admin = Admin::where('username', $username)->find();
            if (!$admin) {
                return $this->error('账号不存在');
            }
            
            // 检查状态
            if ($admin['status'] != 1) {
                return $this->error('账号已被禁用');
            }

            // 验证密码
            if (!password_verify($password, $admin['password'])) {
                return $this->error('密码错误');
            }

            // 设置会话
            $this->setSession($admin);

            // 保持登录：勾选则签发 7 天持久化令牌；未勾选则清掉本机可能残留的旧令牌
            $remember = app(RememberLoginService::class);
            $remember->revokeCurrent();
            if ($this->request->post('keeplogin')) {
                $remember->issue($admin);
            }

            // 更新登录信息（失败计数由 LoginResultMiddleware 自动处理）
            $admin->last_login_time = date('Y-m-d H:i:s');
            $admin->last_login_ip = $this->request->ip();
            $admin->save();

            // 登录成功后设置 cookie 标记未显示登录成功弹窗
            cookie('loginSuccessModalShow', 'true', ['path' => '/admin', 'httponly' => false, 'expire' => 86400]);

            // 触发管理员登录事件
            Event::trigger(new AdminLogin($admin->toArray(), '登录成功', true));

            // LoginResultMiddleware 需要 data.user_id 来记录设备、清除锁定等
            return $this->success('登录成功', [
                'url'     => (string)url('/admin/index'),
                'user_id' => $admin['id'],
            ]);
        }
        
        return $this->error('请求方式错误');
    }
    
    /**
     * 退出登录
     * @return \think\response\Redirect
     */
    #[OperationLog(template: '管理员退出登录', level: 'INFO')]
    public function logout()
    {
        $admin = CurrentAdmin::getInfo();
        if($admin == null){
            return redirect((string)url('/'));
        }
        // 撤销当前浏览器的保持登录令牌（其它设备不受影响）
        app(RememberLoginService::class)->revokeCurrent();

        CurrentAdmin::logout();

        // 触发管理员退出事件
        Event::trigger(new AdminLogout($admin->toArray()));

        // 启用安全入口 且 入口Session不存在时，重定向到首页
        if(config('entry.enabled', false) && empty(session('admin_entry'))){
            return redirect((string)url('/'));
        }else{
            return redirect((string)url('/admin/login'));
        }
    }

    /**
     * 设置会话
     *
     * 统一委托 AdminService::login()，与「保持登录」自动恢复共用同一套 Session 键。
     *
     * @param Admin $admin 管理员信息
     */
    protected function setSession(Admin $admin): void
    {
        app(AdminService::class)->login($admin);
    }

}