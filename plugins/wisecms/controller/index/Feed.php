<?php
namespace plugin\wisecms\controller\index;

use plugin\wisecms\model\Category;
use plugin\wisecms\model\Tag as TagModel;
use plugin\wisecms\service\content\FeedService;
use plugin\wisecms\service\site\SiteService;
use think\Response;

/**
 * CMS 前台订阅入口（RSS 2.0 / Atom 1.0）
 *
 * 路由（见 route/index.php，须位于伪静态 catch-all 之前）：
 *   /cms/feed/index               全站订阅
 *   /cms/feed/category?catid=N    栏目订阅
 *   /cms/feed/tag?id=N            标签订阅
 *
 * 设计要点（详见 docs/wisecms-feed-design.md）：
 *   · 重写 initPlugin() 跳过父类的导航树 / 栏目 URL 查询，仅解析当前站点；
 *   · 用 response() 返回（不沿用 api/Tool::sitemap() 的 header()+echo+exit，
 *     避免绕过 Response 与中间件，保证 X-Request-Id 等响应头正常下发）；
 *   · 不调用 canonicalize()（那是 HTML 页面语义）；
 *   · 范围实体不存在 / 非本站 / 未启用时 404；空结果返回合法 XML 而非 404。
 */
class Feed extends Base
{
    protected FeedService $feedService;

    /**
     * 刻意不调用 parent::initPlugin()：
     * feed 不需要导航树/栏目 URL 等前台公共数据，避免每个 feed 请求多查一次栏目树。
     */
    protected function initPlugin(): void
    {
        $this->siteService = new SiteService();
        $this->siteId      = $this->siteService->resolveCurrentSiteId();
        $this->feedService = new FeedService();
    }

    /**
     * 全站订阅
     */
    public function index(): Response
    {
        return $this->respond(FeedService::TYPE_SITE, 0);
    }

    /**
     * 栏目订阅（catid 必填，须属本站且启用）
     */
    public function category(): Response
    {
        $catid = (int) $this->request->param('catid', 0);
        if ($catid <= 0) {
            $this->abort404('栏目不存在');
        }

        $category = Category::where('id', $catid)
            ->where('site_id', $this->siteId)
            ->where('status', 1)
            ->find();
        if (!$category) {
            $this->abort404('栏目不存在');
        }

        return $this->respond(FeedService::TYPE_CATEGORY, $catid);
    }

    /**
     * 标签订阅（id 必填，须存在）
     */
    public function tag(): Response
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0 || !TagModel::find($id)) {
            $this->abort404('标签不存在');
        }

        return $this->respond(FeedService::TYPE_TAG, $id);
    }

    /**
     * 组装并输出 feed 响应
     */
    protected function respond(string $type, int $scopeId): Response
    {
        // 总开关（关闭时 feed 端点 404）
        if (!$this->getPluginConfig('feed_enable', true)) {
            $this->abort404('订阅功能未开启');
        }

        // 格式：?format=rss|atom 优先，非法回落配置值
        $format = strtolower(trim((string) $this->request->param('format', '')));
        if (!in_array($format, [FeedService::FORMAT_RSS, FeedService::FORMAT_ATOM], true)) {
            $format = (string) $this->getPluginConfig('feed_format', FeedService::FORMAT_RSS);
        }

        $xml = $this->feedService->build($type, $scopeId, $this->siteId, $format);

        return response($xml, 200, [
            'Content-Type' => FeedService::contentType($format) . '; charset=utf-8',
        ]);
    }
}
