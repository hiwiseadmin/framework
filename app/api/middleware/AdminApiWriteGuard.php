<?php
declare(strict_types=1);

namespace app\api\middleware;

use app\admin\model\Admin;
use Closure;
use think\facade\Cache;
use think\Request;
use think\Response;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;
use wise\operationlog\model\OperationLogModel;

/**
 * 后台 App API 写操作守卫（二次验证 + 审计）
 *
 * 在「JwtAuth → AdminApiAuth → AdminApiPermission」之后执行，只作用于写接口。
 *
 * ── 为什么还要一次二次验证 ─────────────────────────────────────────────
 * App 的 access token 有效期默认 1 小时，手机丢失/被解锁时，仅凭 token 就能改密码、
 * 改角色、禁用账号 —— 破坏力远超只读接口。因此写操作要求：
 *   1. 先调 POST /api/admin/write-session 用「密码」或「TOTP」换取一次性写会话 token；
 *   2. 请求头带 `X-Write-Token`（或 body 参数 write_token）；
 *   3. 本中间件校验 token 属于当前管理员且未过期（默认 10 分钟）。
 *
 * ── 审计 ──────────────────────────────────────────────────────────────
 * 业务成功后写一条 operation_log（channel=admin-api，level=WARNING），
 * 只记录「谁 + 方法 + 路径 + HTTP 状态」，**不记录请求体**（避免密码/密钥落日志）。
 * 审计失败不影响业务结果（best-effort）。
 */
class AdminApiWriteGuard
{
    /**
     * 写会话缓存键前缀
     */
    protected const CACHE_PREFIX = 'admin_api:write:';

    public function __construct(protected ResponseWrapper $wrapper)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->admin ?? null;
        if (!$admin instanceof Admin) {
            return $this->wrapper->error('未认证', [], ErrorCode::UNAUTHORIZED);
        }

        if (!(bool) config('admin_api.write_session.enabled', true)) {
            return $next($request);
        }

        $token = $this->writeToken($request);
        if ($token === '') {
            return $this->wrapper->error(
                '写操作需要二次验证：请先调用 POST /api/admin/write-session 获取写会话令牌',
                ['require_write_token' => true],
                ErrorCode::FORBIDDEN
            );
        }

        if (!$this->verify($token, (int) $admin->id)) {
            return $this->wrapper->error(
                '写会话无效或已过期，请重新验证',
                ['require_write_token' => true, 'reason' => 'write_token_invalid'],
                ErrorCode::FORBIDDEN
            );
        }

        $response = $next($request);

        $this->audit($request, $admin, $response);

        return $response;
    }

    /**
     * 校验写会话 token
     */
    protected function verify(string $token, int $adminId): bool
    {
        $cached = Cache::get($this->cacheKey($adminId, $token));

        return $cached === true || $cached === 1 || $cached === '1';
    }

    /**
     * 缓存键（token 只存哈希，避免缓存泄露后可直接复用）
     */
    protected function cacheKey(int $adminId, string $token): string
    {
        return self::CACHE_PREFIX . $adminId . ':' . sha1($token);
    }

    /**
     * 从请求头 / 请求体取写会话 token
     */
    protected function writeToken(Request $request): string
    {
        $token = (string) $request->header('X-Write-Token', '');

        if ($token === '') {
            $token = (string) $request->param('write_token', '');
        }

        return trim($token);
    }

    /**
     * 写审计日志（best-effort）
     */
    protected function audit(Request $request, Admin $admin, Response $response): void
    {
        try {
            $status = method_exists($response, 'getCode') ? (int) $response->getCode() : 0;

            OperationLogModel::create([
                'channel'       => 'admin-api',
                'level'         => 'WARNING',
                'content'       => sprintf(
                    'App 写操作 %s %s（HTTP %d）',
                    strtoupper($request->method()),
                    '/' . ltrim($request->pathinfo(), '/'),
                    $status
                ),
                'operator_id'   => (int) $admin->id,
                'operator_name' => (string) $admin->username,
                'request_url'   => mb_substr((string) $request->url(), 0, 480),
                'ip'            => (string) $request->ip(),
                'user_agent'    => mb_substr((string) $request->header('user-agent', ''), 0, 480),
                'tags'          => 'admin,app-api,write',
                'create_time'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            if (function_exists('trace')) {
                trace('[admin-api] 写操作审计失败：' . $e->getMessage(), 'warning');
            }
        }
    }
}
