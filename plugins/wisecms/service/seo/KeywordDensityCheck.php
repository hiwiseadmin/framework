<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\service\seo\SeoCheckInterface;

/**
 * Keyword 密度检查
 */
class KeywordDensityCheck implements SeoCheckInterface
{
    public function getName(): string { return '关键词密度'; }
    public function getWeight(): float { return 0.06; }

    public function check(array $content, array $category): array
    {
        $kw = (string) ($content['keywords'] ?? '');
        $keywords = array_values(array_filter(array_map('trim', explode(',', $kw)), static fn ($k) => $k !== ''));
        $kwCount = count($keywords);

        if ($kwCount === 0) {
            return ['score' => 0, 'max' => 10, 'pass' => false, 'message' => '建议设置3-5个相关关键词'];
        }

        // 真实密度：统计关键词在正文纯文本中的覆盖情况（而非仅看字段数量）
        $text = mb_strtolower(SeoHtmlParser::textContent((string) ($content['content'] ?? '')));
        $totalLen = mb_strlen($text);
        $hitKeywords = 0;
        $hitChars = 0;
        foreach ($keywords as $keyword) {
            $lower = mb_strtolower($keyword);
            $n = $lower !== '' ? substr_count($text, $lower) : 0;
            if ($n > 0) {
                $hitKeywords++;
                $hitChars += $n * mb_strlen($keyword);
            }
        }
        $density = $totalLen > 0 ? round($hitChars / $totalLen * 100, 2) : 0.0;

        $pass = $kwCount >= 3 && $hitKeywords > 0;
        if ($pass) {
            $score = 10;
            $message = '';
        } elseif ($hitKeywords === 0) {
            $score = 3;
            $message = '正文未出现所设关键词，建议自然融入';
        } else {
            $score = 6;
            $message = '建议设置3-5个相关关键词（当前' . $kwCount . '个，密度' . $density . '%）';
        }

        return ['score' => $score, 'max' => 10, 'pass' => $pass, 'message' => $message];
    }
}
