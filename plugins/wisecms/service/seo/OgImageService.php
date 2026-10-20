<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\model\Content;
use plugin\wisecms\service\url\UrlService;

/**
 * OG/Twitter 社交分享标签生成
 */
class OgImageService
{
    /**
     * 为内容生成 OG 标签 HTML
     */
    public function generateOgTags(Content $content): string
    {
        $siteName = config('wisecms.site_name', '');
        $image = $this->resolveOgImage($content);
        $url = $this->resolveUrl($content);

        $tags = [];
        $tags[] = '<meta property="og:title" content="' . htmlspecialchars($content->title) . '">';
        $tags[] = '<meta property="og:description" content="' . htmlspecialchars($content->description ?: $content->title) . '">';
        $tags[] = '<meta property="og:type" content="article">';
        $tags[] = '<meta property="og:url" content="' . htmlspecialchars($url) . '">';
        $tags[] = '<meta property="og:site_name" content="' . htmlspecialchars($siteName) . '">';
        $tags[] = '<meta property="og:locale" content="zh_CN">';

        if ($image) {
            $tags[] = '<meta property="og:image" content="' . htmlspecialchars($image) . '">';
            $tags[] = '<meta property="og:image:width" content="1200">';
            $tags[] = '<meta property="og:image:height" content="630">';
        }

        // Twitter Card
        $tags[] = '<meta name="twitter:card" content="' . ($image ? 'summary_large_image' : 'summary') . '">';
        $tags[] = '<meta name="twitter:title" content="' . htmlspecialchars($content->title) . '">';
        $tags[] = '<meta name="twitter:description" content="' . htmlspecialchars($content->description ?: $content->title) . '">';
        if ($image) {
            $tags[] = '<meta name="twitter:image" content="' . htmlspecialchars($image) . '">';
        }

        return implode("\n", $tags);
    }

    /**
     * 解析 OG:Image
     * 优先级: 手动设置 > 内容缩略图 > 第一张内容图 > 站点默认
     */
    protected function resolveOgImage(Content $content): string
    {
        if (!empty($content->og_image)) return $content->og_image;
        if (!empty($content->thumb)) return $content->thumb;
        
        // 从内容中提取第一张图片（DOM 优先，可正确处理属性值含 '>' 等情形）
        $firstImage = SeoHtmlParser::firstImageSrc((string) ($content->content ?? ''));
        if ($firstImage !== '') {
            return $firstImage;
        }
        
        // 站点默认 OG 图
        return config('wisecms.default_og_image', '');
    }

    protected function resolveUrl(Content $content): string
    {
        if (!empty($content->url)) return $content->url;
        if (!empty($content->canonical_url)) return $content->canonical_url;
        // 统一由 UrlService 生成（伪静态开启时输出规则 URL）
        return (new UrlService())->content($content);
    }
}
