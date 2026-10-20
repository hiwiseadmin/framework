<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\model\Content;
use plugin\wisecms\model\Category;
use plugin\wisecms\service\url\UrlService;

/**
 * JSON-LD 结构化数据生成
 */
class StructuredDataService
{
    /**
     * 生成内容的 JSON-LD 结构化数据
     */
    public function generateForContent(Content $content): string
    {
        $siteName = config('wisecms.site_name', '我的网站');
        $siteUrl = config('wisecms.site_url', request()->domain());

        $type = $this->detectSchemaType($content);
        $data = [
            '@context' => 'https://schema.org',
            '@type' => $type,
            'headline' => $content->title,
            'description' => $content->description,
            // 发布时间优先取 publish_time（发布时写入），缺失回退 create_time
            'datePublished' => date('c', cms_timestamp($content->publish_time ?: $content->create_time) ?: time()),
            'dateModified' => date('c', cms_timestamp($content->update_time ?: $content->create_time) ?: time()),
            'author' => ['@type' => 'Person', 'name' => $content->username ?: 'Admin'],
            'publisher' => [
                '@type' => 'Organization',
                'name' => $siteName,
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => (new UrlService())->content($content),
            ],
        ];

        if (!empty($content->thumb)) {
            $data['image'] = [$content->thumb];
        }

        return '<script type="application/ld+json">' . $this->encode($data, JSON_PRETTY_PRINT) . '</script>';
    }

    /**
     * JSON-LD 安全编码（P1-A6）
     *
     * 本类的产物在模板中以 `|raw` 直出到 `<script type="application/ld+json">` 内，
     * 因此必须转义 HTML 敏感字符，防止内容里出现 `</script>` 提前闭合标签形成 XSS。
     *
     * 原实现只设 `JSON_UNESCAPED_UNICODE`：当前之所以安全，仅因为 json_encode
     * 默认会转义 `/`（未启用 JSON_UNESCAPED_SLASHES），`</script>` 被写成 `<\/script>`。
     * 这是**偶然安全**——一旦后续有人加上 JSON_UNESCAPED_SLASHES 即变成存储型 XSS。
     */
    protected function encode(array $data, int $extraFlags = 0): string
    {
        $flags = JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG    // < > → \u003C \u003E，彻底阻断 </script>
            | JSON_HEX_AMP    // & → \u0026
            | JSON_HEX_APOS   // ' → \u0027
            | JSON_HEX_QUOT   // " → \u0022
            | $extraFlags;

        return (string) json_encode($data, $flags);
    }

    /**
     * 生成面包屑结构化数据
     */
    public function generateBreadcrumb(array $items): string
    {
        $listItems = [];
        $pos = 1;
        foreach ($items as $item) {
            $listItems[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => $item['name'],
                'item' => $item['url'] ?? '',
            ];
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $listItems,
        ];
        return '<script type="application/ld+json">' . $this->encode($data) . '</script>';
    }

    /**
     * 生成站点结构化数据
     */
    public function generateSiteSchema(): string
    {
        $siteName = config('wisecms.site_name', '');
        $siteUrl = config('wisecms.site_url', request()->domain());
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $siteName,
            'url' => $siteUrl,
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => (new UrlService())->absolute('cms/search/index?keyword={search_term_string}'),
                'query-input' => 'required name=search_term_string',
            ],
        ];
        return '<script type="application/ld+json">' . $this->encode($data) . '</script>';
    }

    /**
     * 根据内容自动检测 Schema 类型
     */
    protected function detectSchemaType(Content $content): string
    {
        $cat = $content->category;
        if ($cat) {
            $name = $cat->name;
            if (stripos($name, '新闻') !== false) return 'NewsArticle';
            if (stripos($name, '博客') !== false) return 'BlogPosting';
            if (stripos($name, '产品') !== false) return 'Product';
            if (stripos($name, 'FAQ') !== false) return 'FAQPage';
            if (stripos($name, '教程') !== false) return 'HowTo';
        }
        if (stripos($content->content ?? '', '<video') !== false) return 'VideoObject';
        return 'Article';
    }
}
