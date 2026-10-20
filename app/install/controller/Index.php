<?php
namespace app\install\controller;

use think\facade\View;
use think\facade\Cache;
use think\facade\Request;
use app\install\service\InstallService;

class Index
{
    // 安装步骤
    protected $steps = [
        'index'    => '系统介绍',
        'license'  => '安装协议',
        'check'    => '环境检测',
        'config'   => '数据库配置',
        'install'  => '开始安装',
        'complete' => '安装完成',
    ];
    
    /**
     * 系统介绍
     */
    public function index()
    {
        // 严格判断：已安装（存在 app/install/install.lock）则显示“已安装”提示页，不再进入安装向导
        if (InstallService::checkInstalled()) {
            return $this->render('installed', [
                'install_info' => InstallService::getInstallStatus(),
            ]);
        }
        return $this->render('index', [
            'system_name' => 'WiseAdmin 后台管理系统',
            'version' => '1.2.0',
            'author' => 'Your Company',
            'website' => 'https://www.wiseadmin.top',
            'requirements' => [
                '服务器系统' => 'Linux/Windows',
                'Web服务器' => 'Apache/Nginx',
                'PHP版本' => '>=8.1.0',
                '数据库' => 'MySQL >=5.7',
            ],
            'features' => [
                'RBAC权限管理',
                '菜单管理',
                '用户管理',
                '操作日志',
                'API接口',
                '多语言支持',
            ],
        ]);
    }
    
    /**
     * 安装协议
     */
    public function license()
    {
        $blocked = $this->blockIfInstalled();
        if ($blocked !== null) {
            return $blocked;
        }

        $licenseFile = dirname(__DIR__) . '/license.txt';
        
        if (file_exists($licenseFile)) {
            $license = file_get_contents($licenseFile);
        } else {
            $license = "系统安装协议\n\n请同意协议以继续安装。";
        }
        
        return $this->render('license', [
            'license' => $license,
        ]);
    }
    
    /**
     * 环境检测
     */
    public function check()
    {
        $blocked = $this->blockIfInstalled();
        if ($blocked !== null) {
            return $blocked;
        }

        $requirements = InstallService::checkEnvironment();
        $permissions = InstallService::checkDirectoryPermissions();
        
        $allPassed = true;
        $errors = [];
        
        foreach ($requirements as $key => $req) {
            if (!$req['result']) {
                $allPassed = false;
                $errors[] = $req['name'] . ' 不满足要求';
            }
        }
        
        foreach ($permissions as $key => $perm) {
            if (!$perm['result']) {
                $allPassed = false;
                $errors[] = $perm['name'] . ' 目录不可写';
            }
        }
        
        return $this->render('check', [
            'requirements' => $requirements,
            'permissions' => $permissions,
            'all_passed' => $allPassed,
            'errors' => $errors,
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '未知',
        ]);
    }
    
    /**
     * 数据库配置
     */
    public function config()
    {
        $blocked = $this->blockIfInstalled();
        if ($blocked !== null) {
            return $blocked;
        }

        if (Request::isPost()) {
            $data = Request::post();
            
            // 验证数据
            $validate = new \think\Validate([
                'hostname' => 'require',
                'database' => 'require|alphaDash',
                'username' => 'require',
                'hostport' => 'require|number',
                'prefix'   => 'alphaDash',
                'admin_username' => 'require|alphaDash|min:3',
                'admin_password' => 'require|min:6|confirm',
                'admin_password_confirm' => 'require|min:6',
                'admin_email'    => 'require|email',
                'site_name'      => 'require|min:2',
            ]);
            
            $validate->message([
                'admin_password.confirm' => '两次密码不一致',
            ]);
            
            if (!$validate->check($data)) {
                return json([
                    'code' => 0,
                    'msg' => $validate->getError(),
                ]);
            }
            
            // 测试数据库连接
            $result = InstallService::testDatabaseConnection([
                'hostname' => $data['hostname'],
                'database' => $data['database'],
                'username' => $data['username'],
                'password' => $data['password'],
                'hostport' => $data['hostport'],
            ]);
            
            if (!$result['success']) {
                return json([
                    'code' => 0,
                    'msg' => $result['message'],
                ]);
            }
            
            // 检查数据库版本
            if (isset($result['version_ok']) && !$result['version_ok']) {
                return json([
                    'code' => 0,
                    'msg' => '数据库版本过低，要求 MySQL >= 5.7.0，当前版本：' . ($result['database_version'] ?? '未知'),
                ]);
            }
            
            // 保存配置到缓存（临时）
            Cache::set('install_config', $data, 7200); // 2小时有效期
            
            return json([
                'code' => 1,
                'msg' => '配置验证成功',
                'data' => $result,
            ]);
        }
        
        // 生成随机的管理员密码建议
        $suggestPassword = $this->generatePassword(10);
        
        return $this->render('config', [
            'default' => [
                'hostname' => '127.0.0.1',
                'hostport' => '3306',
                'username' => 'root',
                'prefix'   => 'ws_',
                'admin_username' => 'admin',
                'admin_email' => 'admin@example.com',
                'site_name' => '后台管理系统',
            ],
            // 'suggest_password' => $uggestPassword,
        ]);
    }
    
