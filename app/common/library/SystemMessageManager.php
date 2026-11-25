<?php
/**
 * SystemMessageManager — 系统消息便捷门面（基于 wise-notification 包）
 *
 * 提供静态方法供插件和业务层快速发送系统消息。
 * 底层委托给 app\admin\service\SystemMessageService，
 * 该服务已全面接入 wise-notification 的 DatabaseChannel。
 *
 * 使用方式：
 *   SystemMessageManager::sendToUsers($msg, [1,2,3]);
 *   SystemMessageManager::sendToRole($msg, 2);
 *   SystemMessageManager::sendToAll($msg);
 *   SystemMessageManager::sendMessage(['receivers' => 'all', ...]);
 *
 * @since 2026-08-09 重构，移除已废弃的 MessageService 依赖
 */
namespace app\common\library;

use app\admin\service\SystemMessageService;
use app\common\event\MessageEvent;
use think\facade\Event;
use wise\support\context\ContextualLogger;

class SystemMessageManager
{
    /**
     * 发送消息给指定用户
     *
     * @param array $messageData 消息数据
     * @param array $userIds     用户ID列表
     * @param bool  $useQueue    是否使用队列（保留参数，当前统一同步写入）
     * @return bool
     */
    public static function sendToUsers(array $messageData, array $userIds, bool $useQueue = false): bool
    {
        try {
            $data = [
                'type'      => 'users',
                'message'   => $messageData,
                'receivers' => $userIds,
            ];

            $event = new MessageEvent($data);
            Event::trigger($event);

            if ($event->getResult() === null) {
                $result = SystemMessageService::instance()->sendToUsers($messageData, $userIds);
                $event->setResult($result);
            }

            return $event->getResult();
        } catch (\Exception $e) {
            ContextualLogger::error('发送用户消息失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 发送消息给指定角色
     */
    public static function sendToRole(array $messageData, int $roleId): bool
    {
        try {
            $data = [
                'type'      => 'role',
                'message'   => $messageData,
                'role_id'   => $roleId,
            ];

            $event = new MessageEvent($data);
            Event::trigger($event);

            if ($event->getResult() === null) {
                $result = SystemMessageService::instance()->sendToRole($messageData, $roleId);
                $event->setResult($result);
            }

            return $event->getResult();
        } catch (\Exception $e) {
            ContextualLogger::error('发送角色消息失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 发送消息给所有用户
     */
    public static function sendToAll(array $messageData): bool
    {
        try {
            $data = [
                'type'    => 'all',
                'message' => $messageData,
            ];

            $event = new MessageEvent($data);
            Event::trigger($event);

            if ($event->getResult() === null) {
                $result = SystemMessageService::instance()->sendToAll($messageData);
                $event->setResult($result);
            }

            return $event->getResult();
        } catch (\Exception $e) {
            ContextualLogger::error('发送全员消息失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 插件发送消息的便捷方法
     *
     * 支持接收者格式：
     *   'all'          — 所有用户
     *   'role:1'       — 指定角色ID
     *   'users:[1,2,3]' — 指定用户列表
     */
    public static function sendMessage(array $config): bool
    {
        $defaults = [
            'plugin_name'  => '',
            'message_type' => '',
            'title'        => '',
            'content'      => '',
            'sender'       => null,
            'sender_name'  => null,
            'priority'     => 1,
            'action_url'   => null,
            'action_text'  => null,
            'expire_time'  => null,
            'receivers'    => 'all',
        ];

        $config = array_merge($defaults, $config);

        $messageData = [
            'plugin_name'  => $config['plugin_name'],
            'message_type' => $config['message_type'],
            'title'        => $config['title'],
            'content'      => $config['content'],
            'sender'       => $config['sender'],
            'sender_name'  => $config['sender_name'],
            'priority'     => $config['priority'],
            'action_url'   => $config['action_url'],
            'action_text'  => $config['action_text'],
            'expire_time'  => $config['expire_time'],
        ];

        $receivers = $config['receivers'];

        if ($receivers === 'all') {
            return self::sendToAll($messageData);
        } elseif (strpos($receivers, 'role:') === 0) {
            $roleId = (int) substr($receivers, 5);
            return self::sendToRole($messageData, $roleId);
        } elseif (strpos($receivers, 'users:') === 0) {
            $userIds = json_decode(substr($receivers, 6), true);
            if (is_array($userIds)) {
                return self::sendToUsers($messageData, $userIds);
            }
        }

        return false;
    }

    /**
     * 发送消息（事件驱动）
     *
     * @param array $messageData 完整消息数据（含 receiver_id 和 receiver_type）
     * @param bool  $useQueue    是否使用队列
     * @return bool
     */
    public static function send(array $messageData, bool $useQueue = false): bool
    {
        try {
            $event = new MessageEvent($messageData);
            Event::trigger($event);

            if ($event->getResult() === null) {
                $result = SystemMessageService::instance()->createMessage($messageData, $useQueue);
                $event->setResult($result);
            }

            return $event->getResult();
        } catch (\Exception $e) {
            ContextualLogger::error('消息发送失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 发送即时消息（同步，不走队列）
     */
    public static function sendImmediate(array $messageData): bool
    {
        return self::send($messageData, false);
    }

    /**
     * 发送异步消息（走队列）
     */
    public static function sendAsync(array $messageData): bool
    {
        return self::send($messageData, true);
    }

    /**
     * 批量发送消息
     */
    public static function batchSend(array $messages, bool $useQueue = false): array
    {
        $results = [];
        foreach ($messages as $key => $message) {
            $results[$key] = self::send($message, $useQueue);
        }
        return $results;
    }

    /**
     * 注册消息类型
     *
     * @param string $plugin    插件标识
     * @param string $typeCode  类型代码
     * @param array  $typeConfig 类型配置
     * @return bool
     */
    public static function registerType(string $plugin, string $typeCode, array $typeConfig): bool
    {
        try {
            return SystemMessageService::instance()->registerSystemMessageType($plugin, $typeCode, $typeConfig);
        } catch (\Exception $e) {
            ContextualLogger::error('注册消息类型失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取插件消息类型（已废弃）
     *
     * 旧 system_message_types 表已不再使用。
     * 消息类型现由 wise-notification 的 notification_templates 管理。
     *
     * @param string $plugin 插件标识
     * @return array
     * @deprecated 请使用 wise-notification 的 NotificationTemplate。
     */
    public static function getPluginTypes(string $plugin): array
    {
        return [];
    }
}
