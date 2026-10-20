<?php
namespace plugin\wisecms\controller\index;

use plugin\wisecms\model\Content as ContentModel;
use plugin\wisecms\model\Tag as TagModel;
use plugin\wisecms\model\TagRelation;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\content\TagService;

/**
 * CMS 前台标签页
 *
 * 无参数时展示标签云，传入 id/name 时展示该标签下的内容列表。
 *
 * P2 标签站点隔离：标签本体（id/name 查找）与关联内容都限定在**当前站点**，
 * 否则 `?id=他站标签id` 可直接打开他站标签页并泄露标签名（内容侧原先已有站点条件）。
 */
class Tag extends Base
{
    protected TagService $tagService;

    protected function initPlugin(): void
    {
        parent::initPlugin();
        $this->tagService = new TagService();
    }

    public function index()
    {
        $id   = (int) $this->request->param('id', 0);
        $name = trim((string) $this->request->param('name', ''));

        $tag = null;
        if ($id > 0) {
            $tag = TagModel::where('id', $id)->where('site_id', $this->siteId)->find();
        } elseif ($name !== '') {
            $tag = TagModel::where('name', $name)->where('site_id', $this->siteId)->find();
        }

        // 未指定标签：展示标签云
        if (!$tag) {
            $this->assign([
                'tag'   => null,
                'tags'  => $this->withEntityUrls($this->tagService->siteCloud($this->siteId, 50), 'tag'),
                'list'  => null,
                'pager' => null,
            ]);
            return $this->fetch('index/tag');
        }

        $page  = max(1, (int) $this->request->param('page', 1));
        $limit = (int) $this->getPluginConfig('list_rows', 15);

        $contentIds = TagRelation::where('site_id', $this->siteId)->where('tag_id', $tag->id)->column('content_id');

        if (empty($contentIds)) {
            $list = ['total' => 0, 'per_page' => $limit, 'current_page' => 1, 'last_page' => 1, 'data' => []];
        } else {
            $list = $this->withUrls(ContentModel::with(['category'])
                ->where('delete_time', null)
                ->where('status', ContentService::STATUS_PUBLISHED)
                ->where('site_id', $this->siteId)
                ->whereIn('id', $contentIds)
                ->order('create_time', 'desc')
                ->paginate(['page' => $page, 'list_rows' => $limit])
                ->toArray());
        }

        // 规范 URL
        $this->canonicalize($this->urls()->tag($tag));

        $this->assign([
            'tag'   => $tag,
            'tags'  => $this->withEntityUrls($this->tagService->siteCloud($this->siteId, 50), 'tag'),
            'list'  => $list,
            'pager' => $this->pager(
                (int) ($list['total'] ?? 0),
                $page,
                (int) ($list['per_page'] ?? $limit),
                [],
                $this->urls()->paginationTemplate('tag', $tag)
            ),
        ]);

        return $this->fetch('index/tag');
    }
}