    /**
     * 执行安装
     */
    public function install()
    {
        $blocked = $this->blockIfInstalled();
        if ($blocked !== null) {
            return $blocked;
        }

        if (Request::isPost()) {
            $config = Cache::get('install_config');
            
            if (!$config) {
                return json([
                    'code' => 0,
                    'msg' => '配置信息已过期，请返回重新填写',
                    'redirect' => url('/config'),
                ]);
            }
            
            // 执行安装
            $result = InstallService::executeInstall($config);
            
            if ($result['success']) {
                // 清除临时配置
                Cache::delete('install_config');
                
                // 记录安装成功
                Cache::set('install_success_data', $result['data'], 3600);
                
                return json([
                    'code' => 1,
                    'msg' => '安装成功',
                    'redirect' => url('/complete'),
                ]);
            } else {
                return json([
                    'code' => 0,
                    'msg' => $result['message'],
                    'error' => $result['error'] ?? '',
                ]);
            }
        }
        
        $config = Cache::get('install_config', []);
        
        if (empty($config)) {
            return redirect('/config');
        }
        
        return $this->render('install', [
            'config' => $config,
            'hide_password' => str_repeat('*', strlen($config['admin_password'] ?? '')),
        ]);
    }
    
    /**
     * 安装完成
     */
    public function complete()
    {
        $installStatus = InstallService::getInstallStatus();
        
        if (!$installStatus['installed']) {
            return redirect('/index');
        }
        
        $successData = Cache::get('install_success_data', [
            'admin_url' => '/admin/login',
            'admin_user' => 'admin',
            'admin_password' => 'admin123',
        ]);
        
        // 清除安装缓存
        Cache::delete('install_success_data');
        
        // 出于安全考虑：明文密码绝不传入视图；管理员账号做掩码处理，避免敏感信息落入页面/缓存
        return $this->render('complete', [
            'install_info' => $installStatus,
            'admin_url' => $successData['admin_url'] ?? '/admin/login',
            'admin_user_masked' => self::maskAccount($successData['admin_user'] ?? 'admin'),
        ]);
    }
    
    /**
     * 对管理员账号做掩码，仅保留首尾用于识别，避免账号明文出现在页面/接口中
     */
    protected static function maskAccount(string $account): string
    {
        $len = mb_strlen($account);
        if ($len <= 1) {
            return str_repeat('*', $len);
        }
        if ($len === 2) {
            return mb_substr($account, 0, 1) . '*';
        }
        return mb_substr($account, 0, 2) . str_repeat('*', $len - 3) . mb_substr($account, -1);
    }
    
    /**
     * 重新安装（谨慎使用）
     */
    public function reinstall()
    {
        // 多重判断：与 CLI 安装命令一致，依据 install.lock + .env 的 ALLOW_REINSTALL 判定
        $canReinstall = InstallService::canReinstall();
        // 是否已在 .env 配置了网页重装安全码
        $reinstallKey = (string) env('REINSTALL_KEY', '');
        $keyConfigured = $reinstallKey !== '';

        if (!Request::isPost()) {
            return $this->render('reinstall', [
                'can_reinstall'  => $canReinstall,
                'key_configured' => $keyConfigured,
            ]);
        }

        // 第一道守卫：ALLOW_REINSTALL=false 或尚未安装，直接拒绝（与 CLI --force 守卫对齐）
        if (!$canReinstall) {
            return json([
                'code' => 0,
                'msg' => '当前环境已禁用网页重装（请在 .env 设置 ALLOW_REINSTALL = true）',
            ]);
        }

        // 第二道守卫：必须预先在 .env 配置 REINSTALL_KEY，否则网页重装入口视为禁用
        if (!$keyConfigured) {
            return json([
                'code' => 0,
                'msg' => '未配置网页重装安全码（请在 .env 设置 REINSTALL_KEY）',
            ]);
        }

        // 验证安全码（防止误操作，密码来自 .env 而非写死在代码里）
        $password = Request::post('password', '');
        if (!is_string($password) || $password !== $reinstallKey) {
            return json([
                'code' => 0,
                'msg' => '安全验证失败',
            ]);
        }
        
        $lockFile = InstallService::getLockFilePath();
        
        if (file_exists($lockFile)) {
            if (!unlink($lockFile)) {
                return json([
                    'code' => 0,
                    'msg' => '删除安装锁文件失败',
                ]);
            }
        }
        
        // 清除缓存
        Cache::clear();
        Cache::delete('install_config');
        Cache::delete('install_success_data');
        
        return json([
            'code' => 1,
            'msg' => '重置成功，即将跳转到安装页面',
            'redirect' => url('/index'),
        ]);
    }
    
    /**
     * 检查安装状态（API接口）
     */
    public function status()
    {
        $status = InstallService::getInstallStatus();
        
        return json([
            'code' => 1,
            'data' => $status,
        ]);
    }
    
    /**
     * 已安装拦截：GET 跳转安装首页（首页会显示“已安装”提示），POST 返回错误
     *
     * @return \think\Response|null 已安装返回响应对象，未安装返回 null
     */
    protected function blockIfInstalled()
    {
        if (!InstallService::checkInstalled()) {
            return null;
        }
        if (Request::isPost()) {
            return json([
                'code' => 0,
                'msg' => '系统已安装，禁止执行安装操作',
            ]);
        }
        return redirect('/install');
    }

    /**
     * 渲染模板
     */
    protected function render(string $step, array $data = [])
    {
        $data['step'] = $step;
        $data['steps'] = $this->steps;
        $data['current_step'] = array_search($step, array_keys($this->steps));
        $data['total_steps'] = count($this->steps);
        $data['base_url'] = Request::rootUrl();

        // 添加安装锁检查
        $data['installed'] = InstallService::checkInstalled();
        
        return View::fetch($step, $data);
    }
    
    /**
     * 生成随机密码
     */
    private function generatePassword(int $length = 10): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }
}