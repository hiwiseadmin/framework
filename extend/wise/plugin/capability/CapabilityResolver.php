<?php
declare(strict_types=1);

namespace wise\plugin\capability;

use wise\plugin\scanner\PluginMetadata;
use wise\plugin\scanner\PluginScanner;
use wise\support\ExtPoint;

/**
 * 能力解析器
 *
 * 在插件安装/启用时检查其能力需求是否被满足（由
 * {@see \wise\plugin\lifecycle\PluginLifecycle} 在依赖检查之后调用）。
 * 支持三层协商：约定（命名匹配）→ 契约（接口校验）→ 诊断（缺失建议）
 *
 * 与 ExtPoint 配合使用：
 *  - 消费方在 manifest.json 中声明 capabilities.requires
 *  - 提供方通过 ExtPoint::register() 注册实现
 *  - 本解析器自动匹配并生成诊断报告
 *
 * 使用示例：
 * <pre>
 * $resolver = new CapabilityResolver($pluginScanner);
 *
 * // 安装前检查
 * $result = $resolver->resolve($pluginMetadata);
 * if (!$result->isSatisfied()) {
 *     foreach ($result->getMissing() as $missing) {
 *         echo $missing['message'] . "\n";
 *         echo "建议: " . implode(', ', $missing['suggestions']) . "\n";
 *     }
 * }
 * </pre>
 *
 * @package wise\plugin\capability
 */
class CapabilityResolver
{
    /**
     * @var PluginScanner
     */
    protected PluginScanner $scanner;

    /**
     * 已解析过的结果缓存（plugin ident → CapabilityResult）
     *
     * @var array<string, CapabilityResult>
     */
    protected array $cache = [];

    public function __construct(PluginScanner $scanner)
    {
        $this->scanner = $scanner;
    }

    /**
     * 解析插件的能力需求
     *
     * @param PluginMetadata $metadata 目标插件元数据
     * @return CapabilityResult
     */
    public function resolve(PluginMetadata $metadata, bool $force = false): CapabilityResult
    {
        $ident = $metadata->getIdent();

        if (!$force && isset($this->cache[$ident])) {
            return $this->cache[$ident];
        }

        $result = new CapabilityResult($ident);

        $capabilities = $metadata->getCapabilities();

        $requires = $capabilities['requires'] ?? [];

        foreach ($requires as $capability => $config) {
            if (!is_array($config)) {
                $config = is_bool($config)
                    ? ['required' => $config]
                    : ['description' => (string) $config];
            }
            $this->resolveCapability($capability, $config, $result);
        }

        $this->cache[$ident] = $result;
        return $result;
    }

    /**
     * 解析单个能力需求
     */
    protected function resolveCapability(string $capability, array $config, CapabilityResult $result): void
    {
        $required = $config['required'] ?? true;
        $mode     = $config['mode'] ?? 'any';
        $preferred = $config['preferred'] ?? null;
        $description = $config['description'] ?? '';

        $implementations = ExtPoint::all($capability);

        if (empty($implementations)) {
            if ($required) {
                $result->addMissing(
                    $capability,
                    "能力 [{$capability}] 无可用实现" . ($description ? "（{$description}）" : ''),
                    $this->findSuggestions($capability)
                );
            } else {
                $result->addOptional($capability, "可选能力 [{$capability}] 未安装");
            }
            return;
        }

        $selected = $this->selectImplementations($implementations, $mode, $preferred);

        if (empty($selected)) {
            $result->addMissing(
                $capability,
                "能力 [{$capability}] 有实现但不符合选择条件（mode={$mode}）",
                array_keys($implementations)
            );
            return;
        }

        $result->addResolved($capability, $selected);
    }

    /**
     * 根据模式选择实现
     */
    protected function selectImplementations(array $implementations, string $mode, ?string $preferred): array
    {
        return match ($mode) {
            'active' => $this->selectActive($implementations),
            'all'    => $implementations,
            default  => $this->selectPreferred($implementations, $preferred),
        };
    }

    /**
     * 选择 active 标记的实现
     */
    protected function selectActive(array $implementations): array
    {
        foreach ($implementations as $name => $item) {
            if ($item['active'] ?? false) {
                return [$name => $item];
            }
        }
        $first = reset($implementations);
        $firstName = array_key_first($implementations);
        return $firstName ? [$firstName => $first] : [];
    }

    /**
     * 按 preferred 选择，无则返回第一个
     */
    protected function selectPreferred(array $implementations, ?string $preferred): array
    {
        if ($preferred && isset($implementations[$preferred])) {
            return [$preferred => $implementations[$preferred]];
        }

        $first = reset($implementations);
        $firstName = array_key_first($implementations);
        return $firstName ? [$firstName => $first] : [];
    }

    /**
     * 为缺失的能力寻找建议
     *
     * 从已扫描的插件中查找提供了该能力的插件
     *
     * @return string[] 建议列表
     */
    protected function findSuggestions(string $capability): array
    {
        $suggestions = [];

        $allPlugins = $this->scanner->scan();
        foreach ($allPlugins as $name => $meta) {
            $provides = $meta->getCapabilities()['provides'] ?? [];
            foreach ($provides['services'] ?? [] as $serviceCap => $_) {
                if (str_starts_with($serviceCap, $capability)) {
                    $suggestions[] = "插件 [{$name}] 提供了 [{$serviceCap}]";
                }
            }
        }

        $points = ExtPoint::points();
        foreach ($points as $point) {
            if (str_contains($point, $capability) || str_contains($capability, $point)) {
                $suggestions[] = "扩展点 [{$point}] 已有注册实现";
            }
        }

        if (empty($suggestions)) {
            $suggestions[] = "暂无插件提供 [{$capability}]，可考虑安装相应的功能插件";
        }

        return $suggestions;
    }

    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
