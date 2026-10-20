<?php

declare(strict_types=1);

namespace wise\plugin;

use wise\plugin\scanner\PluginMetadata;

/**
 * 统一插件主类 —— 所有插件的 Plugin.php 继承此类。
 *
 * 类路径约定：plugins/{name}/Plugin.php
 * 命名空间约定：plugin\{name}
 * 类名约定：Plugin
 *
 * 与旧系统的关键区别：
 * - install/uninstall/enable/disable 不再是抽象方法（默认返回 true，按需覆写）
 * - 元数据由框架通过 setMetadata() 注入，不再自行解析 manifest.json
 * - 移除了 __get 魔法方法和 getInfo() 手动清单解析
 * - 插件生命周期方法在 PluginManager 完成框架级流程后调用
 *
 * 使用示例：
 *
 *   namespace plugin\my-plugin;
 *   use wise\plugin\Plugin as BasePlugin;
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
 */
abstract class Plugin
{
    /**
     * 由框架注入的插件元数据。
     *
     * @var PluginMetadata|null
     */
    protected ?PluginMetadata $metadata = null;

    // ═══════════════════════════════════════════════════════════
    //  框架注入方法
    // ═══════════════════════════════════════════════════════════

    /**
     * 设置插件元数据（由 PluginManager 在安装/启用时调用）。
     *
     * @param PluginMetadata $metadata
     * @return void
     */
    final public function setMetadata(PluginMetadata $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * 获取插件元数据。
     *
     * @return PluginMetadata|null
     */
    final public function getMetadata(): ?PluginMetadata
    {
        return $this->metadata;
    }

    // ═══════════════════════════════════════════════════════════
    //  生命周期方法（可选覆写）
    // ═══════════════════════════════════════════════════════════

    /**
     * 安装时执行。
     *
     * 调用时机：PluginManager 完成迁移、菜单注册、权限注册、资源复制后（**流程最后一步**）。
     *
     * 返回 false 将**真正回滚整个安装流程**（见 `PluginManager::rollbackInstall()`）：
     * 迁移 / 菜单 / 权限 / 插槽 / 配置 / 安装状态 / 静态资源全部还原。
     * 其中菜单与权限走**软删**（可恢复）—— 与卸载语义一致，重装时复用同一行，
     * 不会丢掉运营在后台改过的菜单名/排序。
     *
     * ⚠️ 本方法**不负责建表与初始数据**（迁移/填充早于此步执行），那两类需求请用
     * `database/migrations` 与 `database/seeds`。此处的典型用途是"安装后的自定义副作用"
     * 或"环境不满足则不完成安装"的门禁判断。
     *
     * @return bool
     */
    public function install(): bool
    {
        return true;
    }

    /**
     * 卸载时执行。
     *
     * 调用时机：PluginManager 清理菜单/权限/资源/配置之前。
     * 返回 false 仅记录日志，不阻止卸载。
     *
     * @return bool
     */
    public function uninstall(): bool
    {
        return true;
    }

    /**
     * 启用时执行。
     *
     * 调用时机：PluginManager 完成依赖检查和资源检查后。
     * 返回 false 将阻止插件启用。
     *
     * @return bool
     */
    public function enable(): bool
    {
        return true;
    }

    /**
     * 禁用时执行。
     *
     * 调用时机：PluginManager 已将禁用状态落库后。
     * 返回 false 仅记录日志，不阻止禁用。
     *
     * @return bool
     */
    public function disable(): bool
    {
        return true;
    }

    // ═══════════════════════════════════════════════════════════
    //  配置（可选覆写）
    // ═══════════════════════════════════════════════════════════

    /**
     * 获取插件配置项 schema 定义。
     *
     * 返回格式：
     * [
     *     '配置键名' => [
     *         'type'    => 'text|textarea|radio|checkbox|select',
     *         'title'   => '配置项标题',
     *         'value'   => '默认值',
     *         'options' => [],  // radio/checkbox/select 时需要
     *         'tips'    => '配置项说明',
     *     ]
     * ]
     *
     * 如未覆写，返回 manifest.json 中 config 字段的内容。
     *
     * @return array
     */
    public function getConfig(): array
    {
        if ($this->metadata !== null) {
            return $this->metadata->getConfigSchema();
        }
        return [];
    }

    // ═══════════════════════════════════════════════════════════
    //  工具方法
    // ═══════════════════════════════════════════════════════════

    /**
     * 获取插件标识名。
     *
     * @return string
     */
    public function getName(): string
    {
        if ($this->metadata !== null) {
            return $this->metadata->getName();
        }

        // 从类命名空间自动推导
        $parts = explode('\\', static::class);
        return $parts[1] ?? '';
    }
}
