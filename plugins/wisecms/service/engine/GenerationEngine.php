<?php
namespace plugin\wisecms\service\engine;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\content\TemplateService;

use plugin\wisecms\model\GenerationTask;
use plugin\wisecms\model\Content;
use think\facade\Cache;

class GenerationEngine
{
    protected ContentService $contentService;
    protected QualityScorer $qualityScorer;
    protected array $strategies = [];

    public function __construct()
    {
        $this->contentService = new ContentService();
        $this->qualityScorer = new QualityScorer();
    }

    /**
     * 注册生成策略
     */
    public function registerStrategy(string $mode, GenerationStrategyInterface $strategy): self
    {
        $this->strategies[$mode] = $strategy;
        return $this;
    }

    /**
     * 自然语言生成
     */
    public function generateNatural(array $params): array
    {
        $task = $this->createTask('natural', $params);
        $result = $this->executeWithAI($task, 'natural', $params);
        $this->finishTask($task, $result);
        return $result;
    }

    /**
     * 模板驱动生成
     */
    public function generateFromTemplate(array $params): array
    {
        $task = $this->createTask('template', $params);
        $templateId = $params['template_id'] ?? 0;
        $overrides = $params['overrides'] ?? [];
        $tmplSvc = new TemplateService();
        $data = $tmplSvc->applyTemplate($templateId, $overrides);
        $result = $this->executeWithAI($task, 'template', ['template_data' => $data] + $params);
        $this->finishTask($task, $result);
        return $result;
    }

    /**
     * 批量生成
     */
    public function generateBatch(array $params): array
    {
        $keywords = $params['keywords'] ?? [];
        $catId = $params['category_id'] ?? 0;
        $dailyLimit = $params['daily_limit'] ?? 10;

        $task = $this->createTask('batch', $params);
        $task->total_count = count($keywords);
        $task->save();

        $results = [];
        foreach ($keywords as $i => $kw) {
            try {
                $article = $this->generateArticleFromKeyword($kw, $catId, $task);
                $results[] = $article;
                $task->completed_count = $i + 1;
                $task->progress = json_encode(['current' => $kw, 'index' => $i + 1]);
                $task->save();
            } catch (\Exception $e) {
                $results[] = ['keyword' => $kw, 'error' => $e->getMessage()];
            }
            if ($i + 1 >= $dailyLimit) break;
        }

        $this->finishTask($task, ['results' => $results, 'total' => count($results)]);
        return ['task_id' => $task->id, 'total' => count($results), 'results' => $results];
    }

    /**
     * 智能改写
     */
    public function rewrite(array $params): array
    {
        $task = $this->createTask('rewrite', $params);
        $result = $this->executeWithAI($task, 'rewrite', $params);
        $this->finishTask($task, $result);
        return $result;
    }

    /**
     * 内容续写
     */
    public function continueWriting(array $params): array
    {
        $task = $this->createTask('continue', $params);
        $result = $this->executeWithAI($task, 'continue', $params);
        $this->finishTask($task, $result);
        return $result;
    }

    /**
     * 获取任务状态
     */
    public function getTaskStatus(int $taskId): ?array
    {
        $task = GenerationTask::find($taskId);
        return $task ? $task->toArray() : null;
    }

    /**
     * 取消任务
     */
    public function cancelTask(int $taskId): bool
    {
        $task = GenerationTask::find($taskId);
        if ($task && in_array($task->status, ['pending', 'running'])) {
            $task->status = 'cancelled';
            $task->save();
        }
        return true;
    }

    // --- Private ---

    protected function createTask(string $mode, array $params): GenerationTask
    {
        return GenerationTask::create([
            'name' => $params['task_name'] ?? ('生成任务_' . date('YmdHis')),
            'mode' => $mode,
            'params' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'status' => 'running',
            // 时间字段统一：start_time 为 DATETIME（原 int 时间戳）；
            // create_time / update_time 由模型 autoWriteTimestamp 维护
            'start_time' => cms_now(),
        ]);
    }

    protected function executeWithAI(GenerationTask $task, string $mode, array $params): array
    {
        // AI 调用抽象层 — 实际实现通过 wise-agent
        $prompt = $this->buildPrompt($mode, $params);
        $aiResult = $this->callAI($prompt, $params);
        
        // 保存为内容
        if (!empty($aiResult['content'])) {
            // P0-5：状态必须按全局审核模式推导，不可由 auto_publish 直接置为「已发布」，
            // 否则 content_check=1（先审后发）时 AI 生成可绕过审核直接上线。
            $content = $this->contentService->createWithFields([
                'title' => $aiResult['title'] ?? $params['title'] ?? $params['topic'] ?? '',
                'content' => $aiResult['content'],
                'catid' => $params['category_id'] ?? 0,
                'keywords' => $aiResult['keywords'] ?? '',
                'description' => $aiResult['description'] ?? '',
                'status' => ContentService::derivePublishStatus(!empty($params['auto_publish'])),
            ]);
            return ['content_id' => $content->id, 'title' => $content->title, 'mode' => $mode];
        }

        return ['mode' => $mode, 'raw' => $aiResult, 'note' => 'AI 生成结果'];
    }

