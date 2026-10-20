<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\model\Category;
use plugin\wisecms\model\Content;
use plugin\wisecms\model\Tag;
use plugin\wisecms\model\Page;
use plugin\wisecms\service\url\UrlService;
use think\facade\Cache;

/**
 * 站点地图生成服务
 */
class SitemapService
{
    protected string $siteUrl;

    /** URL 服务（统一生成前台 URL） */
    protected ?UrlService $urls = null;

    public function __construct()
    {
        // config/app.php 中 site_url 默认为空串（键存在），config($key, $default) 不会回退 $default，
        // 必须显式判空，否则 sitemap 中的 loc 会丢失域名
        $siteUrl = (string) config('wisecms.site_url', '');
        $this->siteUrl = rtrim($siteUrl !== '' ? $siteUrl : (string) request()->domain(), '/');
    }

    protected function urls(): UrlService
    {
        return $this->urls ??= new UrlService();
    }

    /**
     * 生成站点地图索引
     */
    public function generateSitemapIndex(int $siteId = 1): string
    {
        $contentCount = Content::where('site_id', $siteId)->where('status', 2)->whereNull('delete_time')->count();
        $perFile = config('wisecms.sitemap_limit', 1000);
        $fileCount = ceil($contentCount / $perFile);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        $xml .= $this->sitemapEntry($this->siteUrl . '/sitemap-pages.xml', time());
        for ($i = 1; $i <= $fileCount; $i++) {
            $xml .= $this->sitemapEntry($this->siteUrl . "/sitemap-content-{$i}.xml", time());
        }
        $xml .= '</sitemapindex>';
        return $xml;
    }

    /**
     * 生成页面站点地图（栏目+单页+标签页）
     */
    public function generatePagesSitemap(int $siteId = 1): string
    {
        $urls = [];
        // 首页
        $urls[] = $this->urlEntry($this->siteUrl . '/', time(), 'daily', '1.0');
        // 栏目
        $categories = Category::where('site_id', $siteId)->where('status', 1)->select();
        foreach ($categories as $cat) {
            $urls[] = $this->urlEntry($this->urls()->category($cat), time(), 'daily', '0.9');
        }
        // 标签（P2 标签站点隔离：只收录本站热门标签，原实现按全站 count 取 → 他站标签进入本站 sitemap）
        $tags = Tag::where('site_id', $siteId)->where('count', '>', 5)->order('count', 'desc')->limit(100)->select();
        foreach ($tags as $tag) {
            $urls[] = $this->urlEntry($this->urls()->tag($tag), time(), 'weekly', '0.6');
        }
        return $this->buildSitemap($urls);
    }

    /**
     * 生成内容站点地图
     */
    public function generateContentSitemap(int $page = 1, int $siteId = 1): string
    {
        $perFile = config('wisecms.sitemap_limit', 1000);
        $contents = Content::where('site_id', $siteId)
            ->where('status', 2)
            ->whereNull('delete_time')
            ->order('create_time', 'desc')
            ->page($page, $perFile)
            ->select();

        $urls = [];
        foreach ($contents as $c) {
            $priority = '0.6';
            if ($c->is_top) $priority = '0.9';
            elseif ($c->is_recommend) $priority = '0.8';

            // lastmod 取最后更新时间，缺失回退创建时间（均为 DATETIME）
            $lastmod = $c->update_time ?: $c->create_time;

            $urls[] = $this->urlEntry(
                $this->urls()->content($c),
                $lastmod,
                cms_timestamp($lastmod) > time() - 86400 ? 'daily' : 'monthly',
                $priority
            );
        }
        return $this->buildSitemap($urls);
    }

    protected function buildSitemap(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $xml .= implode("\n", $urls);
        $xml .= "\n</urlset>";
        return $xml;
    }

    /**
     * @param mixed $lastmod 最后修改时间：DATETIME 字符串 / unix 时间戳 / null 均可
     *                       （时间字段统一后由 cms_timestamp() 收敛，缺省用当天）
     */
    protected function urlEntry(string $loc, $lastmod, string $changefreq, string $priority): string
    {
        return sprintf(
            '  <url><loc>%s</loc><lastmod>%s</lastmod><changefreq>%s</changefreq><priority>%s</priority></url>',
            htmlspecialchars($loc),
            date('Y-m-d', cms_timestamp($lastmod) ?: time()),
            $changefreq,
            $priority
        );
    }

    /**
     * @param mixed $lastmod 同 urlEntry()
     */
    protected function sitemapEntry(string $loc, $lastmod): string
    {
        return sprintf(
            '  <sitemap><loc>%s</loc><lastmod>%s</lastmod></sitemap>',
            htmlspecialchars($loc),
            date('Y-m-d', cms_timestamp($lastmod) ?: time())
        );
    }
}
