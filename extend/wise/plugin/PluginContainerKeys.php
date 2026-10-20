<?php

declare(strict_types=1);

namespace wise\plugin;

/**
 * 插件系统容器键常量
 *
 * 集中管理所有在 ThinkPHP 容器中注册的插件服务键名，
 * 避免硬编码字符串散落各处导致的拼写错误和维护困难。
 */
final class PluginContainerKeys
{
    /**
     * 插件扫描器
     */
    public const SCANNER = 'plugin.scanner';

    /**
     * 钩子调度器
     */
    public const HOOK_DISPATCHER = 'plugin.hook_dispatcher';

    /**
     * 插件配置管理
     */
    public const CONFIG = 'plugin.config';

    /**
     * 插件配置驱动
     */
    public const CONFIG_DRIVER = 'plugin.config.driver';

    /**
     * 依赖检查器
     */
    public const DEPENDENCY_CHECKER = 'plugin.dependency_checker';

    /**
     * 插件管理器
     */
    public const MANAGER = 'plugin.manager';

    /**
     * 市场驱动
     */
    public const MARKET = 'plugin.market';
}
