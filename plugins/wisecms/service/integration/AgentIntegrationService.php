<?php
namespace plugin\wisecms\service\integration;

class AgentIntegrationService
{
    /**
     * AI 辅助写作
     */
    public function assistWriting(string $topic, array $options = []): array
    {
        $prompt = $this->buildWritingPrompt($topic, $options);
        $response = $this->callAgent('chat', ['message' => $prompt]);
        return $this->parseArticleResponse($response);
    }

    /**
     * AI 摘要生成
     */
    public function generateSummary(string $content, int $maxLength = 200): string
    {
        $prompt = "请为以下内容生成{$maxLength}字以内的摘要:\n" . strip_tags($content);
        $response = $this->callAgent('chat', ['message' => $prompt]);
        return $response['content'] ?? mb_substr(strip_tags($content), 0, $maxLength);
    }

    /**
     * AI 标签推荐
     */
    public function suggestTags(string $content, int $count = 10): array
    {
        $prompt = "请为以下内容推荐{$count}个标签，用逗号分隔:\n" . strip_tags(substr($content, 0, 2000));
        $response = $this->callAgent('chat', ['message' => $prompt]);
        $tags = explode(',', $response['content'] ?? '');
        return array_map('trim', array_filter($tags));
    }

    /**
     * AI SEO 优化
     */
    public function optimizeSeo(string $title, string $content): array
    {
        $prompt = <<<PROMPT
为以下文章优化SEO。请返回JSON格式:
{
  "title": "优化后的标题(30字内)",
  "description": "Meta描述(150字内)",
  "keywords": "3-5个关键词逗号分隔",
  "suggestions": ["建议1", "建议2"]
}

原标题: {$title}
内容摘要: 
PROMPT . strip_tags(substr($content, 0, 1000));

        $response = $this->callAgent('chat', ['message' => $prompt]);
        return json_decode($response['content'] ?? '{}', true) ?: [];
    }

    /**
     * AI 内容审核
     */
    public function auditContent(string $content): array
    {
        $prompt = "请检查以下内容是否存在违规（色情/暴力/政治敏感/广告），回复JSON: {\"safe\": true/false, \"issues\": [\"问题1\"]}\n" . strip_tags($content);
        $response = $this->callAgent('chat', ['message' => $prompt]);
        return json_decode($response['content'] ?? '{"safe":true}', true) ?: ['safe' => true];
    }

    /**
     * AI 翻译
     */
    public function translate(string $content, string $targetLang = 'en'): string
    {
        $prompt = "请将以下内容翻译为{$targetLang}:\n" . $content;
        $response = $this->callAgent('chat', ['message' => $prompt]);
        return $response['content'] ?? '';
    }

    /**
     * 调用 wise-agent
     */
    protected function callAgent(string $method, array $params): array
    {
        // 真实入口：wise\agent\agent\AgentManager::chat(string $message, ?string $provider = null, array $options = []): string
        try {
            if (class_exists(\wise\agent\agent\AgentManager::class)) {
                $agent = app(\wise\agent\agent\AgentManager::class);
                return match ($method) {
                    'chat' => ['content' => $agent->chat((string) ($params['message'] ?? ''))],
                    default => ['content' => ''],
                };
            }
        } catch (\Throwable $e) {
            cms_log('warning', '[CMS Agent] 调用失败: ' . $e->getMessage());
        }

        return ['content' => '', 'error' => 'wise-agent 未启用'];
    }

    protected function buildWritingPrompt(string $topic, array $options): string
    {
        $style = $options['style'] ?? '专业';
        $requirements = $options['requirements'] ?? '结构完整，含h2/h3标题，800字以上';
        return "请写一篇关于「{$topic}」的{$style}文章。要求：{$requirements}";
    }

    protected function parseArticleResponse(array $response): array
    {
        $text = $response['content'] ?? '';
        // 尝试提取标题
        $title = '';
        if (preg_match('/^#+\s*(.+)$/m', $text, $m)) {
            $title = trim($m[1]);
        } elseif (preg_match('/^(.{5,50})[\r\n]/u', $text, $m)) {
            $title = trim($m[1]);
        }

        return [
            'title' => $title ?: 'AI生成文章',
            'content' => $text,
            'description' => mb_substr(strip_tags($text), 0, 200),
        ];
    }
}
