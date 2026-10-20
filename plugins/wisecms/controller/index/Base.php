<?php
namespace plugin\wisecms\controller\index;

use plugin\FrontController;
use plugin\wisecms\service\content\CategoryService;
use plugin\wisecms\service\content\FriendLinkService;
use plugin\wisecms\service\site\SiteService;
use plugin\wisecms\service\url\UrlRuleService;
use plugin\wisecms\service\url\UrlService;
use think\exception\HttpException;
use think\exception\HttpResponseException;

/**
 * CMS 前台控制器基类
 *
 * 统一提供：站点配置、栏目导航、前台 URL 生成（统一委托 UrlService）、
 * 规范 URL（canonical）与伪静态 301 归一、分页数据封装。
 * URL 形态: /cms/{controller}/{action}（伪静态由 service/url/UrlService 生成）
 *
 * 继承链: app\BaseController → app\common\controller\FrontController → plugin\FrontController → 本类
 */
abstract class Base extends FrontController
{
    /**
     * 前台路由前缀（与 route/index.php 中的规则前缀保持一致）
     */
    protected string $frontBase = '/cms';

    protected CategoryService $categoryService;

    protected SiteService $siteService;

    /** 当前站点 ID（由 SiteService 解析） */
    protected int $siteId = 1;

    /** URL 服务（请求内复用，保留栏目路径缓存） */
    protected ?UrlService $urls = null;

    protected function initPlugin(): void
    {
        $this->categoryService = new CategoryService();
        $this->siteService     = new SiteService();
        $this->siteId          = $this->siteService->resolveCurrentSiteId();
        $this->assignFrontData();
    }

    /**
     * 注入前台公共模板变量
     */
    protected function assignFrontData(): void
    {
        // P1-30：站点级设置（wisecms_site.setting / seo_setting）优先于插件全局配置，
        // 多站点下各站独立 —— 此前这两列「只写没人读」，站点名一直回退到插件全局配置
        $siteSetting = $this->siteService->getSiteSetting($this->siteId);

        $this->assign('cms', [
            'base'        => $this->frontBase,
            'site_id'     => $this->siteId,
            'site_name'   => $siteSetting['site_name'] ?? $this->getPluginConfig('site_name', 'WiseCMS'),
            // 站点级 SEO 默认值（模板 meta 用 |default 兜底：实体级 > 站点级 > 空）
            'seo'         => $this->siteService->getSiteSeoSetting($this->siteId),
            'home_url'    => $this->urls()->home(),
            'tag_url'     => $this->urls()->absolute('cms/tag/index'),
            'search_url'  => $this->urls()->absolute('cms/search/index'),
            // 订阅入口（模板 <link rel="alternate"> 与页脚 RSS 链接）
            'feed_url'    => $this->urls()->absolute('cms/feed/index'),
            // 友情链接（B-1）：页脚直出，缓存与降级由 FriendLinkService 内部处理
            'friend_links' => $this->friendLinks(),
            // 独立友链页入口
            'friend_link_page_url' => $this->urls()->absolute('cms/link/index'),
            // 导航树，每个节点附前台 URL（伪静态开启时为规则 URL）
            'nav'         => $this->withCategoryUrls($this->categoryService->getTree($this->siteId)),
        ]);
    }

    /**
     * 页脚友链（B-1）
     *
     * 全部异常兜底为空数组：友链属页脚装饰区块，表未迁移 / 缓存故障
     * 都不应让整页 500（详见 docs/wisecms-friendlink-design.md §7.2）。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function friendLinks(): array
    {
        try {
            if (!$this->getPluginConfig('friendlink_enable', true)) {
                return [];
            }

            $max = (int) $this->getPluginConfig('friendlink_max', 50);

            return (new FriendLinkService())->forFront($this->siteId, '', $max > 0 ? $max : 0);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 为栏目树（递归）补充前台 URL
     */
    protected function withCategoryUrls(array $tree): array
    {
        $service = $this->urls();
        foreach ($tree as &$node) {
            if (!is_array($node)) {
                continue;
            }
            $node['url'] = $service->category($node);
            if (!empty($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->withCategoryUrls($node['children']);
            }
        }
        unset($node);

        return $tree;
    }

    /**
     * 为同类实体列表补充前台 URL
     *
     * @param string $type category|tag|page|content
     */
    protected function withEntityUrls(array $items, string $type): array
    {
        $service = $this->urls();
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $item['url'] = match ($type) {
                'category' => $service->category($item),
                'tag'      => $service->tag($item),
                'page'     => $service->page($item),
                default    => $service->content($item),
            };
        }
        unset($item);

        return $items;
    }

