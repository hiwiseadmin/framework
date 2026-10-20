<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\service\seo\SeoCheckInterface;

/**
 * URL结构检查
 */
class UrlStructureCheck implements SeoCheckInterface
{
    public function getName(): string { return 'URL结构'; }
    public function getWeight(): float { return 0.08; }

    public function check(array $content, array $category): array
    {
        $slug = $content['slug'] ?? '';
        $pass = !empty($slug) && mb_strlen($slug) <= 75;
        return [
            'score' => $pass ? 10 : (empty($slug) ? 3 : 6),
            'max' => 10, 'pass' => $pass,
            'message' => $pass ? '' : '建议设置简短的URL别名（≤75字符）',
        ];
    }
}
