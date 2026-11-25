<?php
declare(strict_types=1);

namespace app\admin\service;

use app\admin\contract\SearchProviderInterface;
use wise\support\context\ContextualLogger;

/**
 * 全局搜索服务
 *
 * 职责：
 * 1. 管理 SearchProvider 注册表
 * 2. 遍历 Provider 执行搜索
 * 3. 聚合结果排序（按 score 降序）
 * 4. 关键词级缓存
 */
class SearchService
{
    /**
     * @var SearchProviderInterface[]
     */
    private $providers = [];

    /**
     * 注册搜索提供者
     */
    public function register(SearchProviderInterface $provider): void
    {
        $this->providers[$provider->getId()] = $provider;
    }

    /**
     * 批量注册提供者
     *
     * @param SearchProviderInterface[] $providers
     */
    public function registerMany(array $providers): void
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    /**
     * 获取所有已注册的提供者
     *
     * @return SearchProviderInterface[]
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * 全局搜索入口
     *
     * 流程：
     *  1. 尝试读取关键词缓存（缓存穿透降级为直接搜索）
     *  2. 遍历所有 Provider 执行 search()
     *  3. 聚合 + 按 score 降序
     *  4. 截断到 $limit 条
     *  5. 写入缓存
     *
     * @param string $keyword 搜索关键词
     * @param int    $limit   最大返回条数
     * @return array<int, array{id: string, title: string, subtitle: string, url: string, icon: string, category: string, provider: string, breadcrumb: ?string, score: int}>
     */
    public function search(string $keyword, int $limit = 20): array
    {
        $cacheKey = $this->buildCacheKey($keyword);
        $cached   = cache($cacheKey) ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $allResults = [];
        foreach ($this->providers as $provider) {
            try {
                $items = $provider->search($keyword, $limit);
                if (is_array($items)) {
                    $allResults = array_merge($allResults, $items);
                }
            } catch (\Throwable $e) {
                ContextualLogger::warning("搜索提供者 {$provider->getId()} 异常: " . $e->getMessage());
            }
        }

        usort($allResults, function ($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        $allResults = array_slice($allResults, 0, $limit);

        cache($cacheKey, $allResults, 60);

        return $allResults;
    }

    /**
     * 构建缓存 key（含 admin_id，多用户隔离）
     */
    private function buildCacheKey(string $keyword): string
    {
        $adminId = 0;
        try {
            $adminId = \app\admin\facade\CurrentAdmin::getAdminId();
        } catch (\Throwable $e) {
        }
        return "global_search:{$adminId}:" . md5($keyword);
    }
}