    /**
     * URL 服务实例（请求内复用）
     */
    protected function urls(): UrlService
    {
        return $this->urls ??= new UrlService();
    }

    /**
     * 生成前台 URL
     */
    protected function frontUrl(string $path, array $params = []): string
    {
        $url = $this->frontBase . '/' . ltrim($path, '/');
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $url;
    }

    /**
     * 为内容列表补充前台 URL（伪静态开启时输出规则 URL）
     *
     * 同时兼容分页结构（['data' => [...]]）与纯列表（[item, item...]）。
     */
    protected function withUrls(array $list): array
    {
        $service = $this->urls();

        // 分页结构
        if (!empty($list['data']) && is_array($list['data'])) {
            foreach ($list['data'] as &$item) {
                if (is_array($item) && empty($item['url'])) {
                    $item['url'] = $service->content($item);
                }
            }
            unset($item);

            return $list;
        }

        // 纯列表
        foreach ($list as &$item) {
            if (is_array($item) && !empty($item['id']) && empty($item['url'])) {
                $item['url'] = $service->content($item);
            }
        }
        unset($item);

        return $list;
    }

    /**
     * 设置规范 URL（canonical）
     *
     * 伪静态开启且当前访问路径不是规范路径时（如用 ?id= 访问、尾斜杠差异），
     * 返回 301 归一，避免同内容多 URL 稀释权重。
     */
    protected function canonicalize(string $canonicalUrl): void
    {
        if ($canonicalUrl === '') {
            return;
        }

        $this->assign('canonical', $canonicalUrl);

        if (!$this->urls()->rewriteEnabled()) {
            return;
        }

        // 仅对「伪静态规范 URL」归一：查询串兜底路径不参与，避免跳转异常
        $canonicalPath = UrlRuleService::normalizePath((string) parse_url($canonicalUrl, PHP_URL_PATH));
        if ($canonicalPath === '' || str_starts_with($canonicalPath, 'cms/')) {
            return;
        }

        $currentPath = UrlRuleService::normalizePath((string) $this->request->pathinfo());
        if (rtrim($canonicalPath, '/') === rtrim($currentPath, '/')) {
            return;
        }

        throw new HttpResponseException(redirect($canonicalUrl, 301));
    }

    /**
     * 构建分页数据
     *
     * @param int    $total        总条数
     * @param int    $page         当前页
     * @param int    $limit        每页条数
     * @param array  $extra        需要保留的额外查询参数
     * @param string $urlTemplate  分页 URL 模板（含 __PAGE__ 占位符）；空则用当前路径 + 查询串
     * @return array
     */
    protected function pager(int $total, int $page, int $limit, array $extra = [], string $urlTemplate = ''): array
    {
        $limit     = $limit > 0 ? $limit : 15;
        $lastPage  = (int) max(1, ceil($total / $limit));
        $page      = min(max(1, $page), $lastPage);
        $extra['page'] = '__PAGE__';

        if ($urlTemplate === '') {
            $urlTemplate = $this->request->baseUrl() . '?' . http_build_query($extra);
        }

        return [
            'total'      => $total,
            'page'       => $page,
            'limit'      => $limit,
            'last_page'  => $lastPage,
            'has_prev'   => $page > 1,
            'has_next'   => $page < $lastPage,
            'prev'       => max(1, $page - 1),
            'next'       => min($lastPage, $page + 1),
            // 分页链接模板，占位符 __PAGE__ 由模板替换为具体页码
            'url'        => $urlTemplate,
        ];
    }

    /**
     * 中断并返回 404
     */
    protected function abort404(string $message = '页面不存在'): void
    {
        throw new HttpException(404, $message);
    }
}
