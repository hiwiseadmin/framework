<?php
declare(strict_types=1);

namespace plugin\wiseeditor\src;

use think\facade\Config;

/**
 * 编辑器配置合并工具
 *
 * 将 manifest.json 中用户自定义的配置项（数据库存储）与插件默认配置合并。
 */
class EditorConfigHelper
{
    /**
     * 合并用户配置与默认配置
     *
     * @param string $key      配置键（如 'tiptap.toolbar'）
     * @param array  $default  默认值
     * @param array  $user     用户配置
     * @return array
     */
    public static function merge(string $key, array $default, array $user = []): array
    {
        $config = Config::get("wiseeditor.{$key}", []);
        return array_replace_recursive($default, $config, $user);
    }

    /**
     * 获取编辑器配置
     *
     * @param string $editor 编辑器标识（tiptap|milkdown）
     * @return array
     */
    public static function get(string $editor): array
    {
        return Config::get("wiseeditor.{$editor}", []);
    }
}
