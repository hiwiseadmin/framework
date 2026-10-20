<?php
namespace plugin\wisecms\service\engine;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Content;
use plugin\wisecms\model\ContentQuality;
use think\facade\Db;

class QualityScorer
{
    /** @var QualityDimensionInterface[] */
    protected array $dimensions = [];
    protected int $publishThreshold = 60;
    protected int $warnThreshold = 80;

    public function __construct()
    {
        $this->registerDefaultDimensions();
    }

    protected function registerDefaultDimensions(): void
    {
        $this->dimensions['completeness'] = new dimension\CompletenessDimension();
        $this->dimensions['seo'] = new dimension\SeoDimension();
        $this->dimensions['readability'] = new dimension\ReadabilityDimension();
        $this->dimensions['originality'] = new dimension\OriginalityDimension();
    }

    public function registerDimension(string $name, QualityDimensionInterface $dim): self
    {
        $this->dimensions[$name] = $dim;
        return $this;
    }

    public function score(array $content): array
    {
        $totalScore = 0;
        $maxScore = 0;
        $details = [];
        $allSuggestions = [];

        foreach ($this->dimensions as $name => $dim) {
            $score = $dim->score($content);
            $max = $dim->getMaxScore();
            $suggestions = $dim->getSuggestions($content);
            // 带上维度中文名（来自各 Dimension 自身的 getName()），
            // 供后台面板直接展示 —— 避免前端再维护一份「英文键 → 中文名」映射
            $details[$name] = [
                'name'        => $dim->getName(),
                'score'       => $score,
                'max'         => $max,
                'suggestions' => $suggestions,
            ];
            $totalScore += $score;
            $maxScore += $max;
            $allSuggestions = array_merge($allSuggestions, $suggestions);
        }

        $finalScore = $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : 0;
        $level = $this->getLevel($finalScore);
        $canPublish = $finalScore >= $this->publishThreshold;

        return [
            'total_score' => $finalScore,
            'level' => $level,
            'can_publish' => $canPublish,
            'dimensions' => $details,
            'suggestions' => $allSuggestions,
        ];
    }

    public function scoreAndSave(int $contentId): array
    {
        $content = Content::find($contentId);
        if (!$content) throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
        
        $result = $this->score($content->toArray());

        $attrs = [
            'total_score'  => $result['total_score'],
            'completeness' => $result['dimensions']['completeness']['score'] ?? 0,
            'seo_score'    => $result['dimensions']['seo']['score'] ?? 0,
            'readability'  => $result['dimensions']['readability']['score'] ?? 0,
            'originality'  => $result['dimensions']['originality']['score'] ?? 0,
            'suggestions'  => json_encode($result['suggestions'], JSON_UNESCAPED_UNICODE),
            'level'        => $result['level'],
            'check_time'   => cms_now(),
        ];

        // ⚠️ 原实现调模型上的 `updateOrCreate`（写法：`ContentQuality::updateOrCreate`）——
        //    本版 think-orm 的 Model 与 Query **都没有该方法**，
        //    调用即 `method not exist:think\db\Query->updateOrCreate`。
        //    之所以长期没暴露：`scoreAndSave()` 此前**没有任何调用方**（只有 CLI 命令直达，
        //    而 CLI 也从未被执行过）。P2-02 接上后台入口后立刻 500 → 改为「查到则更新、无则创建」。
        $record = ContentQuality::where('content_id', $contentId)->find();
        if ($record) {
            $record->save($attrs);
        } else {
            ContentQuality::create($attrs + ['content_id' => $contentId]);
        }

        // 同步更新内容表
        $content->quality_score = $result['total_score'];
        $content->quality_level = $result['level'];
        $content->save();

        return $result;
    }

    protected function getLevel(float $score): string
    {
        if ($score >= 90) return 'A';
        if ($score >= 70) return 'B';
        if ($score >= 50) return 'C';
        if ($score >= 30) return 'D';
        return 'F';
    }
}

interface QualityDimensionInterface
{
    public function score(array $content): int;
    public function getMaxScore(): int;
    public function getSuggestions(array $content): array;
    public function getName(): string;
}
