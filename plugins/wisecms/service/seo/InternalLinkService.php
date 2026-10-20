<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\model\InternalLink;
use plugin\wisecms\service\url\UrlService;

/**
 * 内链服务
 */
class InternalLinkService
{
    /**
     * 获取所有内链规则
     */
    public function getList(int $siteId = 0): array
    {
        $query = InternalLink::where('status', 1);
        if ($siteId > 0) $query->where('site_id', $siteId);
        return $query->select()->toArray();
    }

    /**
     * 创建内链规则
     */
    public function create(string $keyword, string $targetUrl, string $targetTitle = '', int $siteId = 0): InternalLink
    {
        return InternalLink::create([
            'keyword' => $keyword, 'target_url' => $targetUrl,
            'target_title' => $targetTitle, 'site_id' => $siteId, 'status' => 1,
        ]);
    }

    /**
     * 删除内链
     */
    public function delete(int $id): bool
    {
        $link = InternalLink::find($id);
        if ($link) $link->delete();
        return true;
    }

    /**
     * 从内容库自动构建内链表
     */
    public function rebuildFromContent(int $siteId = 0): int
    {
        $content = \plugin\wisecms\model\Content::where('status', 2)->whereNull('delete_time');
        if ($siteId > 0) $content->where('site_id', $siteId);
        $contents = $content->select();

        $count = 0;
        $urlService = new UrlService();
        foreach ($contents as $c) {
            $kw = $c->keywords;
            if (empty($kw)) continue;
            foreach (explode(',', $kw) as $keyword) {
                $keyword = trim($keyword);
                if (mb_strlen($keyword) < 2) continue;
                $exists = InternalLink::where('keyword', $keyword)->where('site_id', $siteId)->find();
                if ($exists) continue;
                
                InternalLink::create([
                    'keyword' => $keyword,
                    'target_url' => $urlService->content($c),
                    'target_title' => $c->title,
                    'site_id' => $siteId,
                    'status' => 1,
                ]);
                $count++;
            }
        }
        return $count;
    }
}
