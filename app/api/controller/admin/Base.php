<?php
declare(strict_types=1);

namespace app\api\controller\admin;

use app\admin\model\Admin;
use think\Request;
use wise\api\ApiController;
use wise\auth\service\RbacManager;
use wise\jwt\Jwt;
use wise\jwt\JwtGuardManager;

/**
 * 后台 App API 控制器基类
 *
 * 负责三件事，业务控制器只管取数据：
 *   1. 按 admin guard 取 JWT 引擎（独立密钥 / issuer / 黑名单）
 *   2. 签发 / 提取 token
 *   3. 输出管理员档案（角色 + 权限标识，供 App 做界面级控制）
 */
abstract class Base extends ApiController
{
    /**
     * 当前 guard 的 JWT 引擎
     */
    protected function jwt(): Jwt
    {
        return app(JwtGuardManager::class)->guard($this->guardName());
    }

    /**
     * guard 名（默认 admin，见 config/admin_api.php）
     */
    protected function guardName(): string
    {
        return (string) config('admin_api.guard', 'admin');
    }

    /**
     * 正式 token 有效期
     */
    protected function ttl(): int
    {
        return (int) config('admin_api.token_ttl', 3600);
    }

    /**
     * sub 前缀
     */
    protected function subjectPrefix(): string
    {
        return (string) config('admin_api.subject_prefix', 'admin:');
    }

    /**
     * 从 Authorization: Bearer 提取 token
     *
     * 优先读请求头（未走 JwtAuth 中间件的接口也能用），
     * 其次复用 JwtAuth 已解析的 jwt_token。
     */
    protected function bearerToken(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        return (string) ($request->jwt_token ?? '');
    }

    /**
     * 由 JWT payload 还原管理员 ID（sub = admin:{id}）
     *
     * @param array $payload
     * @return int 非法返回 0
     */
    protected function adminIdFromPayload(array $payload): int
    {
        $sub    = (string) ($payload['sub'] ?? '');
        $prefix = $this->subjectPrefix();

        if ($prefix !== '' && !str_starts_with($sub, $prefix)) {
            return 0;
        }

        $id = (int) substr($sub, strlen($prefix));

        return $id > 0 ? $id : 0;
    }

    /**
     * 签发 token
     *
     * @param Admin  $admin
     * @param array  $extraClaims 额外声明（如 scope=pending_2fa）
     * @param int|null $ttl
     * @return string
     */
    protected function issueToken(Admin $admin, array $extraClaims = [], ?int $ttl = null): string
    {
        $claims = array_merge([
            'user_type' => 'admin',
            'user_id'   => (int) $admin->id,
            'ver'       => app(RbacManager::class)->getUserPermissionVersion($admin->id, 'admin'),
        ], $extraClaims);

        return $this->jwt()->issue(
            $this->subjectPrefix() . $admin->id,
            $claims,
            $ttl ?? $this->ttl()
        );
    }

    /**
     * 当前登录管理员（AdminApiAuth 注入）
     *
     * 统一入口，避免各控制器重复写 `$request->admin ?? null` + instanceof 判断。
     * 返回 null 表示未认证（理论上走不到：A/B 组都挂了 AdminApiAuth）。
     */
    protected function admin(Request $request): ?Admin
    {
        $admin = $request->admin ?? null;

        return $admin instanceof Admin ? $admin : null;
    }

    /**
     * 管理员档案（含角色与权限标识）
     *
     * @param Admin $admin
     * @return array
     */
    protected function adminPayload(Admin $admin): array
    {
        $rbac = app(RbacManager::class);
        $id   = (int) $admin->id;

        $roles = [];
        foreach ($admin->getRoles() as $role) {
            $roles[] = [
                'id'   => (int) $role->id,
                'name' => (string) $role->name,
                'slug' => (string) $role->slug,
            ];
        }

        return [
            'id'          => $id,
            'username'    => (string) $admin->username,
            'nickname'    => (string) $admin->nickname,
            'email'       => (string) $admin->email,
            'avatar'      => (string) $admin->avatar,
            'is_super'    => $rbac->isSuperAdmin($id, 'admin'),
            'roles'       => $roles,
            'permissions' => $rbac->getUserPermissions($id, 'admin'),
        ];
    }
}
