<?php
namespace plugin\wisecms\service\content;

use plugin\wisecms\service\url\UrlService;

/**
 * CMS 草稿预览令牌服务（B-3，零新表）
 *
 * 设计要点（详见 docs/wisecms-preview-design.md）：
 *   · 无状态 HMAC-SHA256 令牌，等价 Laravel temporarySignedRoute，零依赖零新表；
 *   · 令牌结构 `payload . '.' . sig`，payload = base64url(json{id,site,uid,exp})，
 *     sig = substr(hash_hmac('sha256', payload, secret), 0, 32)；
 *   · 三重绑定：内容 ID + 站点 + 过期时间，任一不符即拒绝；
 *   · `hash_equals` 常量时间比较，防时序侧信道与篡改；
 *   · fail-closed：密钥为空时 `enable()` 返回 false，预览端点与签发一律不可用。
 *
 * 纯函数（encode/decode/sign/ttl/b64url*）不依赖框架与数据库，便于零依赖自检。
 */
class PreviewService
{
    /** 前台预览入口路径（与 route/index.php 的 cms/preview/index 一致） */
    const ROUTE = 'cms/preview/index';

    /** 默认令牌有效期（秒） */
    const DEFAULT_TTL = 1800;

    /** 有效期下限（秒） */
    const TTL_MIN = 60;

    /** 有效期上限（秒，24 小时） */
    const TTL_MAX = 86400;

    // ══════════════════════════ 对外 API ══════════════════════════

