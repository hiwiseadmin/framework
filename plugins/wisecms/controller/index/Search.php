<?php
namespace plugin\wisecms\controller\index;

use plugin\wisecms\service\content\SearchService;

/**
 * CMS 前台搜索页
 */
class Search extends Base
{
    protected SearchService $searchService;

    protected function initPlugin(): void
    {
        parent::initPlugin();
        $this->searchService = new SearchService();
    }

    public function index()
    {
        $keyword = trim((string) $this->request->param('keyword', ''));
        $catid   = (int) $this->request->param('catid', 0);
        $page    = max(1, (int) $this->request->param('page', 1));
        $limit   = (int) $this->getPluginConfig('list_rows', 15);

        $result = ['list' => [], 'total' => 0];

        if ($keyword !== '') {
            $result = $this->searchService->search($keyword, [
                'site_id' => $this->siteId,
                'catid'   => $catid,
                'page'    => $page,
                'limit'   => $limit,
            ]);
        }

        $this->assign([
            'keyword' => $keyword,
            'catid'   => $catid,
            'list'    => $this->withUrls($result['list'] ?? []),
            'total'   => (int) ($result['total'] ?? 0),
            'hot'     => $this->searchService->getHotKeywords(10),
            'pager'   => $this->pager((int) ($result['total'] ?? 0), $page, $limit, [
                'keyword' => $keyword,
                'catid'   => $catid,
            ]),
        ]);

        return $this->fetch('index/search');
    }
}
