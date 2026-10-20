<?php
namespace plugin\wisecms\pipeline\stage;

use plugin\wisecms\pipeline\PipelineStageInterface;

class KeywordExtractStage implements PipelineStageInterface
{
    public function getName(): string { return 'keyword_extract'; }
    public function getPriority(): int { return 10; }

    public function handle(array $data, string $scene = 'create'): array
    {
        if (!empty($data['keywords']) || empty($data['title'])) {
            return $data;
        }
        // 简单关键词提取：从标题中提取2-4字词组
        $title = strip_tags($data['title']);
        $len = mb_strlen($title);
        $keywords = [];
        for ($i = 0; $i < $len - 1; $i++) {
            $word = mb_substr($title, $i, min(4, $len - $i));
            if (mb_strlen($word) >= 2) {
                $keywords[] = $word;
            }
        }
        $data['keywords'] = implode(',', array_slice(array_unique($keywords), 0, 5));
        return $data;
    }
}
