<?php
namespace plugin\wisecms\service\engine;

use wise\workflow\Workflow;
use wise\workflow\WorkflowEngine;
use wise\workflow\model\Place;
use wise\workflow\model\Transition;
use plugin\wisecms\service\content\ContentService;

/**
 * CMS 内容工作流定义（对接 wise-workflow）
 *
 * place 与 wisecms_content.status 的映射（双写过渡期）：
 *   draft(0) / pending(1) / approved(→1) / published(2) / rejected(→0) / archived(3)
 *
 * subject 约定：content:{contentId}
 */
final class ContentWorkflow
{
    public const NAME = 'wisecms_content';

    public const INITIAL = 'draft';

    /** place => ContentService 状态常量 */
    public const PLACE_STATUS = [
        'draft'     => ContentService::STATUS_DRAFT,
        'pending'   => ContentService::STATUS_PENDING,
        'approved'  => ContentService::STATUS_PENDING,
        'published' => ContentService::STATUS_PUBLISHED,
        'rejected'  => ContentService::STATUS_DRAFT,
        'archived'  => ContentService::STATUS_ARCHIVED,
    ];

    /**
     * 构建内容工作流定义（places + transitions）
     */
    public static function definition(): Workflow
    {
        $w = new Workflow(self::NAME, [], [], self::INITIAL);

        $w->addPlace(new Place('draft', ['label' => '草稿']));
        $w->addPlace(new Place('pending', ['label' => '待审核']));
        $w->addPlace(new Place('approved', ['label' => '审核通过']));
        $w->addPlace(new Place('published', ['label' => '已发布', 'ended' => true]));
        $w->addPlace(new Place('rejected', ['label' => '已驳回']));
        $w->addPlace(new Place('archived', ['label' => '已归档', 'ended' => true]));

        $w->addTransition(new Transition('submit', 'draft', 'pending', null, ['label' => '提交审核', 'sort' => 10]));
        $w->addTransition(new Transition('approve', 'pending', 'approved', null, ['label' => '审核通过', 'sort' => 20]));
        $w->addTransition(new Transition('publish', ['approved', 'draft'], 'published', null, ['label' => '发布', 'sort' => 30]));
        $w->addTransition(new Transition('reject', ['pending', 'approved'], 'rejected', null, ['label' => '驳回', 'sort' => 40, 'is_reversible' => true]));
        $w->addTransition(new Transition('resubmit', 'rejected', 'draft', null, ['label' => '重新提交', 'sort' => 50]));
        $w->addTransition(new Transition('unpublish', 'published', 'draft', null, ['label' => '取消发布', 'sort' => 60, 'is_reversible' => true]));
        $w->addTransition(new Transition('archive', 'published', 'archived', null, ['label' => '归档', 'sort' => 70]));

        return $w;
    }

    public static function subject(int $contentId): string
    {
        return 'content:' . $contentId;
    }

    /**
     * 构建引擎实例；wise-workflow 未安装时返回 null
     *
     * 显式 new（而非 app(WorkflowEngine::class) 全量自动注入），
     * 避免 TaskManager 等可选依赖被容器意外解析。
     */
    public static function engine(): ?WorkflowEngine
    {
        if (!class_exists(WorkflowEngine::class) || !class_exists(\wise\workflow\contract\MarkingStoreInterface::class)) {
            return null;
        }

        try {
            return new WorkflowEngine(app(\wise\workflow\contract\MarkingStoreInterface::class));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * content.status → place
     */
    public static function placeForStatus(int $status): string
    {
        return match ($status) {
            ContentService::STATUS_PUBLISHED => 'published',
            ContentService::STATUS_ARCHIVED  => 'archived',
            ContentService::STATUS_PENDING   => 'pending',
            default                          => 'draft',
        };
    }

    /**
     * place → content.status
     */
    public static function statusForPlace(string $place): int
    {
        return self::PLACE_STATUS[$place] ?? ContentService::STATUS_DRAFT;
    }
}
