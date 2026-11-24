<?php
declare(strict_types=1);

namespace app\listener;

use wise\support\context\ContextualLogger;
use wise\config\event\ConfigSaved;
use wise\config\event\ConfigReverted;
use wise\config\event\DictChangedEvent;

/**
 * WiseConfig 配置变更监听器
 *
 * 当配置或字典发生变更时，刷新相关模块的缓存：
 *  - Member ConfigProvider 缓存
 *  - 其他依赖 wise-config 运行时配置的模块
 *
 * 监听事件：
 *  - ConfigSaved    : 配置保存后
 *  - ConfigReverted : 配置回滚后
 *  - DictChangedEvent : 字典变更后
 */
class WiseConfigChanged
{
    /**
     * 处理事件
     *
     * @param ConfigSaved|ConfigReverted|DictChangedEvent $event
     * @return void
     */
    public function handle($event): void
    {
        try {
            if (class_exists(\plugin\member\service\ConfigProvider::class)) {
                $provider = app(\plugin\member\service\ConfigProvider::class);
                $provider->refresh();
            }
        } catch (\Throwable $e) {
            ContextualLogger::warning("[WiseConfig] 刷新 Member ConfigProvider 缓存失败: " . $e->getMessage());
        }

        $eventName = get_class($event);
        $details   = '';

        if ($event instanceof ConfigSaved) {
            $details = "key={$event->key}, ns={$event->namespace}, old={$event->oldValue}, new={$event->newValue}";
        } elseif ($event instanceof ConfigReverted) {
            $details = "key={$event->key}, ns={$event->namespace}, toValue={$event->toValue}";
        } elseif ($event instanceof DictChangedEvent) {
            $details = "dictCode={$event->getDictCode()}, action={$event->getAction()}";
        }

        ContextualLogger::info("[WiseConfig] 配置变更: {$eventName} | {$details}");
    }
}
