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

    // 缓存键前缀
    const CACHE_PREFIX = 'system_message:';

    // 缓存时间（5分钟）
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

    // ==================== 消息发送 ====================

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

            // 提取消息核心字段，其余放入 data JSON
            $title   = $messageData['title'] ?? '';
            $content = $messageData['content'] ?? '';
            $extraData = $messageData; // 完整数据存入 data

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

                // 清除用户缓存
                $this->clearUserCache($userId);
            }

            if (!empty($records)) {
                // 批量插入 UserNotification
                (new UserNotification())->insertAll($records);

                // 记录审计日志到 notification_histories
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

    // ==================== 消息查询 ====================

    /**
     * 获取用户消息列表（分页，带缓存）
     */
    public function getUserMessages(int $userId, int $page = 1, int $limit = 10): array
    {
        $cacheKey = self::CACHE_PREFIX . "user_messages:{$userId}:{$page}:{$limit}";

        return Cache::remember($cacheKey, function () use ($userId, $page, $limit) {
            // 查询未过期消息（data JSON 中 expire_time 为空或未过期）
            $now = date('Y-m-d H:i:s');

            // 先查出所有属于该用户的通知（排除已过期）
            $allNotifications = UserNotification::where('user_id', $userId)
                ->order('create_time', 'desc')
                ->select();

            // PHP 层面过滤过期消息（expire_time 存储在 data JSON 中）
            $validNotifications = [];
            foreach ($allNotifications as $item) {
                $itemData = $this->decodeData($item);
                $expireTime = $itemData['expire_time'] ?? null;
                if ($expireTime && $expireTime < $now) {
                    continue; // 已过期，跳过
                }
                $validNotifications[] = $item;
            }

            $total  = count($validNotifications);
            $offset = ($page - 1) * $limit;
            $paged  = array_slice($validNotifications, $offset, $limit);

            // 转换为旧格式（兼容前端）
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
                    'status'        => $item->is_read ? 2 : 1, // 旧系统: 1=未读 2=已读
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

    // ==================== 已读管理 ====================

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

            // 清除缓存
            $this->clearUserCache($userId);

            return $result;
        }

        return true; // 已读，无需操作
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

            // 清除缓存
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

            // 清除缓存
            $this->clearUserCache($userId);

            Db::commit();
            return $result > 0;
        } catch (\Exception $e) {
            Db::rollback();
            ContextualLogger::error('全部标记已读失败: ' . $e->getMessage());
            throw $e;
        }
    }

    // ==================== 删除 ====================

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

            // 清除缓存
            $this->clearUserCache($userId);

            return (bool) $result;
        }

        return false;
    }

    // ==================== 统计 ====================

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

                // 过滤过期
                if ($expireTime && $expireTime < $now) {
                    continue;
                }

                $total++;

                if (!$item->is_read) {
                    $unread++;

                    // 高优先级（>=3）
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

    // ==================== 维护 ====================

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

            // 查找所有 user_notifications 记录
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

    // ==================== 队列/异步支持 ====================

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
            // 消息类型验证已由 wise-notification 的 NotificationManager 处理，
            // 不再依赖已废弃的 SystemMessageType 模型

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

            // 清除用户消息缓存
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

            // 解析接收者
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

            // 构建 Message 对象
            $message = new Message(
                title: $data['title'] ?? '',
                content: $data['content'] ?? '',
                data: $data,
            );

            // 逐个发送（异步入队）
            foreach ($userIds as $userId) {
                /** @var AdminUser|null $user */
                $user = AdminUser::find($userId);
                if ($user) {
                    $manager->channel('database')->send($user, $message);
                }
            }

            // 清除用户缓存
            if (!empty($data['receiver_id'])) {
                $this->clearUserCache((int) $data['receiver_id']);
            }

            return true;
        } catch (\Exception $e) {
            ContextualLogger::error('消息队列失败: ' . $e->getMessage());
            return false;
        }
    }

    // ==================== 消息类型注册（兼容桥接） ====================

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
        // 旧 system_message_types 表已废弃，不再写入
        return true;
    }

    // ==================== 缓存管理 ====================

    /**
     * 清除用户消息缓存
     */
    private function clearUserCache(int $userId): void
    {
        // 清除未读数量缓存
        Cache::delete(self::CACHE_PREFIX . "unread_count:{$userId}");

        // 清除统计数据缓存
        Cache::delete(self::CACHE_PREFIX . "stats:{$userId}");

        // 清除消息列表缓存（模式匹配）
        try {
            $keys = Cache::getHandler()->keys(self::CACHE_PREFIX . "user_messages:{$userId}:*");
            if (is_array($keys)) {
                foreach ($keys as $key) {
                    Cache::delete($key);
                }
            }
        } catch (\Throwable $e) {
            // 某些缓存驱动不支持 keys() 方法（如 file 缓存），
            // 此时由 TTL 自然过期
        }
    }

    // ==================== 内部工具 ====================

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