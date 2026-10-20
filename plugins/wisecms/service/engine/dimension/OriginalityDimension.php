<?php
namespace plugin\wisecms\service\engine\dimension;

use plugin\wisecms\service\engine\QualityDimensionInterface;
use plugin\wisecms\model\Content;

class OriginalityDimension implements QualityDimensionInterface
{
    public function getName(): string { return '原创性'; }
    public function getMaxScore(): int { return 15; }

    public function score(array $content): int
    {
        $score = 10;
        $id = $content['id'] ?? 0;
        // 检测是否有 source_url（转载内容）
        if (!empty($content['source_url'])) $score -= 3;
        // 如果 source_type 为 manual（手动原创），加分
        if (($content['source_type'] ?? '') === 'manual') $score += 5;
        return max(0, min($score, 15));
    }

    public function getSuggestions(array $content): array
    {
        $s = [];
        if (!empty($content['source_url'])) {
            $s[] = '转载内容建议添加原文链接和转载声明';
        }
        return $s;
    }
}
