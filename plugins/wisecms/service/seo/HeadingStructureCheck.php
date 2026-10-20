<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\service\seo\SeoCheckInterface;

/**
 * 标题层级检查
 */
class HeadingStructureCheck implements SeoCheckInterface
{
    public function getName(): string { return 'H1-H6层级'; }
    public function getWeight(): float { return 0.06; }

    public function check(array $content, array $category): array
    {
        $body = (string) ($content['content'] ?? '');

        // 用 DOM/正则解析全部标题层级（替代原 substr_count($body, '<h2') 的脆弱子串匹配）
        $counts = SeoHtmlParser::headingCounts($body);

        // 正文内子标题（h2-h6）数量；h1 通常由前台模板输出，不强制正文包含
        $subCount = $counts[2] + $counts[3] + $counts[4] + $counts[5] + $counts[6];
        $pass = $subCount >= 1;

        return [
            'score' => $pass ? 10 : 2,
            'max' => 10, 'pass' => $pass,
            'message' => $pass ? '' : '建议使用h2/h3标签构建内容层级',
        ];
    }
}
