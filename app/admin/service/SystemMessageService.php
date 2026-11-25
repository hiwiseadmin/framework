<?php
/**
 * SystemMessageService — 系统消息服务（基于 wise-notification 包）
 *
 * 完全使用 wise-notification 的 DatabaseChannel 作为站内信后端，
 * 消息存储在 user_notifications 表，通过 NotificationHistory 记录审计日志。
 * 保持原有公共 API 不变，确保调用方（Controller/Job/Listener）无需修改。
 *
 * 数据映射：
 *   system_messages.*  → user_notifications.data (JSON)
 *   message_receivers.* → user_notifications (单表，每用户一条记录)
 *
 * @since 2026-08-09 重构，使用 wise-notification 包
 */
namespace app\admin\service;

use app\admin\model\Admin as AdminUser;
use think\facade\Cache;
use think\facade\Db;
use wise\support\context\ContextualLogger;
use think\facade\Queue;
use wise\auth\model\UserRole;
use wise\notification\contract\Message;
use wise\notification\model\NotificationHistory;
use wise\notification\model\UserNotification;
use wise\notification\NotificationManager;

class SystemMessageService
{
    private static $instance;

    /** @var NotificationManager */
    private $manager;

    const CACHE_PREFIX = 'system_message:';

    const CACHE_TTL = 300;

    /**
     * 单例
     */
    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取 NotificationManager
     */
    private function getManager(): NotificationManager
    {
        if (!$this->manager) {
            $this->manager = app(NotificationManager::class);
        }
        return $this->manager;
    }


