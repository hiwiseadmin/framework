<?php

declare(strict_types=1);

namespace wise\plugin\contract;

/**
 * PluginConfigDriverInterface 定义了插件配置存储驱动的契约。
 *
 * 实现类可以将插件配置存储在数据库、文件或其他后端中。
 */
interface PluginConfigDriverInterface
{
    /**
     * 读取插件的配置值。
     *
     * @param string $pluginName 插件名称
     * @param string $key        配置键
     * @param mixed  $default    键不存在时的默认值
     * @return mixed
     */
    public function read(string $pluginName, string $key, mixed $default = null): mixed;

    /**
     * 写入插件的配置值。
     *
     * @param string $pluginName 插件名称
     * @param string $key        配置键
     * @param mixed  $value      要存储的值
     * @return void
     */
    public function write(string $pluginName, string $key, mixed $value): void;

    /**
     * 删除插件的配置值。
     *
     * @param string $pluginName 插件名称
     * @param string $key        配置键
     * @return void
     */
    public function delete(string $pluginName, string $key): void;

    /**
     * 清空插件的所有配置值。
     *
     * 实现应一次性删除该插件下的全部配置，避免逐条删除中途失败导致残留。
     *
     * @param string $pluginName 插件名称
     * @return void
     */
    public function clear(string $pluginName): void;

    /**
     * 读取插件的所有配置值。
     *
     * @param string $pluginName 插件名称
     * @return array
     */
    public function readAll(string $pluginName): array;
}
