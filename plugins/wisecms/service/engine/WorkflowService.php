<?php
namespace plugin\wisecms\service\engine;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Content;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\integration\NotificationService;

/**
 * 内容工作流服务（对接 wise-workflow）
 *
 * 对外方法签名与旧实现保持一致（供 controller/Workflow 调用），内部改为：
 *   - 状态机定义见 ContentWorkflow；
 *   - 流转历史读 workflow_history（修正旧实现误读 ContentVersion 的问题）；
 *   - 双写过渡：apply() 后把 place 写回 content.status，保证现有 status 查询与前台渲染不变。
 */
class WorkflowService
{
    protected ContentService $contentService;

    public function __construct()
    {
        $this->contentService = new ContentService();
    }

    /**
     * 获取可用状态转换
     */
    public function getAvailableTransitions(int $contentId): array
    {
        $content = Content::find($contentId);
        $engine  = ContentWorkflow::engine();
        if (!$content || $engine === null) {
            return [];
        }

        $w       = ContentWorkflow::definition();
        $subject = ContentWorkflow::subject($contentId);
        $this->ensureMarking($engine, $subject, (int) $content->status);

        $available = [];
        foreach ($engine->getEnabledTransitions($w, $subject) as $transition) {
            $available[] = [
                'action' => $transition->getName(),
                'from'   => implode(',', $transition->getFrom()),
                'to'     => implode(',', $transition->getTo()),
                'label'  => $transition->getLabel(),
            ];
        }

        return $available;
    }

    /**
     * 执行状态转换
     */
    public function transition(int $contentId, string $action, array $context = []): Content
    {
        $content = Content::find($contentId);
        if (!$content) {
            throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
        }

        $engine = ContentWorkflow::engine();
        if ($engine === null) {
            throw new WisecmsException('工作流组件未安装，无法执行状态转换', ErrorCode::SAVE_FAILED);
        }

        $w       = ContentWorkflow::definition();
        $subject = ContentWorkflow::subject($contentId);
        $this->ensureMarking($engine, $subject, (int) $content->status);

        if (!$engine->can($w, $subject, $action, $context)) {
            throw new WisecmsException("无效的状态转换: {$action}", ErrorCode::VALIDATE_FAILED);
        }

        $engine->apply($w, $subject, $action, $context);

        // 双写：place → status（保证现有 status 查询与前台渲染不变）
        $transitions  = $w->getTransitions();
        $toPlace      = isset($transitions[$action]) ? ($transitions[$action]->getTo()[0] ?? null) : null;
        $targetStatus = ContentWorkflow::statusForPlace((string) $toPlace);

        // P1-B1：工作流引擎已校验流转合法性，此处标记为「工作流驱动」，
        // 允许 draft→publish 这类在流程定义内合法的路径，避免与状态机校验冲突
        $updated = $this->contentService->updateStatus($contentId, $targetStatus, true);

        // 保留旧实现的通知行为：发布/驳回时通知管理员
        if (in_array($action, ['publish', 'reject'], true)) {
            (new NotificationService())->notifyContentReview($contentId, $action);
        }

        return $updated;
    }

    /**
     * 获取工作流状态
     */
    public function getWorkflowStatus(int $contentId): array
    {
        $content = Content::find($contentId);
        if (!$content) {
            return [];
        }

        $engine = ContentWorkflow::engine();
        if ($engine === null) {
            return [
                'content_id'    => $contentId,
                'current_state' => ContentWorkflow::placeForStatus((int) $content->status),
                'status_label'  => $content->status_text,
                'transitions'   => [],
                'history'       => [],
            ];
        }

        $subject = ContentWorkflow::subject($contentId);
        $this->ensureMarking($engine, $subject, (int) $content->status);

        $marking      = $engine->getMarking($subject);
        $currentState = $marking
            ? (string) array_key_first($marking)
            : ContentWorkflow::placeForStatus((int) $content->status);

        return [
            'content_id'    => $contentId,
            'current_state' => $currentState,
            'status_label'  => $content->status_text,
            'transitions'   => $this->getAvailableTransitions($contentId),
            'history'       => $this->getTransitionHistory($contentId),
        ];
    }

    /**
     * 获取转换历史（读 workflow_history）
     */
    public function getTransitionHistory(int $contentId): array
    {
        $engine = ContentWorkflow::engine();
        if ($engine === null) {
            return [];
        }

        $history = $engine->getHistory(ContentWorkflow::subject($contentId), 10);

        // P1-08：workflow_history 只有 user_id，补上操作人姓名（nickname 优先，空回退 username）
        // 供前端时间线显示「谁在什么时间做了什么流转」；admin 模型/表不可用时降级为空字符串。
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', array_column($history, 'user_id'))
        )));
        $names = [];
        if ($userIds !== []) {
            try {
                $rows = \app\admin\model\Admin::whereIn('id', $userIds)
                    ->field('id, nickname, username')
                    ->select()
                    ->toArray();
                foreach ($rows as $r) {
                    $names[(int) $r['id']] = $r['nickname'] !== '' && $r['nickname'] !== null
                        ? (string) $r['nickname']
                        : (string) $r['username'];
                }
            } catch (\Throwable $e) {
                // 管理员表不可用：操作人留空，不影响时间线其余部分
            }
        }

        foreach ($history as &$row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $row['user_name'] = $names[$uid] ?? '';
        }
        unset($row);

        return $history;
    }

    /**
     * 首次访问时按 content.status 回填工作流标记（双写过渡：status 仍为准）
     */
    protected function ensureMarking($engine, string $subject, int $status): void
    {
        if (!empty($engine->getMarking($subject))) {
            return;
        }

        try {
            $engine->setMarking($subject, [ContentWorkflow::placeForStatus($status) => 1]);
        } catch (\Throwable $e) {
            // 工作流表未迁移等场景下降级：不阻断内容操作
        }
    }
}
