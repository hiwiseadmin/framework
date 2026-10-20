<?php
declare(strict_types=1);

namespace plugin\demo;

use plugin\BasePlugin;

/**
 * demo 插件主类
 *
 * 可覆写以下方法实现自定义逻辑：
 * - install(): bool    — 框架完成迁移/菜单/权限/资源后调用，返回 false 回滚安装
 * - uninstall(): bool  — 框架清理前调用，返回 false 仅日志不阻止
 * - enable(): bool     — 框架启用时调用，返回 false 阻止启用
 * - disable(): bool    — 框架禁用时调用（状态已落库），返回 false 仅日志
 * - getConfig(): array — 自定义配置项 schema
 */
class Plugin extends BasePlugin
{
    /**
     * 安装时执行。
     */
    public function install(): bool
    {
        // 在此编写安装逻辑（创建数据表、初始数据等）
        return true;
    }

    /**
     * 卸载时执行。
     */
    public function uninstall(): bool
    {
        // 在此编写卸载清理逻辑
        return true;
    }

    /**
     * 配置项定义。
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            // 'apikey' => [
            //     'type'    => 'text',
            //     'title'   => 'API 密钥',
            //     'value'   => '',
            //     'tips'    => '第三方 API 的访问密钥',
            // ],
        ];
    }
}
