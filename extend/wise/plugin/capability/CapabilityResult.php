<?php
declare(strict_types=1);

namespace wise\plugin\capability;

/**
 * 能力解析结果
 *
 * @package wise\plugin\capability
 */
class CapabilityResult
{
    /**
     * 插件标识
     */
    protected string $pluginIdent;

    /**
     * 已解析的能力（能力名 → 选中实现列表）
     *
     * @var array<string, array>
     */
    protected array $resolved = [];

    /**
     * 缺失的能力
     *
     * @var array<int, array{capability: string, message: string, suggestions: string[]}>
     */
    protected array $missing = [];

    /**
     * 可选但未安装的能力
     *
     * @var array<string, string>
     */
    protected array $optional = [];

    public function __construct(string $pluginIdent)
    {
        $this->pluginIdent = $pluginIdent;
    }

    /**
     * 是否所有必需能力都已满足
     */
    public function isSatisfied(): bool
    {
        return empty($this->missing);
    }

    /**
     * 是否需要警告（有可选能力未安装）
     */
    public function hasWarnings(): bool
    {
        return !empty($this->optional);
    }

    /**
     * 添加已解析的能力
     */
    public function addResolved(string $capability, array $implementations): void
    {
        $this->resolved[$capability] = $implementations;
    }

    /**
     * 添加缺失的必需能力
     *
     * @param string   $capability   能力名称
     * @param string   $message      描述消息
     * @param string[] $suggestions  建议列表
     */
    public function addMissing(string $capability, string $message, array $suggestions = []): void
    {
        $this->missing[] = [
            'capability'  => $capability,
            'message'     => $message,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * 添加可选但未安装的能力
     */
    public function addOptional(string $capability, string $message): void
    {
        $this->optional[$capability] = $message;
    }

    public function getPluginIdent(): string
    {
        return $this->pluginIdent;
    }

    /**
     * 获取已解析的能力
     *
     * @return array<string, array>
     */
    public function getResolved(): array
    {
        return $this->resolved;
    }

    /**
     * 获取缺失的能力列表
     *
     * @return array<int, array>
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    /**
     * 获取缺失能力的消息摘要
     *
     * @return string[]
     */
    public function getMissingMessages(): array
    {
        return array_map(fn(array $item): string => $item['message'], $this->missing);
    }

    /**
     * 获取可选能力的消息
     *
     * @return array<string, string>
     */
    public function getOptional(): array
    {
        return $this->optional;
    }

    /**
     * 获取所有能力建议（缺失 + 可选）
     *
     * @return string[]
     */
    public function getAllSuggestions(): array
    {
        $suggestions = [];
        foreach ($this->missing as $item) {
            foreach ($item['suggestions'] as $s) {
                $suggestions[] = "[缺失] {$s}";
            }
        }
        foreach ($this->optional as $cap => $msg) {
            $suggestions[] = "[可选] {$msg}";
        }
        return $suggestions;
    }

    /**
     * 转为数组
     */
    public function toArray(): array
    {
        return [
            'plugin'    => $this->pluginIdent,
            'satisfied' => $this->isSatisfied(),
            'warnings'  => $this->hasWarnings(),
            'resolved'  => $this->resolved,
            'missing'   => $this->missing,
            'optional'  => $this->optional,
        ];
    }
}
