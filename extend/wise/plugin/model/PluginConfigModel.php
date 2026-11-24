<?php

declare(strict_types=1);

namespace wise\plugin\model;

use think\Model;

/**
 * PluginConfigModel 表示数据库中插件配置的记录。
 *
 * 此 Eloquent 模型映射到 `plugin_config` 表，存储
 * 每个插件的键值配置对。
 *
 * @property int         $id          主键
 * @property string      $plugin_name 插件名称
 * @property string      $key         配置键
 * @property string      $value       配置值（JSON 编码）
 * @property string      $create_time 创建时间戳
 * @property string      $update_time 更新时间戳
 */
class PluginConfigModel extends Model
{
    /**
     * 模型关联的表名（不含前缀）。
     *
     * @var string
     */
    protected $name = 'plugin_config';

    /**
     * 允许批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = ['plugin_name', 'key', 'value'];

    /**
     * 需要进行类型转换的属性。
     *
     * @var array
     */
    protected $type = [
        'value' => 'json',
    ];

    /**
     * 是否自动写入时间戳。
     *
     * @var bool
     */
    protected $autoWriteTimestamp = true;

    /**
     * 创建时间字段名
     *
     * @var string
     */
    protected $createTime = 'create_time';

    /**
     * 更新时间字段名
     *
     * @var string
     */
    protected $updateTime = 'update_time';

    /**
     * 按插件名称和键查找配置记录。
     *
     * @param string $pluginName 插件名称
     * @param string $key        配置键
     * @return static|null
     */
    public static function findByPluginAndKey(string $pluginName, string $key): ?static
    {
        return static::where('plugin_name', $pluginName)
            ->where('key', $key)
            ->find();
    }

    /**
     * 查找插件的所有配置记录。
     *
     * @param string $pluginName 插件名称
     * @return static[]
     */
    public static function findAllByPlugin(string $pluginName): array
    {
        return static::where('plugin_name', $pluginName)->select()->all();
    }
}
