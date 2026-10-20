<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\service\seo\SeoCheckInterface;

/**
 * Meta 描述检查
 */
class MetaDescriptionCheck implements SeoCheckInterface
{
    public function getName(): string { return 'Meta描述'; }
    public function getWeight(): float { return 0.10; }

    public function check(array $content, array $category): array
    {
        $desc = $content['description'] ?? '';
        $len = mb_strlen($desc);
        $pass = $len >= 70 && $len <= 160;
        return [
            'score' => $pass ? 10 : ($len > 0 ? 4 : 0),
            'max' => 10, 'pass' => $pass,
            'message' => $pass ? '' : '建议添加70-160字的Meta描述',
        ];
    }
}
