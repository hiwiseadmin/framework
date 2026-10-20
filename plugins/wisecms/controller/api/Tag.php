<?php
namespace plugin\wisecms\controller\api;

use plugin\wisecms\service\content\TagService;
/**
 * CMS 标签 API 控制器
 *
 * P1-D：标签词表全站共享（`wisecms_tag` 无 `site_id`，属有意设计），
 * 但**引用数与内容列表必须按当前站点统计**，否则 A 站的标签云会暴露 B 站的内容规模与标题。
 */
class Tag extends Base
{
    protected $middleware = [
        \wise\api\middleware\RateLimitMiddleware::class . ':60,1',
    ];

    public function index()
    {
        $params = $this->validateRequest([
            'page'  => 'integer|egt:1',
            'limit' => 'integer|between:1,100',
        ]);

        $page = (int)($params['page'] ?? 1);
        $limit = (int)($params['limit'] ?? 20);

        // 站点维度标签云（引用数只统计本站已发布内容）
        $list = (new TagService())->siteCloud($this->siteId(), $limit, $page);
        return $this->success('ok', $list);
    }

    public function cloud()
    {
        $params = $this->validateRequest(['limit' => 'integer|between:1,200']);
        $limit = (int)($params['limit'] ?? 50);

        $tags = (new TagService())->siteCloud($this->siteId(), $limit);
        return $this->success('ok', $tags);
    }

    public function content()
    {
        $params = $this->validateRequest([
            'tag_id' => 'require|integer|egt:1',
            'page'   => 'integer|egt:1',
            'limit'  => 'integer|between:1,100',
        ]);

        $tagId = (int)$params['tag_id'];
        $page = (int)($params['page'] ?? 1);
        $limit = (int)($params['limit'] ?? 15);

        // P1-D：标签下的内容限本站 + 已发布 + 未删除；total 按真实条件统计
        //（原实现用 count($contentIds) 作为总数，既忽略状态也不分站点，分页数与实际不符）
        $result = (new TagService())->siteContentList($tagId, $this->siteId(), $page, $limit);

        return $this->success('ok', [
            'list'  => $result['list'],
            'total' => $result['total'],
            'page'  => $page,
            'limit' => $limit,
        ]);
    }
}
