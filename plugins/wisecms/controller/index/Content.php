<?php
namespace plugin\wisecms\controller\index;

use plugin\wisecms\model\Content as ContentModel;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\content\RecommendService;
use plugin\wisecms\service\model\FieldRegistry;
use plugin\wisecms\service\seo\OgImageService;
use plugin\wisecms\service\seo\StructuredDataService;
use think\facade\Db;

/**
 * CMS 前台内容详情页
 */
class Content extends Base
{
    protected ContentService $contentService;

    protected function initPlugin(): void
    {
        parent::initPlugin();
        $this->contentService = new ContentService();
    }

    public function detail()
    {
        $id   = (int) $this->request->param('id', 0);
        $slug = trim((string) $this->request->param('slug', ''));

        if ($id > 0) {
            $content = $this->contentService->getDetailOutput($id);
        } elseif ($slug !== '') {
            $found = ContentModel::where('slug', $slug)->find();
            $content = $found ? $this->contentService->getDetailOutput((int) $found->id) : null;
        } else {
            $content = null;
        }

        if (!$content || (int) $content['status'] !== ContentService::STATUS_PUBLISHED) {
            $this->abort404('内容不存在或未发布');
        }

        // 多站点隔离：内容必须属于当前站点
        if ((int) ($content['site_id'] ?? 1) !== $this->siteId) {
            $this->abort404('内容不存在或未发布');
        }

        return $this->renderDetail($content, ['preview' => false]);
    }

    /**
     * 装配详情页数据并渲染
     *
     * 前台详情与预览（controller/index/Preview）共用本方法，保证预览页与正式页
     * 外观、OG / 结构化数据 / 自定义字段 / 推荐位完全一致。
     *
     * @param array $content getDetailOutput() 结果
     * @param array $options ['preview' => bool, 'expires_at' => int]
     * @return mixed
     */
    protected function renderDetail(array $content, array $options = []): mixed
    {
        $preview = !empty($options['preview']);

        // 规范 URL：预览页不做 301 归一（预览 URL 本身就不是规范 URL）
        if (!$preview) {
            $this->canonicalize($this->urls()->content($content));

            // 浏览量自增：预览不计数（避免运营/客户预览污染 PV）
            ContentModel::where('id', (int) $content['id'])->update([
                'view_count' => Db::raw('view_count+1'),
            ]);
        }

        // 自定义字段列表（供模板渲染）
        $customFields = [];
        if ((int) ($content['model_id'] ?? 0) > 0) {
            $customFields = (new FieldRegistry())->getCustomFields((int) $content['model_id']);
        }

        // SEO 结构化数据 + OG 标签（接线 StructuredDataService / OgImageService）
        $contentModel   = ContentModel::find((int) $content['id']);
        $structuredData = $contentModel ? (new StructuredDataService())->generateForContent($contentModel) : '';
        $ogTags         = $contentModel ? (new OgImageService())->generateOgTags($contentModel) : '';

        $prev = $this->neighbour((int) $content['catid'], (int) $content['id'], '<', 'desc');
        $next = $this->neighbour((int) $content['catid'], (int) $content['id'], '>', 'asc');

        // 标签附前台 URL
        if (!empty($content['tags']) && is_array($content['tags'])) {
            $content['tags'] = $this->withEntityUrls($content['tags'], 'tag');
        }

        // 推荐位：相关阅读 / 热门 / 编辑推荐（缓存与降级由 RecommendService 内部处理）
        // 种子标签直接复用 $content['tags']，不再回查标签关联表
        $recommend = (new RecommendService())->forDetail($content, [
            'related' => (int) $this->getPluginConfig('recommend_related_limit', 8),
            'hot'     => (int) $this->getPluginConfig('recommend_hot_limit', 8),
            'picked'  => (int) $this->getPluginConfig('recommend_picked_limit', 8),
        ]);

        $this->assign([
            'content'        => $content,
            'customFields'   => $customFields,
            'structuredData' => $structuredData,
            'ogTags'         => $ogTags,
            'categoryUrl'    => !empty($content['category']['id']) ? $this->urls()->category($content['category']) : '',
            'prev'           => $prev,
            'next'           => $next,
            'prevUrl'        => $prev ? $this->urls()->content($prev) : '',
            'nextUrl'        => $next ? $this->urls()->content($next) : '',
            'recommend'      => $recommend,
            'isPreview'      => $preview,
            'previewExpire'  => (int) ($options['expires_at'] ?? 0),
        ]);

        $html = $this->fetch('index/detail');

        if (!$preview) {
            return $html;
        }

        // 预览页：显式声明不被搜索引擎收录（配合模板 noindex meta）
        return response($html, 200, ['X-Robots-Tag' => 'noindex, nofollow, noarchive']);
    }

    /**
     * 获取上一篇/下一篇
     */
    protected function neighbour(int $catid, int $id, string $op, string $order): ?ContentModel
    {
        return ContentModel::where('delete_time', null)
            ->where('status', ContentService::STATUS_PUBLISHED)
            ->where('site_id', $this->siteId)
            ->where('catid', $catid)
            ->where('id', $op, $id)
            ->order('id', $order)
            ->find();
    }
}
