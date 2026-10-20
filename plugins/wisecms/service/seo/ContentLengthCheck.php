<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\service\seo\SeoCheckInterface;

/**
 * 内容长度检查
 */
class ContentLengthCheck implements SeoCheckInterface
{
    public function getName(): string { return '内容长度'; }
    public function getWeight(): float { return 0.05; }

    public function check(array $content, array $category): array
    {
        // 提取正文纯文本（剔除标签与 script/style 内容），再统计字数
        $body = SeoHtmlParser::textContent((string) ($content['content'] ?? ''));
        $len = mb_strlen($body);
        $pass = $len >= 800;
        return [
            'score' => $pass ? 10 : ($len >= 400 ? 6 : ($len > 0 ? 3 : 0)),
            'max' => 10, 'pass' => $pass,
            'message' => $pass ? '' : '建议正文≥800字（当前' . $len . '字）',
        ];
    }
}
