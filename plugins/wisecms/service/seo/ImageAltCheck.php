<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\service\seo\SeoCheckInterface;

/**
 * 图片Alt检查
 * 建议所有图片都设置Alt属性，以便搜索引擎抓取图片内容
 */
class ImageAltCheck implements SeoCheckInterface
{
    public function getName(): string { return '图片Alt'; }
    public function getWeight(): float { return 0.05; }

    public function check(array $content, array $category): array
    {
        $body = (string) ($content['content'] ?? '');

        // 用 DOM/正则解析 <img> 与 alt（替代原 [^>]+ 正则在属性值含 '>' 时会截断的问题）
        $stats = SeoHtmlParser::imageAltStats($body);
        $total = $stats['total'];
        $missing = $stats['missing'];

        $pass = $total === 0 || $missing === 0;
        return [
            'score' => $pass ? 10 : ($total > 0 ? 3 : 8),
            'max' => 10, 'pass' => $pass,
            'message' => $pass ? '' : "{$missing}/{$total}张图片缺少Alt属性",
        ];
    }
}
