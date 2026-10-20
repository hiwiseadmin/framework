<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\service\seo\SeoCheckInterface;

/**
 * 内链检查
 */
class InternalLinksCheck implements SeoCheckInterface
{
    public function getName(): string { return '内链数量'; }
    public function getWeight(): float { return 0.05; }

    public function check(array $content, array $category): array
    {
        $body = (string) ($content['content'] ?? '');

        // 站点域名：优先取内容自身 URL，其次取站点配置
        $host = (string) (parse_url((string) ($content['url'] ?? ''), PHP_URL_HOST) ?: '');
        if ($host === '') {
            $host = (string) (parse_url((string) config('wisecms.site_url', ''), PHP_URL_HOST) ?: '');
        }

        // 用 DOM/正则解析 <a href>（替代原 substr_count($body, '<a href=')，后者漏掉
        // 属性空白差异且把外链也计入「内链」）
        $stats = SeoHtmlParser::linkStats($body, $host);
        $count = $stats['internal'];

        $pass = $count >= 2;
        return [
            'score' => $pass ? 10 : ($count >= 1 ? 5 : 2),
            'max' => 10, 'pass' => $pass,
            'message' => $pass ? '' : "建议添加2-3个相关内链（当前{$count}个）",
        ];
    }
}
