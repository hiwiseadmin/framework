<?php

declare(strict_types=1);

namespace plugin;

use wise\plugin\Plugin as CorePlugin;

/**
 * 插件主类基类 —— 所有插件的 Plugin.php 继承此类。
 *
 * 内部委托给 {@see \wise\plugin\Plugin}，为插件开发者提供一个
 * 与插件管理系统完全解耦的轻量入口。插件代码中无需导入 wise\plugin
 * 命名空间。
 *
 * 使用示例：
 *
 *   namespace plugin\my-plugin;
 *   use plugin\BasePlugin;
 *
 *   class Plugin extends BasePlugin
 *   {
 *       public function install(): bool
 *       {
 *           // 在框架完成迁移/菜单/权限/资源后执行自定义逻辑
 *           return true;
 *       }
 *
 *       public function getConfig(): array
 *       {
 *           return [
 *               'apikey' => ['type' => 'text', 'title' => 'API Key', 'value' => ''],
 *           ];
 *       }
 *   }
 *
 * 类路径约定：plugins/{name}/Plugin.php
 * 命名空间约定：plugin\{name}
 * 类名约定：Plugin
 *
 * @see \wise\plugin\Plugin  核心实现
 */
abstract class BasePlugin extends CorePlugin
{
}
