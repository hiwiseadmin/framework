<?php
declare(strict_types=1);

namespace app\api\middleware;

use app\admin\model\Admin;
use Closure;
use think\Request;
use think\Response;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;
use wise\auth\service\RbacManager;
use wise\support\context\RequestContext;

/**
 * 后台 App API 认证中间件（JWT → Admin）
 *
 * 必须在 wise\jwt\middleware\JwtAuth（guard=admin, bearer）之后执行：
 * JwtAuth 负责验签 / 过期 / 黑名单，本中间件负责「业务身份」与状态校验。
 *
 * 校验链：
 *   1. jwt_payload 存在（否则 401）
 *   2. scope != pending_2fa（pending token 只能用于 /api/admin/2fa）
 *   3. sub 形如 admin:{id}（拒绝会员数字 sub，双保险）
 *   4. 管理员存在且 status=1
 *   5. 权限版本一致（角色变更后旧 token 立即失效，与后台 Session 链路同语义）
 *
 * 通过后注入：
 *   - $request->admin         Admin 模型
 *   - $request->auth_user_id  供 wise-auth 中间件 / 审计链路识别身份
 *   - $request->_rbac_trusted 配合 wise-auth.middleware_trust_auth_user_id
 *   - RequestContext 用户上下文（Swoole 协程隔离由 RequestContext 保证）
 */
class AdminApiAuth
{
    public function __construct(protected ResponseWrapper $wrapper)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $payload = $request->jwt_payload ?? null;
        if (!is_array($payload)) {
            return $this->deny('未提供有效的认证令牌');
        }

        if (($payload['scope'] ?? '') === 'pending_2fa') {
            return $this->deny('请先完成双因素认证');
        }

        $adminId = $this->adminId($payload);
        if ($adminId <= 0) {
            return $this->deny('令牌主体非法');
        }

        $admin = Admin::find($adminId);
        if (!$admin) {
            return $this->deny('管理员不存在');
        }
        if ((int) $admin->status !== 1) {
            return $this->deny('账号已被禁用', ErrorCode::FORBIDDEN);
        }

        $version = app(RbacManager::class)->getUserPermissionVersion($adminId, 'admin');
        if ($version > 0 && (int) ($payload['ver'] ?? 0) !== $version) {
            return $this->deny('权限已变更，请重新登录');
        }

        $request->admin         = $admin;
        $request->auth_user_id  = $adminId;
        $request->_rbac_trusted = true;

        RequestContext::setUser($admin);
        RequestContext::setGuard('admin');

        return $next($request);
    }

    /**
     * 由 payload 还原管理员 ID（sub = admin:{id}）
     */
    protected function adminId(array $payload): int
    {
        $sub    = (string) ($payload['sub'] ?? '');
        $prefix = (string) config('admin_api.subject_prefix', 'admin:');

        if ($prefix === '' || !str_starts_with($sub, $prefix)) {
            return 0;
        }

        return (int) substr($sub, strlen($prefix));
    }

    /**
     * 统一 401/403 响应
     */
    protected function deny(string $message, ErrorCode $code = ErrorCode::UNAUTHORIZED): Response
    {
        return $this->wrapper->error($message, [], $code);
    }
}
