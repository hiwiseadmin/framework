<?php
namespace plugin\wisecms\controller\index;

use plugin\wisecms\service\content\ContentService;

/**
 * CMS 前台首页
 *
 * 侧栏「热门内容 / 标签云」已改由模板标签 `{cms:arclist flag="hot"}` / `{cms:tags}` 自助取数
 * （C-3 模板标签库），控制器不再为这两个区块做 assign。
 */
class Index extends Base
{
    protected ContentService $contentService;

    protected function initPlugin(): void
    {
        parent::initPlugin();
        $this->contentService = new ContentService();
    }

    public function index()
    {
        $limit = (int) $this->getPluginConfig('list_rows', 15);
        $page  = max(1, (int) $this->request->param('page', 1));

        // 主列表需要分页，仍由控制器取数（模板用 $latest.data + {cms:page}）
        $latest = $this->withUrls($this->contentService->getList([
            'site_id' => $this->siteId,
            'status'  => ContentService::STATUS_PUBLISHED,
            'page'    => $page,
            'limit'   => $limit,
            'order'   => 'create_time desc',
        ]));

        // 首页规范 URL
        $this->canonicalize($this->urls()->home());

        $this->assign([
            'latest' => $latest,
            'pager'  => $this->pager(
                (int) ($latest['total'] ?? 0),
                $page,
                (int) ($latest['per_page'] ?? $limit),
                [],
                $this->urls()->absolute('cms') . '?page=__PAGE__'
            ),
        ]);

        return $this->fetch('index/index');
    }
}
