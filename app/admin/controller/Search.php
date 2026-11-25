<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\contract\SearchProviderInterface;
use app\admin\service\SearchService;
use app\admin\search\MenuSearchProvider;
use app\admin\search\ConfigSearchProvider;
use wise\support\context\ContextualLogger;
use wise\support\ExtPoint;
use wise\support\ExtPoints;

/**
 * 全局搜索控制器
 *
 * POST /admin/search/global
 *  - 需要登录但无需单独 RBAC 鉴权（通过 wise-auth.whitelist_routes 白名单控制）
 *  - 搜索结果受 SearchProvider 内部 RBAC 约束（如 MenuSearchProvider 复用角色菜单树）
 */
class Search extends BaseController
{
    /**
     * 全局搜索 API
     *
     * POST 参数：
     *   - keyword: string  搜索关键词（1-50 字符）
     *
     * 响应：
     *   - code=0, data=array[]  搜索成功
     *   - code=1, msg=...      搜索失败
     */
    public function global()
    {
        $keyword = trim((string)($this->request->post('keyword', '')));
        if ($keyword === '') {
            return $this->success('ok', []);
        }
        if (mb_strlen($keyword) > 50) {
            return $this->error('搜索关键词不能超过 50 个字符');
        }

        $manager = app(SearchService::class);

        $this->registerProviders($manager);

        try {
            $results = $manager->search($keyword, 20);
            return $this->success('ok', $results);
        } catch (\Throwable $e) {
            ContextualLogger::error('全局搜索异常: ' . $e->getMessage());
            return $this->error('搜索服务暂时不可用');
        }
    }

    /**
     * 获取搜索服务状态（调试用，可选）
     */
    public function status()
    {
        $manager = app(SearchService::class);
        $this->registerProviders($manager);

        $providers = [];
        foreach ($manager->getProviders() as $p) {
            $providers[] = [
                'id'   => $p->getId(),
                'name' => $p->getName(),
            ];
        }

        return $this->success('ok', [
            'provider_count' => count($providers),
            'providers'      => $providers,
        ]);
    }

    /**
     * 注册所有内置搜索提供者
     */
    private function registerProviders(SearchService $manager): void
    {
        $manager->registerMany([
            new MenuSearchProvider(),
            new ConfigSearchProvider(),
        ]);


        $this->registerProvidersFromExtPoint($manager);
    }

    /**
     * 从 SEARCH_PROVIDER 扩展点发现第三方搜索提供者
     *
     * 第三方按规则投递即可将全局搜索后端替换/扩展为 ES、Meilisearch 等：
     * <pre>
     * ExtPoint::register(ExtPoints::SEARCH_PROVIDER, 'meilisearch', MySearchProvider::class);
     * </pre>
     */
    private function registerProvidersFromExtPoint(SearchService $manager): void
    {
        if (!class_exists(ExtPoint::class) || !ExtPoint::defined(ExtPoints::SEARCH_PROVIDER)) {
            return;
        }

        foreach (ExtPoint::all(ExtPoints::SEARCH_PROVIDER) as $name => $item) {
            if (isset($item['active']) && !$item['active']) {
                continue;
            }

            try {
                $provider = $this->instantiateSearchProvider($item['concrete'] ?? null);
                if ($provider !== null) {
                    $manager->register($provider);
                }
            } catch (\Throwable $e) {
                ContextualLogger::warning("发现搜索提供者 [{$name}] 失败: " . $e->getMessage());
            }
        }
    }

    /**
     * 实例化搜索提供者（类名 / 闭包 / 实例），不符合契约返回 null
     */
    private function instantiateSearchProvider(mixed $concrete): ?SearchProviderInterface
    {
        try {
            if ($concrete instanceof SearchProviderInterface) {
                $instance = $concrete;
            } elseif (is_string($concrete) && class_exists($concrete)) {
                $instance = app($concrete);
            } elseif (is_callable($concrete)) {
                $instance = $concrete();
            } else {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $instance instanceof SearchProviderInterface ? $instance : null;
    }
}
