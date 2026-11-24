<?php
declare(strict_types=1);

namespace app\api\controller\admin;

use app\admin\model\Admin;
use app\admin\model\LoginLog;
use app\event\AdminLogin;
use think\facade\Cache;
use think\facade\Event;
use think\Request;
use think\response\Json;
use wise\api\response\ErrorCode;
use wise\login\lock\LoginLockManager;
use wise\login\totp\TotpManager;

/**
 * 后台管理端 App 认证（/api/admin/*）
 *
 * 与后台 Web 登录的差异：
 *   - 不下发 Session，只下发 JWT（admin guard，独立密钥/issuer/黑名单）；
 *   - TOTP 采用两段式：login 返回 pending_token → POST /api/admin/2fa 换正式 token；
 *   - 不写「保持登录」Cookie（那是浏览器语义）。
 *
 * 复用：
 *   - 失败锁定：wise-login-security 的 LoginLockManager（与 Web 同一套阈值/存储）；
 *   - 登录事件：app\event\AdminLogin（登录日志监听器照常生效）。
 */
class Auth extends Base
{
    /**
     * 账号密码登录
     *
     * POST /api/admin/login
     * 参数：username、password、totp_code（已启用 2FA 时）
     */
    public function login(Request $request): Json
    {
        if (!$request->isPost()) {
            return $this->error('请求方式错误', null, ErrorCode::METHOD_NOT_ALLOWED);
        }

        $username = trim((string) $request->param('username', ''));
        $password = (string) $request->param('password', '');
        $totpCode = trim((string) $request->param('totp_code', ''));

        if ($username === '' || $password === '') {
            return $this->error('请输入账号和密码', null, ErrorCode::INVALID_PARAM);
        }

        $ip          = (string) $request->ip();
        $lockEnabled = (bool) config('admin_api.login.lock', true);
        $lock        = $lockEnabled ? app(LoginLockManager::class) : null;

        if ($lock !== null && $lock->isLocked($username, $ip)) {
            return $this->error('登录失败次数过多，请稍后再试', [
                'retry_after' => $lock->lockTtl($username, $ip),
            ], ErrorCode::RATE_LIMITED);
        }

        $admin = Admin::where('username', $username)->find();
        if (!$admin || !password_verify($password, (string) $admin->getData('password'))) {
            $this->recordFailure($lock, $admin, $username, $ip, '账号或密码错误');

            return $this->error('账号或密码错误', null, ErrorCode::UNAUTHORIZED);
        }

        if ((int) $admin->status !== 1) {
            $this->recordFailure($lock, $admin, $username, $ip, '账号已被禁用');

            return $this->error('账号已被禁用', null, ErrorCode::FORBIDDEN);
        }

        if ($admin->isTotpEnabled()) {
            $pendingTtl = (int) config('admin_api.pending_ttl', 300);

            if ($totpCode === '') {
                return $this->success('需要双因素认证', [
                    'require_2fa'   => true,
                    'pending_token' => $this->issueToken($admin, ['scope' => 'pending_2fa'], $pendingTtl),
                    'token_type'    => 'Bearer',
                    'expires_in'    => $pendingTtl,
                ]);
            }

            if (!app(TotpManager::class)->verify($admin, $totpCode)) {
                $this->recordFailure($lock, $admin, $username, $ip, '动态验证码错误');

                return $this->error('动态验证码错误', null, ErrorCode::UNAUTHORIZED);
            }
        }

        return $this->completeLogin($admin, $lock, $ip);
    }

