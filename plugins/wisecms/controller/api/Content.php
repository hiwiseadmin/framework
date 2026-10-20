<?php
namespace plugin\wisecms\controller\api;

use plugin\wisecms\service\content\ContentService;
/**
 * CMS 内容 API 控制器
 *
 * P1-D：全部 action 限定在当前站点（站点由域名解析，不接受 `?site_id=`）。
 */
class Content extends Base
{
    protected $middleware = [
        \wise\api\middleware\RateLimitMiddleware::class . ':60,1',
    ];

    public function index()
    {
        $params = $this->validateRequest([
            'catid'  => 'integer',
            'status' => 'in:0,1,2,3',
            'page'   => 'integer|egt:1',
            'limit'  => 'integer|between:1,100',
            'order'  => 'max:100',
        ]);

        // 对外列表接口不暴露非公开状态：匿名/普通调用方仅能取到已发布内容，
        // 显式传入 0/1/3 时一律回落为 2（已发布）
        $status = (int)($params['status'] ?? 2);
        if ($status !== 2) {
            $status = 2;
        }

        $listParams = [
            'catid'   => (int)($params['catid'] ?? 0),
            'status'  => $status,
            'page'    => (int)($params['page'] ?? 1),
            'limit'   => (int)($params['limit'] ?? 15),
            'order'   => $params['order'] ?? 'create_time desc',
            // P1-D：站点隔离（原实现未传 site_id → 列表跨站返回）
            'site_id' => $this->siteId(),
        ];

        $svc = new ContentService();
        $list = $svc->getList($listParams);
        return $this->success('ok', $list);
    }

    public function detail()
    {
        $params = $this->validateRequest(['id' => 'require|integer|egt:1']);

        // 对外 API 仅暴露「本站点 + 已发布 + 未删除」内容：
        // P1-D 补站点条件（原实现只校验状态，遍历 id 即可读到其它站点的已发布内容）
        $content = $this->findSiteContent((int) $params['id']);

        if (!$content) return $this->error('内容不存在', [], 4001);
        return $this->success('ok', $content->toArray());
    }

    public function hot()
    {
        $params = $this->validateRequest(['limit' => 'integer|between:1,50']);
        $limit = (int)($params['limit'] ?? 10);
        $list = \plugin\wisecms\model\Content::where('site_id', $this->siteId())
            ->where('status', 2)
            ->whereNull('delete_time')
            ->order('view_count', 'desc')
            ->limit($limit)
            ->select()->toArray();
        return $this->success('ok', $list);
    }

    public function related()
    {
        $params = $this->validateRequest([
            'id'    => 'require|integer|egt:1',
            'limit' => 'integer|between:1,20',
        ]);

        $id = (int)$params['id'];
        $limit = (int)($params['limit'] ?? 5);
        // P1-D：种子内容必须属于当前站点（原实现任意 id 均可作种子，借关键词外泄他站标题）
        $content = $this->findSiteContent($id);
        if (!$content) return $this->error('内容不存在', [], 4001);

        $kw = $content->keywords;
        $list = [];
        if ($kw) {
            $keywords = explode(',', $kw);
            $list = \plugin\wisecms\model\Content::where('id', '<>', $id)
                ->where('site_id', $this->siteId())
                ->where('status', 2)->whereNull('delete_time')
                ->where(function($q) use ($keywords) {
                    foreach ($keywords as $k) {
                        $k = trim($k);
                        if ($k !== '') {
                            $k = addcslashes($k, '%_\\');
                            $q->whereOr('keywords', 'like', "%{$k}%");
                        }
                    }
                })->limit($limit)->select()->toArray();
        }
        return $this->success('ok', $list);
    }

    public function prevnext()
    {
        $params = $this->validateRequest([
            'id'    => 'require|integer|egt:1',
            'catid' => 'require|integer|egt:1',
        ]);

        $id = (int)$params['id'];
        $catid = (int)$params['catid'];

        // P1-D：种子内容须属于当前站点，且上下篇限本站（原实现按 catid 直查，跨站栏目 id 相同即互相串页）
        if (!$this->findSiteContent($id)) {
            return $this->error('内容不存在', [], 4001);
        }

        $siteId = $this->siteId();

        $prev = \plugin\wisecms\model\Content::where('site_id', $siteId)->where('catid', $catid)->where('id', '<', $id)
            ->where('status', 2)->whereNull('delete_time')->order('id', 'desc')->findOrEmpty();
        $next = \plugin\wisecms\model\Content::where('site_id', $siteId)->where('catid', $catid)->where('id', '>', $id)
            ->where('status', 2)->whereNull('delete_time')->order('id', 'asc')->findOrEmpty();

        return $this->success('ok', [
            'prev' => $prev->toArray(),
            'next' => $next->toArray()
        ]);
    }
}