    protected function buildPrompt(string $mode, array $params): string
    {
        // 注意：字符串插值 {$expr} 仅支持变量/数组/属性，不能写 ??，
        // 因此先取出变量再插值（原写法会导致整个文件解析失败）。
        $topic        = $params['topic'] ?? '';
        $style        = $params['style'] ?? '专业';
        $requirements = $params['requirements'] ?? '结构完整，800字以上，含h2/h3标题';
        $sourceText   = $params['source_text'] ?? '';
        $direction    = $params['direction'] ?? '自然发展';
        $fragment     = $params['fragment'] ?? '';

        return match($mode) {
            'natural' => "请写一篇关于「{$topic}」的{$style}文章。要求：{$requirements}",
            'template' => "请基于以下模板数据填充一篇完整文章：" . json_encode($params['template_data'] ?? [], JSON_UNESCAPED_UNICODE),
            'rewrite' => "请对以下内容进行{$style}改写，保持核心信息不变：\n{$sourceText}",
            'continue' => "请续写以下内容（{$direction}）：\n{$fragment}",
            default => $topic,
        };
    }

    protected function callAI(string $prompt, array $params = []): array
    {
        // 真实入口：wise\agent\agent\AgentManager::chat(string $message, ?string $provider = null, array $options = []): string
        try {
            if (class_exists(\wise\agent\agent\AgentManager::class)) {
                $agent    = app(\wise\agent\agent\AgentManager::class);
                $response = $agent->chat($prompt);
                return $this->parseAIResponse((string) $response);
            }
        } catch (\Throwable $e) {
            cms_log('warning', '[CMS AI] 调用失败: ' . $e->getMessage());
        }

        // 降级：不再把用户输入（$prompt）拼进 HTML，旧实现形成了内容注入面
        return [
            'title'       => $params['title'] ?? $params['topic'] ?? 'AI生成文章',
            'content'     => '<p>未启用 wise-agent，无法生成内容，请在插件配置中启用后重试。</p>',
            'keywords'    => '',
            'description' => '',
        ];
    }

    protected function parseAIResponse(string $response): array
    {
        // 尝试解析 JSON 格式的 AI 响应
        $jsonStart = strpos($response, '{');
        if ($jsonStart !== false) {
            $jsonEnd = strrpos($response, '}');
            if ($jsonEnd !== false) {
                $jsonStr = substr($response, $jsonStart, $jsonEnd - $jsonStart + 1);
                $parsed = json_decode($jsonStr, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return [
                        'title'       => $parsed['title'] ?? '',
                        'content'     => $parsed['content'] ?? $response,
                        'keywords'    => $parsed['keywords'] ?? '',
                        'description' => $parsed['description'] ?? '',
                    ];
                }
            }
        }

        // 降级：尝试从 Markdown 提取标题
        $title = '';
        if (preg_match('/^#\s+(.+)/mu', $response, $m)) {
            $title = trim($m[1]);
        }

        return [
            'content'     => $response,
            'title'       => $title,
            'keywords'    => '',
            'description' => mb_substr(strip_tags($response), 0, 160),
        ];
    }

    protected function generateArticleFromKeyword(string $keyword, int $catId, ?GenerationTask $task = null): array
    {
        // 不能再依赖“最近一条 running 任务”：可能返回 null（强类型参数会抛 TypeError），
        // 也可能误取到批量任务自身。缺省时单独创建一个任务。
        $task ??= $this->createTask('natural', [
            'task_name'     => '生成任务_' . $keyword,
            'topic'         => $keyword,
            'category_id'   => $catId,
        ]);

        return $this->executeWithAI(
            $task,
            'natural',
            ['topic' => $keyword, 'category_id' => $catId, 'style' => '专业', 'requirements' => '1200字']
        );
    }

    protected function finishTask(GenerationTask $task, array $result): void
    {
        $task->status = 'completed';
        // 时间字段统一：finish_time 为 DATETIME（原 int 时间戳）
        $task->finish_time = cms_now();
        $task->save();
    }
}

interface GenerationStrategyInterface
{
    public function generate(array $params): array;
    public function supports(string $mode): bool;
}