    /**
     * 双因素认证第二步：用 pending token 换正式 token
     *
     * POST /api/admin/2fa
     * 参数：code（TOTP 动态码）
     *
     * 该路由只经过 JwtAuth（验签），不经过 AdminApiAuth —— pending token 是合法签名，
     * 但只能用于本接口，不能访问业务接口（AdminApiAuth 会按 scope 拒绝）。
     */
    public function verify2fa(Request $request): Json
    {
        if (!$request->isPost()) {
            return $this->error('请求方式错误', null, ErrorCode::METHOD_NOT_ALLOWED);
        }

        $token = $this->bearerToken($request);
        if ($token === '') {
            return $this->error('未提供认证令牌', null, ErrorCode::UNAUTHORIZED);
        }

        $payload = $this->jwt()->tryParse($token);
        if (!is_array($payload) || ($payload['scope'] ?? '') !== 'pending_2fa') {
            return $this->error('请先使用账号密码登录', null, ErrorCode::UNAUTHORIZED);
        }

        $admin = Admin::find($this->adminIdFromPayload($payload));
        if (!$admin) {
            return $this->error('管理员不存在', null, ErrorCode::UNAUTHORIZED);
        }
        if ((int) $admin->status !== 1) {
            return $this->error('账号已被禁用', null, ErrorCode::FORBIDDEN);
        }

        if ($admin->isTotpEnabled()) {
            $code = trim((string) $request->param('code', ''));
            if ($code === '' || !app(TotpManager::class)->verify($admin, $code)) {
                return $this->error('动态验证码错误', null, ErrorCode::UNAUTHORIZED);
            }
        }

        $this->jwt()->revoke($token);

        return $this->success('登录成功', [
            'token'      => $this->issueToken($admin),
            'token_type' => 'Bearer',
            'expires_in' => $this->ttl(),
            'admin'      => $this->adminPayload($admin),
        ]);
    }

    /**
     * 续签
     *
     * POST /api/admin/refresh
     *
     * 只在「进入续签窗口」（剩余有效期 < guard.refresh_ttl）时换新 token，
     * 否则原样返回当前 token，避免客户端无限续期把 TTL 变成摆设。
     */
    public function refresh(Request $request): Json
    {
        $token = $this->bearerToken($request);
        if ($token === '') {
            return $this->error('未提供认证令牌', null, ErrorCode::UNAUTHORIZED);
        }

        $jwt     = $this->jwt();
        $payload = $jwt->tryParse($token);
        if (!is_array($payload) || ($payload['scope'] ?? '') === 'pending_2fa') {
            return $this->error('令牌无效或已过期', null, ErrorCode::UNAUTHORIZED);
        }

        $admin = Admin::find($this->adminIdFromPayload($payload));
        if (!$admin) {
            return $this->error('管理员不存在', null, ErrorCode::UNAUTHORIZED);
        }
        if ((int) $admin->status !== 1) {
            return $this->error('账号已被禁用', null, ErrorCode::FORBIDDEN);
        }

        if (!$jwt->shouldRefresh($payload)) {
            return $this->success('当前令牌无需续签', [
                'token'      => $token,
                'token_type' => 'Bearer',
                'expires_in' => $jwt->ttlOf($payload),
            ]);
        }

        $jwt->revoke($token);

        return $this->success('续签成功', [
            'token'      => $this->issueToken($admin),
            'token_type' => 'Bearer',
            'expires_in' => $this->ttl(),
        ]);
    }

    /**
     * 退出登录（吊销当前 token）
     *
     * POST /api/admin/logout
     */
    public function logout(Request $request): Json
    {
        $token = $this->bearerToken($request);
        if ($token !== '') {
            $this->jwt()->revoke($token);
        }

        return $this->success('已退出登录');
    }

    /**
     * 当前登录管理员档案（角色 + 权限标识）
     *
     * GET /api/admin/me
     */
    public function me(Request $request): Json
    {
        $admin = $request->admin ?? null;
        if (!$admin instanceof Admin) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        return $this->success('ok', $this->adminPayload($admin));
    }

