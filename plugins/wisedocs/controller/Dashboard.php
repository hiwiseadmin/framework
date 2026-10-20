<?php

declare(strict_types=1);

namespace plugin\wisedocs\controller;

use plugin\wisedocs\model\Category;
use plugin\wisedocs\model\Document;
use plugin\wisedocs\model\Feedback;
use plugin\wisedocs\model\Tag;

/**
 * 文档中心 · 概览（工作台）
 *
 * P0 交互升级：从「只读看板」变成「可用的工作台」——
 *   统计卡可点击直达对应列表、待处理事项带数量入口、快捷操作、
 *   最近更新（标题可直接跳到文档列表并打开编辑弹窗）。
 */
class Dashboard extends Base
{
    /** 内容类型文案（与 Document 控制器保持一致） */
    protected const TYPE_TEXT = [
        Document::CONTENT_TYPE_ARTICLE   => '文章',
        Document::CONTENT_TYPE_CODE      => '代码',
        Document::CONTENT_TYPE_COMPONENT => '组件',
        Document::CONTENT_TYPE_API       => 'API',
    ];

    public function index()
    {
        $counts = [
            'documents'  => $this->safeCount(Document::class),
            'categories' => $this->safeCount(Category::class),
            'tags'       => $this->safeCount(Tag::class),
            'feedback'   => $this->safeCount(Feedback::class),
            'published'  => $this->safeCountWhere(
                static fn (): int => Document::where('status', Document::STATUS_PUBLISHED)->count()
            ),
            'draft'      => $this->safeCountWhere(
                static fn (): int => Document::where('status', Document::STATUS_DRAFT)->count()
            ),
        ];

        $todos = [
            // 待处理反馈 → 反馈列表 ?processed=0
            'pending_feedback' => $this->safeCountWhere(
                static fn (): int => Feedback::where('processed', Feedback::STATUS_PENDING)->count()
            ),
            // 草稿文档 → 文档列表 ?status=0
            'draft_documents'  => $counts['draft'],
            // 定时待发布：草稿 + 发布时间在未来（由前台 ScheduleService 惰性发布）
            'scheduled'        => $this->safeCountWhere(
                static fn (): int => Document::where('status', Document::STATUS_DRAFT)
                    ->where('published_at', '>', date('Y-m-d H:i:s'))
                    ->count()
            ),
            // 待发布：已发布但存在未发布的改动 → 文档列表 ?publish_state=2
            'pending_publish'  => $this->safeCountWhere(
                static fn (): int => Document::where('publish_state', Document::PUBLISH_STATE_PENDING)->count()
            ),
        ];

        $recent = $this->safeSelect(
            static fn () => Document::with(['category'])
                ->field('id,title,slug,status,content_type,category_id,update_time')
                ->order('update_time DESC, id DESC')
                ->limit(8)
                ->select()
                ->toArray()
        );

        foreach ($recent as &$item) {
            $item['content_type_text'] = self::TYPE_TEXT[(int) ($item['content_type'] ?? 0)] ?? '-';
            $item['status_text']       = (int) ($item['status'] ?? 0) === Document::STATUS_PUBLISHED
                ? '已发布'
                : '草稿';
        }
        unset($item);

        return $this->assign([
            'counts' => $counts,
            'todos'  => $todos,
            'recent' => $recent,
            'title'  => '文档中心概览',
        ])->fetch();
    }

    /**
     * 安全计数：表尚未创建等异常场景返回 0，避免概览页 500。
     */
    protected function safeCount(string $model): int
    {
        try {
            return (int) $model::count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 带条件的安数计数（条件来自闭包，便于表达多条件查询）
     */
    protected function safeCountWhere(callable $query): int
    {
        try {
            return (int) $query();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 安全查询：异常场景返回空数组
     */
    protected function safeSelect(callable $query): array
    {
        try {
            $result = $query();

            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
