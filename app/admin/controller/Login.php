<?php
namespace app\admin\controller;

use app\admin\model\Admin;
use app\admin\service\AdminService;
use app\admin\service\RememberLoginService;
use app\event\AdminLogin;
use app\event\AdminLogout;
use app\admin\facade\CurrentAdmin;
use think\facade\Event;
use wise\login\middleware\CsrfTokenMiddleware;
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
        if (CurrentAdmin::isLogin()) {
            $url = $this->request->get('referer') ?? url('/admin/index');
            return redirect($url);
        }
        
        if(config('entry.enabled', false) && empty(session('admin_entry'))){
            return abort(404, '页面异常');
        }

        $captchaImageUrl = url('/wise/captcha/image');
        $captchaCreateUrl = url('/wise/captcha/create');
        $dologin = url('/admin/login/doLogin');
        $referer = $this->request->get('referer');

        $rememberService = app(RememberLoginService::class);
        $rememberTtl     = $rememberService->getTtl();

        return $this->assign([
            'dologin_url'          => $dologin,
            'captcha_image_url'    => $captchaImageUrl,
            'captcha_create_url'   => $captchaCreateUrl,
            'captcha_enabled'      => config('wise-auth.login.captcha.enabled', true),
            'referer'              => $referer,
            'csrf_token'           => CsrfTokenMiddleware::getToken($this->request),
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

            $admin = Admin::where('username', $username)->find();
            if (!$admin) {
                return $this->error('账号不存在');
            }
            
            if ($admin['status'] != 1) {
                return $this->error('账号已被禁用');
            }

            if (!password_verify($password, $admin['password'])) {
                return $this->error('密码错误');
            }

            $this->setSession($admin);

            $remember = app(RememberLoginService::class);
            $remember->revokeCurrent();
            if ($this->request->post('keeplogin')) {
                $remember->issue($admin);
            }

            $admin->last_login_time = date('Y-m-d H:i:s');
            $admin->last_login_ip = $this->request->ip();
            $admin->save();

            cookie('loginSuccessModalShow', 'true', ['path' => '/admin', 'httponly' => false, 'expire' => 86400]);

            Event::trigger(new AdminLogin($admin->toArray(), '登录成功', true));

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
        app(RememberLoginService::class)->revokeCurrent();

        CurrentAdmin::logout();

        Event::trigger(new AdminLogout($admin->toArray()));

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