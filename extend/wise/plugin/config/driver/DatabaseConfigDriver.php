<?php

declare(strict_types=1);

namespace wise\plugin\config\driver;

use wise\plugin\config\PluginConfigSchema;
use wise\plugin\contract\PluginConfigDriverInterface;
use wise\plugin\model\PluginConfigModel;

/**
 * DatabaseConfigDriver 将插件配置存储在数据库中。
 *
 * 使用 PluginConfigModel（ThinkPHP ORM）在 `plugin_config` 表中持久化
 * 键值对配置数据。
 */
class DatabaseConfigDriver implements PluginConfigDriverInterface
{
    /**
     * 读取插件的配置值。
     *
     * @param string $pluginName 插件名称
     * @param string $key        配置键
     * @param mixed  $default    键不存在时的默认值
     * @return mixed
     */
    public function read(string $pluginName, string $key, mixed $default = null): mixed
    {
        $record = PluginConfigModel::findByPluginAndKey($pluginName, $key);

        if ($record === null) {
            return $default;
        }

        return $record->value;
    }

    /**
     * 写入插件的配置值。
     *
     * 创建或更新配置项。
     *
     * @param string $pluginName 插件名称
     * @param string $key        配置键
     * @param mixed  $value      要存储的值
     * @return void
     */
    public function write(string $pluginName, string $key, mixed $value): void
    {
        // 验证值可 JSON 序列化
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \wise\plugin\exception\PluginConfigException(
                "配置值 {$pluginName}.{$key} 不可 JSON 序列化: " . $e->getMessage()
            );
        }

        $record = PluginConfigModel::findByPluginAndKey($pluginName, $key);

        if ($record !== null) {
            $record->value = $value;
            $record->save();
        } else {
            PluginConfigModel::create([
                'plugin_name' => $pluginName,
                'key' => $key,
                'value' => $value,
            ]);
        }
    }

    /**
     * 删除插件的配置值。
     *
     * @param string $pluginName 插件名称
     * @param string $key        配置键
     * @return void
     */
    public function delete(string $pluginName, string $key): void
    {
        $record = PluginConfigModel::findByPluginAndKey($pluginName, $key);

        if ($record !== null) {
            $record->delete();
        }
    }

    /**
     * 读取插件的所有业务配置值。
     *
     * 返回键值对的关联数组。仅返回业务配置项：
     * 以 '__' 开头的框架内部状态键（如 __state.enabled / __state.installed）
     * 一律过滤，避免状态位混入业务配置。
     *
     * 注意：read() / write() / delete() / clear() 不加该过滤，
     * PluginStateManager 依赖它们读写状态键，clear() 也需在卸载时清干净状态。
     *
     * @param string $pluginName 插件名称
     * @return array
     */
    public function readAll(string $pluginName): array
    {
        $records = PluginConfigModel::findAllByPlugin($pluginName);
        $result = [];

        foreach ($records as $record) {
            if (PluginConfigSchema::isReserved((string) $record->key)) {
                continue;
            }

            $result[$record->key] = $record->value;
        }

        return $result;
    }

    /**
     * 清空插件的所有配置值。
     *
     * 使用单条 SQL 原子删除，避免逐条删除中途失败导致残留。
     *
     * @param string $pluginName 插件名称
     * @return void
     */
    public function clear(string $pluginName): void
    {
        PluginConfigModel::where('plugin_name', $pluginName)->delete();
    }
}
