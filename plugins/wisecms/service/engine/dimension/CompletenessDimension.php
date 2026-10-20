<?php
namespace plugin\wisecms\service\engine\dimension;

use plugin\wisecms\service\engine\QualityDimensionInterface;

class CompletenessDimension implements QualityDimensionInterface
{
    public function getName(): string { return '内容完整度'; }
    public function getMaxScore(): int { return 30; }

    public function score(array $content): int
    {
        $score = 0;
        // 标题质量
        $titleLen = mb_strlen($content['title'] ?? '');
        if ($titleLen >= 10 && $titleLen <= 60) $score += 8;
        elseif ($titleLen >= 5) $score += 4;
        elseif ($titleLen > 0) $score += 2;
        // 正文长度
        $bodyLen = mb_strlen(strip_tags($content['content'] ?? ''));
        if ($bodyLen >= 800) $score += 8;
        elseif ($bodyLen >= 400) $score += 5;
        elseif ($bodyLen >= 100) $score += 3;
        // 段落结构
        if (!empty($content['content'])) {
            $h2Count = substr_count($content['content'], '<h2');
            $h3Count = substr_count($content['content'], '<h3');
            if ($h2Count >= 1) $score += 4;
            if ($h3Count >= 1) $score += 3;
        }
        // 多媒体
        $hasImage = !empty($content['thumb']) || stripos($content['content'] ?? '', '<img') !== false;
        if ($hasImage) $score += 7;
        return min($score, 30);
    }

    public function getSuggestions(array $content): array
    {
        $s = [];
        $titleLen = mb_strlen($content['title'] ?? '');
        if ($titleLen < 10) $s[] = '标题过短，建议补充核心关键词';
        if ($titleLen > 60) $s[] = '标题过长，建议控制在30-60字';
        $bodyLen = mb_strlen(strip_tags($content['content'] ?? ''));
        if ($bodyLen < 400) $s[] = '正文内容较少，建议补充至400字以上';
        if (empty($content['thumb'])) $s[] = '建议添加配图提升内容质感';
        return $s;
    }
}
