<?php
/**
 * MessageServiceProvider — 消息服务提供者（已重构）
 *
 * 原绑定 MessageService/MessageListener，这两个类不存在且已被废弃。
 * 系统消息功能现已由 wise-notification 包接管，通过 SystemMessageManager
 * 和 SystemMessageService 提供事件驱动的发送能力。
 *
 * 保留此 Provider 兼容性，绑定映射到 SystemMessageService。
 *
 * @since 2026-08-09 重构接入 wise-notification
 */
namespace app\common\provider;

use app\admin\service\SystemMessageService;
use think\Service;

class MessageServiceProvider extends Service
{
    public function register()
    {
        $this->app->bind('messageService', function () {
            return \app\admin\service\SystemMessageService::instance();
        });
    }

    public function boot()
    {
    }
}