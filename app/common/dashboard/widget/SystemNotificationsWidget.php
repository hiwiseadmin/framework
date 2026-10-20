<?php
declare(strict_types=1);

namespace app\common\dashboard\widget;

use app\admin\facade\CurrentAdmin;
use plugin\contract\DashboardWidgetInterface;
use app\admin\service\SystemMessageService;

/**
 * 内置：站内消息面板（category=list）
 *
 * 数据源：SystemMessageService（wise-notification 站内信）。服务不可用时静默返回空列表。
 *
 * @package app\common\dashboard\widget
 */
class SystemNotificationsWidget implements DashboardWidgetInterface
{
    public function getKey(): string
    {
        return 'system.notifications';
    }

    public function getTitle(): string
    {
        return '站内消息';
    }

    public function getDescription(): string
    {
        return '当前管理员最近站内消息与未读数';
    }

    public function getIcon(): array
    {
        return ['type' => 'bi', 'value' => 'bi bi-bell', 'color' => ''];
    }

    public function getCategory(): string
    {
        return 'list';
    }

    public function getDefaultSize(): array
    {
        return ['w' => 4, 'h' => 3];
    }

    public function getMinSize(): array
    {
        return ['w' => 2, 'h' => 1];
    }

    public function getSuggestedAccent(): string
    {
        return 'success';
    }

    public function getDefaultConfig(): array
    {
        return ['limit' => 5];
    }

    public function getConfigSchema(): array
    {
        return [
            'limit' => [
                'type'    => 'number',
                'title'   => '显示条数',
                'default' => 5,
                'options' => [],
            ],
        ];
    }

    public function getData(array $config = []): array
    {
        $adminId = 0;
        try {
            $adminId = (int) CurrentAdmin::getAdminId();
        } catch (\Throwable $e) {
            // 未登录上下文，直接空数据
        }
        if ($adminId <= 0) {
            return ['items' => [], 'unread' => 0];
        }

        $limit = max(1, min(20, (int) ($config['limit'] ?? 5)));
        try {
            $result = SystemMessageService::instance()->getUserMessages($adminId, 1, $limit);
            $items  = [];
            foreach (($result['messages'] ?? []) as $row) {
                $msg = $row['message'] ?? [];
                $items[] = [
                    'id'        => $row['id'] ?? 0,
                    'title'     => $msg['title'] ?? '',
                    'content'   => $msg['content'] ?? '',
                    'type'      => $msg['message_type'] ?? 'system',
                    'priority'  => $msg['priority'] ?? 1,
                    'read'      => ((int) ($row['status'] ?? 1)) === 2,
                    'actionUrl' => $msg['action_url'] ?? '',
                    'createAt'  => $msg['create_time'] ?? '',
                ];
            }
            return [
                'items'  => $items,
                'unread' => (int) ($result['unread_count'] ?? 0),
            ];
        } catch (\Throwable $e) {
            // 通知组件/表不可用时静默降级
            return ['items' => [], 'unread' => 0];
        }
    }

    public function getCacheKey(array $config = []): string
    {
        return '';
    }

    public function getCacheTTL(): int
    {
        return 0;
    }

    public function isAccessible(): bool
    {
        return true;
    }
}
