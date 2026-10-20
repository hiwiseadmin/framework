<?php
namespace plugin\wisecms\service\engine\dimension;

use plugin\wisecms\service\engine\QualityDimensionInterface;

class ReadabilityDimension implements QualityDimensionInterface
{
    public function getName(): string { return '可读性'; }
    public function getMaxScore(): int { return 25; }

    public function score(array $content): int
    {
        $score = 15; // 基础分
        $body = strip_tags($content['content'] ?? '');
        if (empty($body)) return 0;
        // 句子长度检测
        $sentences = preg_split('/[。！？.!?]/u', $body, -1, PREG_SPLIT_NO_EMPTY);
        $avgLen = count($sentences) > 0 ? mb_strlen(implode('', $sentences)) / count($sentences) : 0;
        if ($avgLen > 0 && $avgLen <= 25) $score += 5;
        elseif ($avgLen <= 35) $score += 3;
        // 段落检测
        $paragraphs = array_filter(explode("\n", $body));
        if (count($paragraphs) >= 3) $score += 5;
        return min($score, 25);
    }

    public function getSuggestions(array $content): array
    {
        $s = [];
        $body = strip_tags($content['content'] ?? '');
        $sentences = preg_split('/[。！？.!?]/u', $body, -1, PREG_SPLIT_NO_EMPTY);
        $avgLen = count($sentences) > 0 ? mb_strlen(implode('', $sentences)) / count($sentences) : 0;
        if ($avgLen > 35) $s[] = '句子偏长（均' . round($avgLen) . '字），建议拆分长句';
        $paragraphs = array_filter(explode("\n", $body));
        if (count($paragraphs) < 3) $s[] = '建议合理分段，每段控制在5句以内';
        return $s;
    }
}
