<?php
namespace plugin\wisecms\controller\api;

use plugin\wisecms\service\content\SearchService;
/**
 * CMS 搜索 API 控制器
 *
 * P1-D：搜索与热搜词均限定在当前站点。
 */
class Search extends Base
{
    /**
     * 中间件：限流（60次/分钟，令牌桶：容量60, 速率1/s）
     */
    protected $middleware = [
        \wise\api\middleware\RateLimitMiddleware::class . ':60,1',
    ];

    /**
     * 搜索
     */
    public function index()
    {
        $params = $this->validateRequest([
            'q'     => 'max:200',
            'catid' => 'integer',
            'page'  => 'integer|egt:1',
            'limit' => 'integer|between:1,100',
        ]);

        $filters = [
            'catid' => (int)($params['catid'] ?? 0),
            'page'  => (int)($params['page'] ?? 1),
            'limit' => (int)($params['limit'] ?? 15),
            // P1-D：站点隔离（原实现 site_id=0 → 引擎跳过站点条件，搜索跨站返回）
            'site_id' => $this->siteId(),
        ];

        $svc = new SearchService();
        $result = $svc->search($params['q'] ?? '', $filters);
        return $this->success('ok', $result);
    }

    /**
     * 热搜词
     */
    public function hot()
    {
        $params = $this->validateRequest([
            'limit' => 'integer|between:1,50',
        ]);

        $svc = new SearchService();
        // P1-D：热搜词按站点统计（原实现全站合并，暴露他站搜索词）
        $hot = $svc->getHotKeywords((int)($params['limit'] ?? 10), $this->siteId());
        return $this->success('ok', $hot);
    }
}
