<?php
declare(strict_types=1);

namespace app\admin\search;

use app\admin\contract\SearchProviderInterface;
use app\admin\model\Plugin as PluginModel;
use wise\plugin\PluginContainerKeys;
use wise\support\context\ContextualLogger;

/**
 * 插件搜索提供者（聚合）
 *
 * 遍历所有已启用插件，调用 Plugin::getSearchProvider() 钩子方法。
 * 注意：此 Provider 本身不产生搜索结果，而是作为桥梁，
 * 将插件返回的 SearchProvider 注册到 SearchService。
 *
 * 插件示例（在 plugin/xxx/Plugin.php 中）：
 * <pre>
 * public function getSearchProvider(): array {
 *     return [new class implements SearchProviderInterface { ... }];
 * }
 * </pre>
 */
class PluginSearchProvider implements SearchProviderInterface
{
    /** @var SearchProviderInterface[] */
    private $delegates = [];

    public function getId(): string
    {
        return 'plugin';
    }

    public function getName(): string
    {
        return '插件';
    }

    public function getIcon(): string
    {
        return 'bi bi-plugin';
    }

    /**
     * 加载插件注册的搜索提供者
     */
    private function loadDelegates(): void
    {
        if (!empty($this->delegates)) {
            return;
        }

        try {
            $pluginManager = app(PluginContainerKeys::MANAGER);
            $enabledPlugins = PluginModel::where('status', 1)->column('ident');
        } catch (\Throwable $e) {
            return;
        }

        foreach ($enabledPlugins as $ident) {
            try {
                $items = $pluginManager->callHook($ident, 'getSearchProvider', []);
                if (is_array($items)) {
                    foreach ($items as $item) {
                        if ($item instanceof SearchProviderInterface) {
                            $this->delegates[] = $item;
                        }
                    }
                }
            } catch (\Throwable $e) {
                ContextualLogger::warning("插件 {$ident} getSearchProvider 钩子异常: " . $e->getMessage());
            }
        }
    }

    public function search(string $keyword, int $limit = 10): array
    {
        $this->loadDelegates();

        $results = [];
        foreach ($this->delegates as $delegate) {
            try {
                $items = $delegate->search($keyword, $limit);
                if (is_array($items)) {
                    $results = array_merge($results, $items);
                }
            } catch (\Throwable $e) {
                ContextualLogger::warning("插件搜索提供者 {$delegate->getId()} 异常: " . $e->getMessage());
            }
        }

        return $results;
    }
}