    /**
     * 签发预览令牌
     *
     * @param int      $contentId 内容 ID
     * @param int      $siteId    站点 ID（令牌绑定站点）
     * @param int|null $ttl       有效期秒数（null 取 preview_ttl，内部收敛到 60..86400）
     * @param int      $userId    签发用户 ID（preview_bind_user=1 时校验）
     * @return array{token:string,url:string,expires_at:int}
     *
     * @throws \RuntimeException 预览功能未开启 / 未配置密钥
     */
    public function generate(int $contentId, int $siteId, ?int $ttl = null, int $userId = 0): array
    {
        if (!$this->enable()) {
            throw new \RuntimeException('预览功能未开启或未配置密钥');
        }

        $expiresAt = time() + self::ttl($ttl);
        $payload   = [
            'id'   => $contentId,
            'site' => $siteId,
            'uid'  => max(0, $userId),
            'exp'  => $expiresAt,
            // P1-A7：令牌唯一标识，供 preview_once（一次性消费）与日志追踪使用
            'jti'  => bin2hex(random_bytes(8)),
        ];

        $token = self::encode($payload, $this->secret());
        $query = http_build_query(['id' => $contentId, 'token' => $token]);

        return [
            'token'      => $token,
            'url'        => $this->absolute(self::ROUTE . '?' . $query),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * 校验预览令牌
     *
     * 失败一律返回 null（不区分原因，避免成为伪造探测点）。
     *
     * @param string $token     令牌
     * @param int    $contentId 期望的内容 ID
     * @param int    $siteId    期望的站点 ID
     * @return array|null 通过时返回 payload
     */
    public function verify(string $token, int $contentId, int $siteId): ?array
    {
        if (!$this->enable() || $token === '') {
            return null;
        }

        $payload = self::decode($token, $this->secret());
        if ($payload === null) {
            return null;
        }

        // 三重绑定：内容 ID + 站点 + 过期
        if ((int) ($payload['id'] ?? 0) !== $contentId) {
            return null;
        }
        if ((int) ($payload['site'] ?? 0) !== $siteId) {
            return null;
        }
        if ((int) ($payload['exp'] ?? 0) < time()) {
            return null;
        }

        // 绑定签发用户（P1-A7：默认开启）
        //
        // 关闭后仅校验 id+site+exp，链接一旦泄露，TTL（最长 24h）内任何人可看他人草稿。
        // 默认开启时令牌仅在「签发它的那个后台账号」会话中可打开。
        if ((bool) $this->getConfig('preview_bind_user', true)) {
            $uid     = (int) ($payload['uid'] ?? 0);
            $current = $this->currentUserId();
            if ($uid <= 0 || $current <= 0 || $uid !== $current) {
                return null;
            }
        }

        // 一次性令牌（可选，preview_once=1）
        // ⚠️ 开启后「同一链接刷新页面也会失效」，仅在需要严格防外发的场景启用。
        if ((bool) $this->getConfig('preview_once', false)) {
            $jti = (string) ($payload['jti'] ?? '');
            if ($jti === '' || !$this->consumeOnce($jti, (int) ($payload['exp'] ?? 0))) {
                return null;
            }
        }

        return $payload;
    }

    /**
     * 一次性令牌消费（P1-A7）
     *
     * 首次返回 true，重复消费返回 false；缓存不可用（如脱离框架的零依赖自检）时
     * 降级放行，避免预览功能整体不可用。
     *
     * 注：get + set 之间存在极小的并发窗口，同一令牌被两个并发请求同时放行属可接受
     * 概率事件；如需强一致需改用原子 setnx（依赖具体缓存驱动）。
     */
    protected function consumeOnce(string $jti, int $expiresAt): bool
    {
        $key = 'wisecms:preview:used:' . $jti;
        $ttl = max(60, $expiresAt - time());

        try {
            if (\think\facade\Cache::get($key)) {
                return false;
            }
            \think\facade\Cache::set($key, 1, $ttl);

            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * 预览功能是否可用（总开关 + 密钥可用性）
     *
     * 密钥为空时返回 false（fail-closed），避免「无密钥签名」退化为公开可伪造。
     */
    public function enable(): bool
    {
        if (!(bool) $this->getConfig('preview_enable', true)) {
            return false;
        }

        return $this->secret() !== '';
    }

    // ══════════════════════════ 纯函数 ══════════════════════════

    /**
     * 编码：base64url(payload) . '.' . sign(payload)
     */
    public static function encode(array $payload, string $secret): string
    {
        $body = self::b64urlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $body . '.' . self::sign($body, $secret);
    }

    /**
     * 解码：拆分包体与签名，常量时间校验后还原 payload；任何一步失败返回 null
     */
    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$body, $sig] = $parts;
        if ($body === '' || $sig === '') {
            return null;
        }

        // 常量时间比较，防时序侧信道；同时防篡改
        if (!hash_equals(self::sign($body, $secret), $sig)) {
            return null;
        }

        $json = self::b64urlDecode($body);
        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * 签名：sha256 HMAC 取前 32 位十六进制（128 bit）
     */
    public static function sign(string $payload, string $secret): string
    {
        return substr(hash_hmac('sha256', $payload, $secret), 0, 32);
    }

    /**
     * 有效期收敛到 60..86400（null 取 preview_ttl）
     */
    public static function ttl(?int $ttl = null): int
    {
        if ($ttl === null) {
            $ttl = (int) config('wisecms.preview_ttl', self::DEFAULT_TTL);
        }

        return max(self::TTL_MIN, min(self::TTL_MAX, $ttl));
    }

    /**
     * URL 安全 base64 编码（+/ → -_，去尾部 =）
     */
    public static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL 安全 base64 解码（失败返回 null）
     */
    public static function b64urlDecode(string $data): ?string
    {
        if ($data === '') {
            return null;
        }

        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($data, true);

        return $decoded === false ? null : $decoded;
    }

    // ══════════════════════════ 内部工具 ══════════════════════════

    /**
     * 读取预览配置（config('wisecms.preview_*')）
     */
    protected function getConfig(string $key, $default = null)
    {
        return config('wisecms.' . $key, $default);
    }

    /**
     * 签名密钥：preview_secret 优先，留空回退 env('APP_KEY')
     *
     * 两者皆空 → 返回空串，enable() 返回 false（fail-closed）。
     * 禁止硬编码默认密钥（会退化为「公开可伪造」）。
     */
    protected function secret(): string
    {
        $secret = trim((string) $this->getConfig('preview_secret', ''));
        if ($secret === '' && function_exists('env')) {
            $secret = trim((string) env('APP_KEY', ''));
        }

        return $secret;
    }

    /**
     * 当前登录用户 ID（与后台 session 约定一致；非后台/未登录返回 0）
     */
    protected function currentUserId(): int
    {
        try {
            if (function_exists('session')) {
                $id = (int) session('admin_id');
                if ($id > 0) {
                    return $id;
                }
            }
        } catch (\Throwable $e) {
            // 忽略：取不到登录态时按未登录处理
        }

        return 0;
    }

    /**
     * 预览 URL 绝对化（统一委托 UrlService，保持站点域名/规则一致）
     *
     * 脱离框架（无 request()）时降级为根相对路径，保证零依赖自检可用。
     */
    protected function absolute(string $path): string
    {
        try {
            return (new UrlService())->absolute($path);
        } catch (\Throwable $e) {
            return '/' . ltrim($path, '/');
        }
    }
}
