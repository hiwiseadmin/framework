<?php
namespace plugin\wisecms\service\engine\dimension;

use plugin\wisecms\service\engine\QualityDimensionInterface;

class SeoDimension implements QualityDimensionInterface
{
    public function getName(): string { return 'SEO优化度'; }
    public function getMaxScore(): int { return 30; }

    public function score(array $content): int
    {
        $score = 0;
        // 关键词
        $kw = $content['keywords'] ?? '';
        $kwCount = !empty($kw) ? count(explode(',', $kw)) : 0;
        if ($kwCount >= 3) $score += 8;
        elseif ($kwCount >= 1) $score += 4;
        // Meta描述
        $descLen = mb_strlen($content['description'] ?? '');
        if ($descLen >= 70 && $descLen <= 160) $score += 8;
        elseif ($descLen > 0) $score += 4;
        // URL别名
        if (!empty($content['slug'])) $score += 7;
        // 结构化数据标记（简化检测）
        $hasStructure = !empty($content['structured_data']);
        if ($hasStructure) $score += 7;
        return min($score, 30);
    }

    public function getSuggestions(array $content): array
    {
        $s = [];
        if (empty($content['keywords'])) $s[] = '建议添加3-5个关键词';
        $descLen = mb_strlen($content['description'] ?? '');
        if ($descLen < 70) $s[] = '建议补充Meta描述（70-160字）';
        if (empty($content['slug'])) $s[] = '建议设置URL别名';
        return $s;
    }
}
