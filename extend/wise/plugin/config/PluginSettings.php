<?php

declare(strict_types=1);

namespace wise\plugin\config;

use wise\support\context\ContextualLogger;
use wise\plugin\exception\PluginConfigException;
use wise\plugin\exception\PluginNotFoundException;
use wise\plugin\PluginSupport;

/**
 * 插件配置（schema 解析 + 读写 + 默认值播种）。
 *
 * 从 PluginManager 拆出：这里只关心「配置」这一件事，
 * 不再与安装/卸载/列表渲染混在同一个 2700 行的类里。
 *
 * schema 的唯一来源是 manifest.json 的 `config` 字段 ——
 * 插件不再能通过代码动态覆盖（原 Plugin::getConfig() 已移除）。
 */
class PluginSettings extends PluginSupport
{
    /**
     * 获取插件的配置项定义（已归一化）。
     *
     * @param  string $name 插件名称（目录名）
     * @return array<string, array> 归一化 schema，无配置声明时返回空数组
     * @throws PluginNotFoundException 插件不存在时
     */
    public function getPluginConfigSchema(string $name): array
    {
        return PluginConfigSchema::normalize($this->manager->getPlugin($name)->getConfigSchema());
    }

    /**
     * 获取插件配置页的展示元数据（标签页 / 分区的中文标题与顺序）。
     *
     * 来源为 manifest 顶层 `config_tabs` / `config_groups`（值支持 `{title,sort}` 或字符串简写），
     * 渲染器 {@see PluginConfigForm::render()} 用它覆盖内置标题表。
     *
     * @return array{tabs: array<string, mixed>, groups: array<string, mixed>}
     * @throws PluginNotFoundException
     */
    public function getPluginConfigMeta(string $name): array
    {
        $metadata = $this->manager->getPlugin($name);

        return [
            'tabs'   => $metadata->getConfigTabs(),
            'groups' => $metadata->getConfigGroups(),
        ];
    }

    /**
     * 获取插件的生效配置值（schema 默认值 ⊕ 已存储值）。
     *
     * 返回的键集始终等于 schema 的键集，即使从未保存过配置，
     * 调用方也能拿到 manifest 声明的默认值。
     *
     * @return array<string, mixed>
     */
    public function getPluginConfig(string $name): array
    {
        return $this->getConfig()->values($name, $this->getPluginConfigSchema($name));
    }

    /**
     * 保存插件配置（白名单 + 类型校验）。
     *
     * 安全约束：
     *   - 仅接受 schema 中声明过的键，其余一律丢弃并记录日志
     *   - 拒绝 '__' 前缀的框架内部状态键，杜绝伪造 __state.installed / __state.enabled
     *   - 插件未声明任何 config 却尝试写入时抛出异常
     *
     * @param  array $config 待保存的配置（键必须是 schema 中声明的）
     * @return array{saved: array, dropped: string[]}
     * @throws PluginConfigException schema 为空却尝试写入时
     */
    public function setPluginConfig(string $name, array $config): array
    {
        $schema = $this->getPluginConfigSchema($name);

        if ($schema === []) {
            if ($config !== []) {
                throw new PluginConfigException(sprintf(
                    '插件 "%s" 未声明任何配置项（manifest.json 的 config 字段为空），拒绝写入配置',
                    $name
                ));
            }

            return ['saved' => [], 'dropped' => []];
        }

        $saved   = [];
        $dropped = [];

        foreach ($config as $key => $value) {
            $key = (string) $key;

            if (PluginConfigSchema::isReserved($key) || !isset($schema[$key])) {
                $dropped[] = $key;
                continue;
            }

            $saved[$key] = PluginConfigSchema::validate($schema[$key], $value);
        }

        if ($dropped !== []) {
            ContextualLogger::warning(sprintf(
                '插件 "%s" 配置保存时丢弃了 %d 个未声明的键：%s',
                $name,
                count($dropped),
                implode(', ', $dropped)
            ));
        }

        if ($saved !== []) {
            $this->getConfig()->setMany($name, $saved);
        }

        return ['saved' => $saved, 'dropped' => $dropped];
    }

    /**
     * 播种插件配置的默认值（幂等）。
     *
     * 仅补齐「schema 中声明了、但库中还没有」的键，绝不覆盖用户已保存的值 ——
     * 保证升级新增配置项时用户设置不丢失。
     *
     * @param  string $name   插件名称（目录名）
     * @param  array  $schema 已归一化的 schema；为空时自动从 manifest 读取
     * @return string[] 本次实际播种的键名
     */
    public function seedDefaultConfig(string $name, array $schema = []): array
    {
        $schema = $schema ?: $this->getPluginConfigSchema($name);
        if ($schema === []) {
            return [];
        }

        $defaults = PluginConfigSchema::defaults($schema);
        $stored   = $this->getConfig()->all($name);

        $seeded = [];
        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $stored)) {
                continue;
            }

            $this->getConfig()->set($name . '.' . (string) $key, $value);
            $seeded[] = (string) $key;
        }

        if ($seeded !== []) {
            $this->getConfig()->flushCache($name);
            ContextualLogger::info(sprintf(
                '插件 "%s" 已播种 %d 个配置默认值：%s',
                $name,
                count($seeded),
                implode(', ', $seeded)
            ));
        }

        return $seeded;
    }
}
