<?php
namespace app\admin\service;

use app\admin\model\Admin;
use app\admin\model\AdminRememberToken;
use think\facade\Cookie;
use think\facade\Log;

/**
 * 后台「保持登录」服务
 *
 * 协议：Cookie 值 = selector:validator
 *  - selector  ：16 字节随机数的 hex（32 字符），入库用于检索；
 *  - validator ：32 字节随机数的 hex（64 字符），只把 SHA-256 哈希入库。
 *
 * 因此数据库泄露不会直接得到可用的 Cookie；selector 命中但 validator
 * 不匹配时视为令牌被篡改/窃用，直接撤销该条记录。
 */
class RememberLoginService
{
    /**
     * Cookie 名称（未配置时使用）
     */
    public const COOKIE_NAME = 'admin_remember';

    /**
     * 是否启用保持登录
     */
    public function isEnabled(): bool
    {
        return (bool) config('remember_login.enabled', true);
    }

    /**
     * 免登录有效期（秒）
     */
    public function getTtl(): int
    {
        return max(60, (int) config('remember_login.ttl', 7 * 24 * 60 * 60));
    }

    /**
     * 登录成功后签发持久化令牌并写入 Cookie
     *
     * 失败不阻断登录主流程（仅记录日志）。
     */
    public function issue(Admin $admin): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $selector  = bin2hex(random_bytes(16));
            $validator = bin2hex(random_bytes(32));

            AdminRememberToken::create([
                'admin_id'        => (int) $admin->id,
                'selector'        => $selector,
                'validator_hash'  => hash('sha256', $validator),
                'credential_hash' => $this->credentialFingerprint($admin),
                'expires_at'      => date('Y-m-d H:i:s', time() + $this->getTtl()),
                'login_ip'        => (string) request()->ip(),
                'user_agent'      => substr((string) request()->header('user-agent', ''), 0, 255),
            ]);

            $this->collectGarbage();
            $this->enforceLimit((int) $admin->id);

            $this->setCookie($selector . ':' . $validator);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[保持登录] 令牌签发失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 尝试用持久化 Cookie 恢复登录态
     *
     * @return bool 是否成功恢复
     */
    public function restore(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $raw = Cookie::get($this->cookieName());
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        $parts = explode(':', $raw);
        if (count($parts) !== 2 || !$this->isValidSegment($parts[0], 32) || !$this->isValidSegment($parts[1], 64)) {
            $this->clearCookie();
            return false;
        }

        [$selector, $validator] = $parts;

        $token = AdminRememberToken::where('selector', $selector)->find();
        if (!$token) {
            $this->clearCookie();
            return false;
        }

        if (strtotime((string) $token['expires_at']) <= time()) {
            $token->delete();
            $this->clearCookie();
            return false;
        }

        if (!hash_equals((string) $token['validator_hash'], hash('sha256', $validator))) {
            $token->delete();
            $this->clearCookie();
            return false;
        }

        $admin = Admin::find((int) $token['admin_id']);
        if (!$admin || (int) $admin['status'] !== 1) {
            AdminRememberToken::where('admin_id', (int) $token['admin_id'])->delete();
            $this->clearCookie();
            return false;
        }

        if (!hash_equals((string) $token['credential_hash'], $this->credentialFingerprint($admin))) {
            AdminRememberToken::where('admin_id', (int) $token['admin_id'])->delete();
            $this->clearCookie();
            return false;
        }

        app(AdminService::class)->login($admin);

        $this->renewIfNeeded($token, $raw);

        return true;
    }

    /**
     * 撤销当前浏览器对应的令牌（退出登录）
     */
    public function revokeCurrent(): void
    {
        $raw = Cookie::get($this->cookieName());
        if (is_string($raw) && $raw !== '') {
            $parts = explode(':', $raw);
            if (count($parts) === 2 && $parts[0] !== '') {
                AdminRememberToken::where('selector', $parts[0])->delete();
            }
        }

        $this->clearCookie();
    }

    /**
     * 撤销某管理员的全部令牌（如强制下线 / 修改密码）
     */
    public function revokeAll(int $adminId): void
    {
        if ($adminId > 0) {
            AdminRememberToken::where('admin_id', $adminId)->delete();
        }
    }


    private function credentialFingerprint(Admin $admin): string
    {
        return hash('sha256', (string) $admin->getData('password'));
    }

    private function isValidSegment(string $segment, int $length): bool
    {
        return strlen($segment) === $length && preg_match('/^[a-f0-9]+$/', $segment) === 1;
    }

    private function renewIfNeeded(AdminRememberToken $token, string $raw): void
    {
        $remaining = strtotime((string) $token['expires_at']) - time();
        if ($remaining > (int) floor($this->getTtl() / 2)) {
            return;
        }

        try {
            $token->save([
                'expires_at'     => date('Y-m-d H:i:s', time() + $this->getTtl()),
                'last_used_time' => date('Y-m-d H:i:s'),
            ]);
            $this->setCookie($raw);
        } catch (\Throwable $e) {
            Log::warning('[保持登录] 令牌续期失败: ' . $e->getMessage());
        }
    }

    private function collectGarbage(): void
    {
        try {
            AdminRememberToken::where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
        } catch (\Throwable $e) {
        }
    }

    private function enforceLimit(int $adminId): void
    {
        $max = max(1, (int) config('remember_login.max_tokens', 5));
        $ids = AdminRememberToken::where('admin_id', $adminId)->order('id', 'desc')->column('id');

        if (count($ids) > $max) {
            AdminRememberToken::whereIn('id', array_slice($ids, $max))->delete();
        }
    }

    private function cookieName(): string
    {
        return (string) config('remember_login.cookie', self::COOKIE_NAME);
    }

    private function cookieOptions(): array
    {
        return [
            'expire'   => $this->getTtl(),
            'path'     => (string) config('remember_login.path', '/'),
            'domain'   => (string) config('remember_login.domain', ''),
            'secure'   => (bool) config('cookie.secure', false),
            'httponly' => true,
            'samesite' => (string) config('cookie.samesite', 'lax'),
        ];
    }

    private function setCookie(string $value): void
    {
        Cookie::set($this->cookieName(), $value, $this->cookieOptions());
    }

    private function clearCookie(): void
    {
        Cookie::delete($this->cookieName(), [
            'path'   => (string) config('remember_login.path', '/'),
            'domain' => (string) config('remember_login.domain', ''),
        ]);
    }
}
