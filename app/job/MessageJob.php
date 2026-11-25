<?php
/**
 * MessageJob — 系统消息队列任务（兼容层）
 *
 * 原有调用方（如旧插件）可能仍将任务推入此队列。
 * 现委托给 SystemMessageService::saveMessage()（已改为 public），
 * 由 wise-notification 的 UserNotification 模型处理。
 *
 * 新代码建议直接使用 wise-notification 的 SendNotificationJob。
 *
 * @since 2026-08-09 重构，委托 SystemMessageService
 */
namespace app\job;


use wise\support\context\ContextualLogger;
use think\queue\Job;
use app\admin\service\SystemMessageService;

class MessageJob
{
    /**
     * 队列任务执行入口
     *
     * @param Job   $job  队列任务对象
     * @param array $data 消息数据
     */
    public function fire(Job $job, $data)
    {
        try {
            $messageService = SystemMessageService::instance();
            $result = $messageService->saveMessage($data);

            if ($result) {
                $job->delete();
                ContextualLogger::info('队列消息处理成功: ' . json_encode($data));
            } else {
                $job->release(10);
            }
        } catch (\Exception $e) {
            ContextualLogger::error('队列消息处理失败: ' . $e->getMessage());
            $job->release(30);
        }
    }
}
