<?php
declare(strict_types=1);

namespace app\listener;

use wise\support\context\ContextualLogger;

/**
 * WiseConfig TpConfigBridge 初始化监听器
 *
 * 在应用启动时将数据库中的动态配置注入到 ThinkPHP 原生 config() 体系，
 * 使 `config('wise.group.key')` 可以直接访问 wise-config 存储的配置。
 *
 * 监听 HttpRun 事件（仅 HTTP 请求触发，CLI 命令不触发）。
 */
class WiseConfigBridgeInit
{
    /**
     * 处理事件
     *
     * @return void
     */
    public function handle(): void
    {
        // 本监听器挂在 HttpRun（仅 HTTP 请求触发），无需再判断 CLI：
        // Swoole 下 runningInConsole() 恒为 true，原判断会导致动态配置永不注入。
        try {
            $bridge = app('wise.config.tp_config_bridge');
            $prefix = config('wise-config.tp_integration.inject_prefix', 'wise');
            $count  = $bridge->injectToConfig('default', $prefix);

            if ($count > 0) {
                ContextualLogger::debug("[WiseConfig] TpConfigBridge 已注入 {$count} 条配置到 config('{$prefix}.*')");
            }
        } catch (\Throwable $e) {
            // 桥接失败不应阻塞应用启动
            ContextualLogger::warning("[WiseConfig] TpConfigBridge 注入失败: " . $e->getMessage());
        }
    }
}
