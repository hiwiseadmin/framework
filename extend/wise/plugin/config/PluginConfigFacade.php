<?php

declare(strict_types=1);

namespace wise\plugin\config;

use think\Facade;

/**
 * PluginConfigFacade 为 PluginConfig 提供静态代理。
 *
 * 允许通过静态方法调用便捷地访问插件配置：
 *   PluginConfigFacade::get('myplugin.key');
 *   PluginConfigFacade::set('myplugin.key', 'value');
 *
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void set(string $key, mixed $value)
 * @method static void delete(string $key)
 * @method static array all(string $pluginName) 仅业务配置项（不含 '__' 保留键）
 * @method static array values(string $pluginName, array $schema = []) schema 默认值 ⊕ 已存储值
 * @method static void setMany(string $pluginName, array $config) 批量写入
 * @method static void flushCache(?string $pluginName = null) 清除请求级缓存
 * @method static void clear(string $pluginName) 清空插件全部配置（含状态键）
 * @method static void setDriver(\wise\plugin\contract\PluginConfigDriverInterface $driver)
 */
class PluginConfigFacade extends Facade
{
    /**
     * 获取此代理所指向的门面类名。
     *
     * @return string
     */
    protected static function getFacadeClass(): string
    {
        return PluginConfig::class;
    }
}
