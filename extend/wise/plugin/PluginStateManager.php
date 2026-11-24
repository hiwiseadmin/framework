<?php

declare(strict_types=1);

namespace wise\plugin;

use wise\plugin\config\PluginConfig;

/**
 * 插件运行时状态管理器
 *
 * 负责插件 enabled 状态的读取、写入和清除。
 * 状态通过 PluginConfig 持久化，不污染 plugin.json 源码文件。
 * 从 PluginManager 中分离出来，遵循单一职责原则。
 */
class PluginStateManager
{
    /**
     * enabled 状态的 PluginConfig 键名
     */
    protected const STATE_KEY = '__state.enabled';

    /**
     * installed 状态的 PluginConfig 键名
     */
    protected const INSTALLED_KEY = '__state.installed';

    /**
     * 「卸载后残留数据表」登记键。
     *
     * 卸载时若迁移回滚没能把表删干净（例如迁移 down() 漏写 `->save()`），
     * 仅靠"状态键是否存在"无法把这种情况识别出来（状态可能已被清、也可能还在），
     * 因此**当场把残留表名记下来**：
     *   - 列表可据此明确显示「数据残留·需修复」并给出「重装」入口；
     *   - 即使插件目录随后被删（CLI 卸载默认删目录），也仍认得出这批表。
     *
     * 值为表名数组（JSON 编码）；与业务配置同表但以 `__` 前缀隔离，
     * 业务侧 `readAll()` 会过滤掉。
     */
    protected const RESIDUAL_KEY = '__state.residual_tables';

    /**
     * 配置管理器
     *
     * @var PluginConfig
     */
    protected PluginConfig $config;

    /**
     * 创建 PluginStateManager 实例
     *
     * @param PluginConfig $config
     */
    public function __construct(PluginConfig $config)
    {
        $this->config = $config;
    }

    /**
     * 检查插件是否已启用
     *
     * @param string $name 插件名称
     * @return bool
     */
    public function isEnabled(string $name): bool
    {
        try {
            return $this->config->get($name . '.' . self::STATE_KEY) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 设置插件的启用状态
     *
     * @param string $name    插件名称
     * @param bool   $enabled 是否启用
     * @return void
     */
    public function setEnabled(string $name, bool $enabled): void
    {
        $this->config->set($name . '.' . self::STATE_KEY, $enabled);
    }

    /**
     * 检查插件是否已安装（执行过迁移/填充等框架集成）。
     *
     * 仅表示"有安装记录"，不表示"有记录之外的任何含义"；
     * 无记录时由 PluginManager 结合迁移记录做兼容判定。
     *
     * @param string $name 插件名称
     * @return bool
     */
    public function isInstalled(string $name): bool
    {
        try {
            return $this->config->get($name . '.' . self::INSTALLED_KEY) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 标记插件的安装状态
     *
     * @param string $name     插件名称
     * @param bool   $installed 是否已安装
     * @return void
     */
    public function setInstalled(string $name, bool $installed): void
    {
        $this->config->set($name . '.' . self::INSTALLED_KEY, $installed);
    }

    /**
     * 清除插件的运行时状态（启用状态 + 安装状态）
     *
     * @param string $name 插件名称
     * @return void
     */
    public function clearState(string $name): void
    {
        $this->config->delete($name . '.' . self::STATE_KEY);
        $this->config->delete($name . '.' . self::INSTALLED_KEY);
        $this->config->delete($name . '.' . self::RESIDUAL_KEY);
    }

    /**
     * 读取「卸载后残留数据表」登记。
     *
     * @param string $name 插件名称
     * @return string[] 残留表名（含前缀）；无登记时返回空数组
     */
    public function getResidualTables(string $name): array
    {
        try {
            $value = $this->config->get($name . '.' . self::RESIDUAL_KEY);
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $value),
            static fn (string $table): bool => $table !== ''
        ));
    }

    /**
     * 写入/清除「卸载后残留数据表」登记。
     *
     * 传空数组即删除该键 —— 这样"清干净了"与"从未有过残留"在库里表现一致。
     *
     * @param string   $name   插件名称
     * @param string[] $tables 残留表名（含前缀）
     * @return void
     */
    public function setResidualTables(string $name, array $tables): void
    {
        $tables = array_values(array_unique(array_filter(
            array_map('strval', $tables),
            static fn (string $table): bool => $table !== ''
        )));

        if ($tables === []) {
            $this->config->delete($name . '.' . self::RESIDUAL_KEY);
            return;
        }

        $this->config->set($name . '.' . self::RESIDUAL_KEY, $tables);
    }

    /**
     * 获取所有已启用插件的名称列表
     *
     * @param array<string, \wise\plugin\scanner\PluginMetadata> $plugins 所有已扫描插件
     * @return string[] 已启用插件名称数组
     */
    public function getEnabledNames(array $plugins): array
    {
        $enabled = [];
        foreach ($plugins as $name => $metadata) {
            if ($this->isEnabled($name)) {
                $enabled[] = $name;
            }
        }
        return $enabled;
    }
}
