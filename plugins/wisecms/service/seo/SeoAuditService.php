<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Content;
use plugin\wisecms\model\SeoAudit;
use plugin\wisecms\model\Category;

/**
 * SEO审查服务
 */
class SeoAuditService
{
    /** @var SeoCheckInterface[] */
    protected array $checks = [];

    public function __construct()
    {
        $this->registerDefaultChecks();
    }

    protected function registerDefaultChecks(): void
    {
        $this->checks['title_tag']       = new TitleTagCheck();
        $this->checks['meta_description'] = new MetaDescriptionCheck();
        $this->checks['url_structure']    = new UrlStructureCheck();
        $this->checks['heading_structure'] = new HeadingStructureCheck();
        $this->checks['keyword_density']  = new KeywordDensityCheck();
        $this->checks['content_length']   = new ContentLengthCheck();
        $this->checks['internal_links']   = new InternalLinksCheck();
        $this->checks['image_alt']        = new ImageAltCheck();
    }

    public function registerCheck(SeoCheckInterface $check): self
    {
        $this->checks[$check->getName()] = $check;
        return $this;
    }

    public function auditContent(int $contentId): array
    {
        $content = Content::find($contentId);
        if (!$content) throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
        $data = $content->toArray();
        $category = $content->category ? $content->category->toArray() : [];

        $totalScore = 0;
        $maxScore = 0;
        $results = [];
        $issues = [];

        foreach ($this->checks as $name => $check) {
            $result = $check->check($data, $category);
            $weight = $check->getWeight();
            $results[$name] = $result;
            $totalScore += $result['score'] * $weight;
            $maxScore += $result['max'] * $weight;
            if (!$result['pass']) {
                $issues[] = ['check' => $name, 'name' => $check->getName(), 'message' => $result['message']];
            }
        }

        $finalScore = $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : 0;
        $level = $this->getLevel($finalScore);

        // 保存审查记录
        SeoAudit::create([
            'content_id' => $contentId,
            'total_score' => $finalScore,
            'checks' => json_encode($results, JSON_UNESCAPED_UNICODE),
            'issues' => json_encode($issues, JSON_UNESCAPED_UNICODE),
            'audit_time' => cms_now(),
        ]);

        // 同步内容表
        $content->seo_score = $finalScore;
        $content->seo_level = $level;
        $content->save();

        return ['total_score' => $finalScore, 'level' => $level, 'checks' => $results, 'issues' => $issues];
    }

    public function auditCategory(int $catId): array
    {
        $contents = Content::where('catid', $catId)->whereNull('delete_time')->select();
        $results = [];
        foreach ($contents as $c) {
            $results[] = $this->auditContent($c->id);
        }
        return $results;
    }

    public function siteHealthCheck(int $siteId = 1): array
    {
        $totalContents = Content::where('site_id', $siteId)->whereNull('delete_time')->count();
        $avgSeo = Content::where('site_id', $siteId)->whereNull('delete_time')->avg('seo_score') ?? 0;
        $qualityA = Content::where('site_id', $siteId)->whereNull('delete_time')->where('seo_level', 'A')->count();
        $qualityF = Content::where('site_id', $siteId)->whereNull('delete_time')->where('seo_level', 'F')->count();

        return [
            'total_contents' => $totalContents,
            'avg_seo_score' => round($avgSeo, 2),
            'grade_distribution' => ['A' => $qualityA, 'F' => $qualityF],
            'timestamp' => time(),
        ];
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
