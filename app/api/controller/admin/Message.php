<?php
declare(strict_types=1);

namespace app\api\controller\admin;

use app\admin\service\SystemMessageService;
use think\Request;
use think\response\Json;
use wise\api\response\ErrorCode;

/**
 * 站内消息（后台 App API）
 *
 * GET    /api/admin/messages          消息列表（分页）
 * GET    /api/admin/messages/stats    未读统计
 * POST   /api/admin/messages/read     标记已读（单条 / 批量）
 * POST   /api/admin/messages/read-all 全部已读
 * DELETE /api/admin/messages/{id}     删除（仅本人消息）
 *
 * ⚠️ 权限：本组接口**不挂 AdminApiPermission**，只验身份（路由 A 组）。
 *    理由与 Web 侧一致：`config/wise-auth.php` 的 authenticated_routes 里
 *    `/admin/system_message/*` 只要求登录 —— 这些接口返回的是「当前登录人自己的消息」，
 *    不存在「A 角色能看 B 角色消息」的授权语义，用业务权限节点反而制造假边界。
 *    越权由 service 层按 `$userId` 过滤保证（任何操作都带当前 admin_id 条件）。
 */
class Message extends Base
{
    /**
     * 单页上限
     */
    protected const MAX_LIMIT = 50;

    /**
     * 消息列表
     */
    public function index(Request $request): Json
    {
        $admin = $this->admin($request);
        if ($admin === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $page  = max(1, (int) $request->param('page', 1));
        $limit = (int) $request->param('limit', 10);
        $limit = $limit < 1 ? 10 : min(self::MAX_LIMIT, $limit);

        return $this->success('ok', SystemMessageService::instance()->getUserMessages(
            (int) $admin->id,
            $page,
            $limit
        ));
    }

    /**
     * 未读统计（App 角标）
     */
    public function stats(Request $request): Json
    {
        $admin = $this->admin($request);
        if ($admin === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        return $this->success('ok', SystemMessageService::instance()->getMessageStats((int) $admin->id));
    }

    /**
     * 标记已读
     *
     * 参数：
     *   message_id=1                  单条
     *   message_ids[]=1&message_ids[]=2  批量
     */
    public function read(Request $request): Json
    {
        $admin = $this->admin($request);
        if ($admin === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $ids    = $this->resolveIds($request);
        $userId = (int) $admin->id;
        $service = SystemMessageService::instance();

        if ($ids === []) {
            return $this->error('请选择消息');
        }

        if (count($ids) === 1) {
            $ok = $service->markAsRead($ids[0], $userId);

            return $ok
                ? $this->success('标记成功')
                : $this->error('消息不存在或已标记');
        }

        // 注意：service 返回 bool（无「部分成功」语义），不是条数
        return $service->batchMarkAsRead($ids, $userId)
            ? $this->success('批量标记成功')
            : $this->error('没有可标记的消息');
    }

    /**
     * 全部标记已读
     */
    public function readAll(Request $request): Json
    {
        $admin = $this->admin($request);
        if ($admin === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        return SystemMessageService::instance()->markAllAsRead((int) $admin->id)
            ? $this->success('全部标记为已读')
            : $this->error('操作失败');
    }

    /**
     * 删除消息（仅本人的）
     */
    public function destroy(Request $request, int $id = 0): Json
    {
        $admin = $this->admin($request);
        if ($admin === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $id = $id > 0 ? $id : (int) $request->param('id', 0);
        if ($id <= 0) {
            return $this->error('缺少消息 ID');
        }

        return SystemMessageService::instance()->deleteUserMessage($id, (int) $admin->id)
            ? $this->success('删除成功')
            : $this->error('消息不存在或无权删除');
    }

    /**
     * 解析消息 ID（兼容 message_id / message_ids / id / ids）
     *
     * @return array<int, int>
     */
    protected function resolveIds(Request $request): array
    {
        $ids = $request->param('message_ids/a', []);

        if (!is_array($ids) || $ids === []) {
            $ids = $request->param('ids/a', []);
        }

        if (!is_array($ids) || $ids === []) {
            $single = (int) $request->param('message_id', 0);
            if ($single <= 0) {
                $single = (int) $request->param('id', 0);
            }
            $ids = $single > 0 ? [$single] : [];
        }

        return array_values(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0));
    }
}
