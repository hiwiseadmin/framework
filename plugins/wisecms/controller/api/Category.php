<?php
namespace plugin\wisecms\controller\api;

use plugin\ApiController;
use plugin\wisecms\service\content\CategoryService;
/**
 * CMS 栏目 API 控制器
 */
class Category extends ApiController
{
    protected $middleware = [
        \wise\api\middleware\RateLimitMiddleware::class . ':60,1',
    ];

    public function tree()
    {
        $params = $this->validateRequest(['site_id' => 'integer|egt:1']);
        $siteId = (int)($params['site_id'] ?? 1);
        $svc = new CategoryService();
        $tree = $svc->getTree($siteId);
        return $this->success('ok', $tree);
    }

    public function detail()
    {
        $params = $this->validateRequest(['id' => 'require|integer|egt:1']);
        $cat = \plugin\wisecms\model\Category::find((int)$params['id']);
        if (!$cat) return $this->error('栏目不存在', [], 4001);
        return $this->success('ok', $cat->toArray());
    }
}