    /**
     * 换取写会话令牌（敏感写操作前的二次验证）
     *
     * POST /api/admin/write-session
     * 参数：
     *   - 已启用 TOTP：totp_code（必填）
     *   - 未启用 TOTP：password（必填）
     *
     * 返回：write_token + expires_in（默认 10 分钟，见 config/admin_api.php → write_session）
     *
     * 为什么单独做一次验证：
     *   access token 有效期 1 小时，手机丢失时仅凭它就能改密码/改角色/禁用账号；
     *   写接口统一要求短时效 write_token（请求头 X-Write-Token），把窗口压到分钟级。
     *
     * 失败节流：同一管理员连续失败达上限后锁定一段时间（缓存计数，不影响 Web 登录锁定）。
     */
    public function writeSession(Request $request): Json
    {
        if (!$request->isPost()) {
            return $this->error('请求方式错误', null, ErrorCode::METHOD_NOT_ALLOWED);
        }

        $admin = $this->admin($request);
        if ($admin === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $cfg     = (array) config('admin_api.write_session', []);
        $ttl     = max(60, (int) ($cfg['ttl'] ?? 600));
        $maxFail = max(1, (int) ($cfg['max_fail'] ?? 5));
        $lockTtl = max(60, (int) ($cfg['lock_ttl'] ?? 600));

        $failKey = 'admin_api:write_fail:' . (int) $admin->id;
        $fails   = (int) Cache::get($failKey, 0);

        if ($fails >= $maxFail) {
            return $this->error(
                '二次验证失败次数过多，请稍后再试',
                ['retry_after' => $lockTtl],
                ErrorCode::RATE_LIMITED
            );
        }

        $totpCode = trim((string) $request->param('totp_code', ''));
        $password = (string) $request->param('password', '');

        $ok = false;

        if ($admin->isTotpEnabled()) {
            $ok = $totpCode !== '' && app(TotpManager::class)->verify($admin, $totpCode);
        } else {
            $ok = $password !== '' && password_verify($password, (string) $admin->getData('password'));
        }

        if (!$ok) {
            Cache::set($failKey, $fails + 1, $lockTtl);

            return $this->error(
                $admin->isTotpEnabled() ? '动态验证码错误' : '密码错误',
                ['remaining' => max(0, $maxFail - $fails - 1)],
                ErrorCode::UNAUTHORIZED
            );
        }

        Cache::delete($failKey);

        $token = bin2hex(random_bytes(32));

        Cache::set('admin_api:write:' . (int) $admin->id . ':' . sha1($token), true, $ttl);

        return $this->success('验证通过', [
            'write_token' => $token,
            'expires_in'  => $ttl,
            'scope'       => 'write',
        ]);
    }

    /**
     * 登录成功收尾：清失败计数、更新登录信息、触发事件
     */
    protected function completeLogin(Admin $admin, ?LoginLockManager $lock, string $ip): Json
    {
        if ($lock !== null) {
            $lock->clear((string) $admin->username, $ip);
        }

        $admin->last_login_time = date('Y-m-d H:i:s');
        $admin->last_login_ip   = $ip;
        $admin->save();

        Event::trigger(new AdminLogin($admin->toArray(), '登录成功', true));

        return $this->success('登录成功', [
            'token'      => $this->issueToken($admin),
            'token_type' => 'Bearer',
            'expires_in' => $this->ttl(),
            'admin'      => $this->adminPayload($admin),
        ]);
    }

    /**
     * 记录失败（失败计数 + 登录日志）
     *
     * 不复用 AdminLogin 事件：Web 侧失败事件由 LoginResultMiddleware 补齐，
     * 这里直接写 login_log，避免事件订阅者拿不到 id/username 而抛错。
     * 日志写入失败不得影响登录响应（fail-open）。
     */
    protected function recordFailure(
        ?LoginLockManager $lock,
        ?Admin $admin,
        string $username,
        string $ip,
        string $reason
    ): void {
        if ($lock !== null) {
            $lock->recordFailure($username, $ip);
        }

        try {
            LoginLog::record((int) ($admin->id ?? 0), $username, LoginLog::STATUS_FAILED, $reason);
        } catch (\Throwable) {
        }
    }
}