    /**
     * 发送消息（批量同步写入）
     *
     * @param array $messageData 消息数据 ['title','content','plugin_name','message_type','sender','sender_name','priority','action_url','action_text','expire_time']
     * @param array $receivers   接收者 [['user_id'=>1,'type'=>'direct'], ...]
     * @return bool
     */
    public function sendMessage(array $messageData, array $receivers): bool
    {
        Db::startTrans();
        try {
            $now = date('Y-m-d H:i:s');
            $records = [];

            $title   = $messageData['title'] ?? '';
            $content = $messageData['content'] ?? '';
            $extraData = $messageData;

            foreach ($receivers as $receiver) {
                $userId = (int) ($receiver['user_id'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }

                $extraData['receiver_type'] = $receiver['type'] ?? 'direct';

                $records[] = [
                    'user_id'     => $userId,
                    'title'       => $title,
                    'content'     => $content,
                    'type'        => $messageData['message_type'] ?? 'system',
                    'data'        => json_encode($extraData, JSON_UNESCAPED_UNICODE),
                    'is_read'     => 0,
                    'create_time' => $now,
                ];

                $this->clearUserCache($userId);
            }

            if (!empty($records)) {
                (new UserNotification())->insertAll($records);

                foreach ($records as $record) {
                    NotificationHistory::record([
                        'channel'          => 'database',
                        'notifiable_type'  => AdminUser::class,
                        'notifiable_id'    => (string) $record['user_id'],
                        'message_type'     => 'direct',
                        'message_payload'  => json_encode([
                            'title'   => $record['title'],
                            'content' => $record['content'],
                            'data'    => json_decode($record['data'], true),
                        ], JSON_UNESCAPED_UNICODE),
                        'status'       => 'sent',
                        'create_time'  => $now,
                    ]);
                }
            }

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            ContextualLogger::error('发送消息失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 发送消息给指定用户
     */
    public function sendToUsers(array $messageData, array $userIds): bool
    {
        $receivers = [];
        foreach ($userIds as $userId) {
            $receivers[] = [
                'user_id' => $userId,
                'type'    => 'direct',
            ];
        }
        return $this->sendMessage($messageData, $receivers);
    }

    /**
     * 发送消息给指定角色下的所有用户
     */
    public function sendToRole(array $messageData, int $roleId): bool
    {
        $userIds = UserRole::where('role_id', $roleId)
            ->column('user_id');

        if (empty($userIds)) {
            return false;
        }

        $receivers = [];
        foreach ($userIds as $userId) {
            $receivers[] = [
                'user_id' => $userId,
                'type'    => 'role',
            ];
        }

        return $this->sendMessage($messageData, $receivers);
    }

    /**
     * 发送消息给所有活跃管理员
     */
    public function sendToAll(array $messageData): bool
    {
        $userIds = AdminUser::where('status', 1)
            ->column('id');

        if (empty($userIds)) {
            return false;
        }

        $receivers = [];
        foreach ($userIds as $userId) {
            $receivers[] = [
                'user_id' => $userId,
                'type'    => 'all',
            ];
        }

        return $this->sendMessage($messageData, $receivers);
    }


    /**
     * 获取用户消息列表（分页，带缓存）
     */
    public function getUserMessages(int $userId, int $page = 1, int $limit = 10): array
    {
        $cacheKey = self::CACHE_PREFIX . "user_messages:{$userId}:{$page}:{$limit}";

        return Cache::remember($cacheKey, function () use ($userId, $page, $limit) {
            $now = date('Y-m-d H:i:s');

            $allNotifications = UserNotification::where('user_id', $userId)
                ->order('create_time', 'desc')
                ->select();

            $validNotifications = [];
            foreach ($allNotifications as $item) {
                $itemData = $this->decodeData($item);
                $expireTime = $itemData['expire_time'] ?? null;
                if ($expireTime && $expireTime < $now) {
                    continue;
                }
                $validNotifications[] = $item;
            }

            $total  = count($validNotifications);
            $offset = ($page - 1) * $limit;
            $paged  = array_slice($validNotifications, $offset, $limit);

            $messages = array_map(function ($item) {
                $itemData = $this->decodeData($item);
                return [
                    'id'      => $item->id,
                    'user_id' => $item->user_id,
                    'message' => [
                        'id'           => $item->id,
                        'plugin_name'  => $itemData['plugin_name'] ?? '',
                        'message_type' => $itemData['message_type'] ?? $item->type,
                        'title'        => $item->title,
                        'content'      => $item->content,
                        'sender'       => $itemData['sender'] ?? null,
                        'sender_name'  => $itemData['sender_name'] ?? null,
                        'priority'     => $itemData['priority'] ?? 1,
                        'action_url'   => $itemData['action_url'] ?? null,
                        'action_text'  => $itemData['action_text'] ?? null,
                        'expire_time'  => $itemData['expire_time'] ?? null,
                        'create_time'  => $item->create_time,
                    ],
                    'status'        => $item->is_read ? 2 : 1,
                    'receiver_type' => $itemData['receiver_type'] ?? 'direct',
                ];
            }, $paged);

            return [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'messages'    => $messages,
                'unread_count' => $this->getUnreadCountDirect($userId, $validNotifications),
            ];
        }, self::CACHE_TTL);
    }

    /**
     * 获取用户未读消息数量（带缓存）
     */
    public function getUnreadCount(int $userId): int
    {
        $cacheKey = self::CACHE_PREFIX . "unread_count:{$userId}";
        return Cache::remember($cacheKey, function () use ($userId) {
            $now = date('Y-m-d H:i:s');
            $all = UserNotification::where('user_id', $userId)
                ->where('is_read', 0)
                ->select();

            $count = 0;
            foreach ($all as $item) {
                $itemData = $this->decodeData($item);
                $expireTime = $itemData['expire_time'] ?? null;
                if (!$expireTime || $expireTime >= $now) {
                    $count++;
                }
            }
            return $count;
        }, 60);
    }

    /**
     * 直接从未过滤列表中计算未读数（供 getUserMessages 内部使用，避免重复查询）
     */
    private function getUnreadCountDirect(int $userId, array $notifications): int
    {
        $count = 0;
        foreach ($notifications as $item) {
            if (!$item->is_read) {
                $count++;
            }
        }
        return $count;
    }


    /**
     * 标记单条消息为已读
     */
    public function markAsRead(int $messageId, int $userId): bool
    {
        $notification = UserNotification::where('id', $messageId)
            ->where('user_id', $userId)
            ->find();

        if (!$notification) {
            return false;
        }

        if (!$notification->is_read) {
            $result = $notification->markAsRead();

            $this->clearUserCache($userId);

            return $result;
        }

        return true;
    }

    /**
     * 批量标记为已读
     */
    public function batchMarkAsRead(array $messageIds, int $userId): bool
    {
        if (empty($messageIds)) {
            return false;
        }

        Db::startTrans();
        try {
            $result = UserNotification::whereIn('id', $messageIds)
                ->where('user_id', $userId)
                ->where('is_read', 0)
                ->update([
                    'is_read'  => 1,
                    'read_at'  => date('Y-m-d H:i:s'),
                ]);

            $this->clearUserCache($userId);

            Db::commit();
            return $result > 0;
        } catch (\Exception $e) {
            Db::rollback();
            ContextualLogger::error('批量标记已读失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 标记所有消息为已读
     */
    public function markAllAsRead(int $userId): bool
    {
        Db::startTrans();
        try {
            $result = UserNotification::where('user_id', $userId)
                ->where('is_read', 0)
                ->update([
                    'is_read'  => 1,
                    'read_at'  => date('Y-m-d H:i:s'),
                ]);

            $this->clearUserCache($userId);

            Db::commit();
            return $result > 0;
        } catch (\Exception $e) {
            Db::rollback();
            ContextualLogger::error('全部标记已读失败: ' . $e->getMessage());
            throw $e;
        }
    }


    /**
     * 删除用户消息（物理删除）
     */
    public function deleteUserMessage(int $messageId, int $userId): bool
    {
        $notification = UserNotification::where('id', $messageId)
            ->where('user_id', $userId)
            ->find();

        if ($notification) {
            $result = $notification->delete();

            $this->clearUserCache($userId);

            return (bool) $result;
        }

        return false;
    }


    /**
     * 获取消息统计数据
     */
    public function getMessageStats(int $userId): array
    {
        $cacheKey = self::CACHE_PREFIX . "stats:{$userId}";

        return Cache::remember($cacheKey, function () use ($userId) {
            $today    = date('Y-m-d 00:00:00');
            $weekAgo  = date('Y-m-d 00:00:00', strtotime('-7 days'));
            $now      = date('Y-m-d H:i:s');

            $all = UserNotification::where('user_id', $userId)->select();

            $total        = 0;
            $unread       = 0;
            $todayCount   = 0;
            $weekCount    = 0;
            $highPriority = 0;

            foreach ($all as $item) {
                $itemData = $this->decodeData($item);
                $expireTime = $itemData['expire_time'] ?? null;
                $createTime = $item->create_time ?? '';

                if ($expireTime && $expireTime < $now) {
                    continue;
                }

                $total++;

                if (!$item->is_read) {
                    $unread++;

                    $priority = $itemData['priority'] ?? 1;
                    if ((int) $priority >= 3) {
                        $highPriority++;
                    }
                }

                if ($createTime >= $today) {
                    $todayCount++;
                }
                if ($createTime >= $weekAgo) {
                    $weekCount++;
                }
            }

            return [
                'total'        => $total,
                'unread'       => $unread,
                'today'        => $todayCount,
                'this_week'    => $weekCount,
                'high_priority' => $highPriority,
            ];
        }, 300);
    }


    /**
     * 清理过期消息
     */
    public function cleanExpiredMessages(): array
    {
        $result = [
            'notifications' => 0,
        ];

        Db::startTrans();
        try {
            $now = date('Y-m-d H:i:s');

            $all = UserNotification::select();
            $expiredIds = [];

            foreach ($all as $item) {
                $itemData = $this->decodeData($item);
                $expireTime = $itemData['expire_time'] ?? null;
                if ($expireTime && $expireTime < $now) {
                    $expiredIds[] = $item->id;
                }
            }

            if (!empty($expiredIds)) {
                $result['notifications'] = UserNotification::whereIn('id', $expiredIds)->delete();
            }

            Db::commit();
            return $result;
        } catch (\Exception $e) {
            Db::rollback();
            ContextualLogger::error('清理过期消息失败: ' . $e->getMessage());
            throw $e;
        }
    }


    /**
     * 创建消息（支持队列异步发送）
     *
     * 当 $useQueue=true 时，通过 NotificationManager 异步发送（走队列），
     * 每个接收者一条任务，由 wise-notification 的 SendNotificationJob 处理。
     * 当 $useQueue=false 时，直接写入 user_notifications 表。
     */
    public function createMessage(array $data, $useQueue = false): bool
    {
        if ($useQueue) {
            return $this->queueMessage($data);
        }
        return $this->saveMessage($data);
    }

    /**
     * 直接保存消息到 user_notifications（同步，不含接收者发放逻辑）
     *
     * 注：从 private 改为 public，供 MessageJob 队列任务调用。
     */
    public function saveMessage(array $data): bool
    {
        Db::startTrans();
        try {

            $title   = $data['title'] ?? '';
            $content = $data['content'] ?? '';

            $notification = new UserNotification();
            $notification->save([
                'user_id'     => $data['receiver_id'] ?? 0,
                'title'       => $title,
                'content'     => $content,
                'type'        => $data['message_type'] ?? 'system',
                'data'        => json_encode($data, JSON_UNESCAPED_UNICODE),
                'is_read'     => 0,
                'create_time' => date('Y-m-d H:i:s'),
            ]);

            if (!empty($data['receiver_id'])) {
                $this->clearUserCache((int) $data['receiver_id']);
            }

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            ContextualLogger::error('消息保存失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 队列发送消息
     *
     * 使用 wise-notification 的 NotificationManager 异步发送。
     * 每个接收者通过队列推送，由 SendNotificationJob 处理实际的 DatabaseChannel 写入。
     */
    private function queueMessage(array $data): bool
    {
        try {
            $manager = $this->getManager();

            $receiverType = $data['receiver_type'] ?? 'all';
            $userIds = [];

            if ($receiverType === 'all') {
                $userIds = AdminUser::where('status', 1)->column('id');
            } elseif (!empty($data['receiver_id'])) {
                $userIds = [$data['receiver_id']];
            }

            if (empty($userIds)) {
                ContextualLogger::warning('队列消息无有效接收者: ' . json_encode($data));
                return false;
            }

            $message = new Message(
                title: $data['title'] ?? '',
                content: $data['content'] ?? '',
                data: $data,
            );

            foreach ($userIds as $userId) {
                $user = AdminUser::find($userId);
                if ($user) {
                    $manager->channel('database')->send($user, $message);
                }
            }

            if (!empty($data['receiver_id'])) {
                $this->clearUserCache((int) $data['receiver_id']);
            }

            return true;
        } catch (\Exception $e) {
            ContextualLogger::error('消息队列失败: ' . $e->getMessage());
            return false;
        }
    }


    /**
     * 注册消息类型（兼容桥接，旧 system_message_types 表已废弃）
     *
     * 原 SystemMessageType 模型和 system_message_types 表已不再使用。
     * 消息类型现在由 wise-notification 的 notification_templates 管理。
     * 此方法保留为兼容桥接，始终返回 true。
     *
     * @deprecated 请在插件中使用 wise-notification 的 NotificationTemplate。
     */
    public function registerSystemMessageType(string $plugin, string $typeCode, array $config): bool
    {
        return true;
    }


    /**
     * 清除用户消息缓存
     */
    private function clearUserCache(int $userId): void
    {
        Cache::delete(self::CACHE_PREFIX . "unread_count:{$userId}");

        Cache::delete(self::CACHE_PREFIX . "stats:{$userId}");

        try {
            $keys = Cache::getHandler()->keys(self::CACHE_PREFIX . "user_messages:{$userId}:*");
            if (is_array($keys)) {
                foreach ($keys as $key) {
                    Cache::delete($key);
                }
            }
        } catch (\Throwable $e) {
        }
    }


    /**
     * 安全解码 UserNotification 的 data JSON 字段
     *
     * UserNotification 模型未声明 $json 属性，
     * 因此 data 字段读写均为原始字符串，需手动编解码。
     *
     * @param UserNotification $item
     * @return array
     */
    private function decodeData(UserNotification $item): array
    {
        $raw = $item->getAttr('data');
        if (empty($raw)) {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}