<?php
declare(strict_types=1);

namespace app\common\middleware;

use app\admin\facade\CurrentAdmin;
use think\Request;
use think\Response;
use wise\jwt\facade\Jwt;

/**
 * 附件接口登录中间件
 *
 * 适用于独立于全局 API 中间件链的附件接口（route/api.php 中
 * Route::group('api/attachment', ...)），实现「三端任一登录即可访问」：
 *
 *   1. admin   后端：CurrentAdmin::isLogin()（后台会话 Cookie，用于后台页面/编辑器上传）
 *   2. member  前台会员：Authorization: Bearer <JWT>（wise-jwt 默认 app guard，sub=会员ID）
 *   3. api     外部 API 端：与会员同构的 Bearer JWT（小程序/APP 登录签发的访问令牌）
 *
 * 三者任一通过即放行，并向 $request 注入：
 *   - attachment_actor = ['type' => 'admin'|'member'|'api', 'id' => 用户ID]
 *   - member（type=member/api 时）——兼容既有控制器 $request->member 读取习惯
 *
 * 全部未通过统一返回 401 JSON。download 等公开动作可通过 $except 名单放行
 * （默认仅 download，便于富文本回显 / <img> 直接引用）。
 */
class AttachmentLogin
{
    /**
     * 免登录动作白名单（小写动作名）
     *
     * @var string[]
     */
    protected array $except = ['download'];

    /**
     * 动作执行：鉴权通过才继续，否则返回统一 401。
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $action = strtolower((string) $request->action());
        if (in_array($action, $this->except, true)) {
            return $next($request);
        }

        $actor = $this->authenticate($request);
        if ($actor === null) {
            return json([
                'code' => 401,
                'msg'  => '请先登录后再操作附件',
                'data' => null,
            ], 401);
        }

        $request->attachment_actor = $actor;
        if (in_array($actor['type'], ['member', 'api'], true) && !empty($actor['member'])) {
            $request->member = $actor['member'];
        }

        return $next($request);
    }

    /**
     * 依次尝试 admin / member / api 三端登录态。
     *
     * @return array{type:string,id:int|string|null,member?:object}|null
     */
    protected function authenticate(Request $request): ?array
    {
        // 1. 后端 admin（后台会话）
        try {
            if (CurrentAdmin::isLogin()) {
                $id = (int) CurrentAdmin::getAdminId();
                return ['type' => 'admin', 'id' => $id > 0 ? $id : null];
            }
        } catch (\Throwable $e) {
            // session/后台组件缺失时忽略，继续尝试下一端
        }

        // 2/3. 前台会员 / API 端（Bearer JWT，wise-jwt 默认 guard）
        $token = $this->extractToken($request);
        if ($token !== '') {
            $member = $this->resolveMember($token);
            if ($member !== null) {
                return ['type' => 'member', 'id' => (int) $member->id, 'member' => $member];
            }
        }

        return null;
    }

    /**
     * 解析 Bearer JWT 并校验为有效会员。
     *
     * 会员模型来自 `wise-auth.user_models.member`（配置驱动），本中间件**不引用任何
     * 具体包的类** —— wise-member 未安装时模型类不存在，直接视为「该端不可用」，
     * 落到统一 401；而不是实例化缺失类导致致命错误。
     */
    protected function resolveMember(string $token): ?object
    {
        $model = $this->memberModelClass();
        if ($model === null) {
            return null;
        }

        try {
            $payload = Jwt::tryParse($token);
        } catch (\Throwable $e) {
            return null;
        }

        if (empty($payload) || empty($payload['sub'])) {
            return null;
        }

        $member = $model::find((int) $payload['sub']);
        if (!$member) {
            return null;
        }

        return $this->isMemberActive($member) ? $member : null;
    }

    /**
     * 会员模型类（FQCN）
     *
     * 取 `wise-auth.user_models.member`；未配置或类不存在（提供该模型的包未安装）
     * 时返回 null，表示会员端不可用。
     */
    protected function memberModelClass(): ?string
    {
        $class = (string) config('wise-auth.user_models.member', '');

        return ($class !== '' && class_exists($class)) ? $class : null;
    }

    /**
     * 会员是否处于可用登录态
     *
     * 优先让模型自述（`isActive()`）；模型未提供时按
     * `wise-auth.member_status_field` / `member_active_status` 判定
     * （默认等价于历史实现：`status === 1`）。
     */
    protected function isMemberActive(object $member): bool
    {
        if (method_exists($member, 'isActive')) {
            try {
                return (bool) $member->isActive();
            } catch (\Throwable $e) {
                // 模型自述异常时回退到字段判定
            }
        }

        $field = (string) config('wise-auth.member_status_field', 'status');
        $value = (int) config('wise-auth.member_active_status', 1);

        if ($field === '' || !method_exists($member, 'getAttr')) {
            return true;
        }

        return (int) $member->getAttr($field) === $value;
    }

    /**
     * 从请求提取 Bearer token（头优先，兼容 query 参数）。
     */
    protected function extractToken(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        $queryToken = (string) $request->param('token', '');
        return trim($queryToken);
    }
}
