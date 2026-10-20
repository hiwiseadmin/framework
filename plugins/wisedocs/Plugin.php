<?php

declare(strict_types=1);

namespace plugin\wisedocs;

use plugin\BasePlugin;
use plugin\wisedocs\search\WisedocsSearchProvider;

/**
 * WiseDocs 插件主类
 *
 * 插件日常启动/挂载由 BootServiceProvider 承担；本类为插件管理系统预留的
 * 轻量入口（生命周期钩子、全局搜索钩子等），按需覆写。
 */
class Plugin extends BasePlugin
{
    /**
     * 全局搜索接入（后台 `/` 或 Ctrl+K / Cmd+K 命令面板）。
     *
     * 由框架 PluginSearchProvider 通过 `callHook($ident, 'getSearchProvider')` 调用，
     * 返回实现 {@see \app\admin\contract\SearchProviderInterface} 的提供者数组。
     *
     * @return \app\admin\contract\SearchProviderInterface[]
     */
    public function getSearchProvider(): array
    {
        return [
            new WisedocsSearchProvider(),
        ];
    }
}
