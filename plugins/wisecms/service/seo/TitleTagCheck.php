<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\service\seo\SeoCheckInterface;

/**
 * Title标签检查
 */
class TitleTagCheck implements SeoCheckInterface
{
    public function getName(): string { return 'Title标签'; }
    public function getWeight(): float { return 0.10; }

    public function check(array $content, array $category): array
    {
        $title = $content['title'] ?? '';
        $len = mb_strlen($title);
        $pass = $len >= 15 && $len <= 60;
        return [
            'score' => $pass ? 10 : ($len > 0 ? 5 : 0),
            'max' => 10,
            'pass' => $pass,
            'message' => $pass ? '' : ($len == 0 ? '缺少标题' : "标题长度{$len}字，建议15-60字"),
        ];
    }
}
