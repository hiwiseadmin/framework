<?php
declare(strict_types=1);

namespace app\common\service;

use wise\support\context\ContextualLogger;
use wise\workflow\contract\PermissionServiceInterface;
use wise\workflow\model\Transition;

/**
 * 工作流转换权限 —— 阶段 0 的「只审计 + 默认拒绝」最小实现
 *
 * 背景：wise-workflow 的 CheckPermissionPipe 从容器标识 permission 取权限服务，
 * 取不到就直接放行（fail-open）——也就是说「不绑定」等价于「权限工序是空操作」。
 * 本类让判定真正发生，并逐条记录，用于产出「转换 × 调用方」清单，
 * 作为后续设计真实权限策略的输入。
 *
 * 运行模式（环境变量 WISE_WORKFLOW_PERMISSION_MODE）：
 *   deny  —— 默认。拒绝一切转换，但逐条记录，用于发现「谁在依赖无权限调用」。
 *   allow —— 临时放行，仅用于应急/回滚；日志以 ALLOW 前缀显著标注。
 *
 * ⚠️ 这是过渡实现，不是长期授权方案：
 *   1. 它不做真正的权限判断（只记录 + 拒绝），真实 RBAC 实现应替换本绑定；
 *   2. $context['user_id'] 在阶段 1 修复 C-2 之前可能来自请求体，不可当可信主体；
 *   3. $context['subject'] 目前不由包注入（阶段 3 的 C-1 才提供），故日志中常为 null。
 *
 * 回滚：在 app/AppService::register() 中注释掉 'permission' 绑定即恢复原状
 *      （注意：权限工序会立刻变回 fail-open 的空操作）。
 *
 * @see \d:\WorkBuddyWorkspace\wiseadmin\docs\security-audit-skill\wise-workflow\run-1\FIX-PLAN.md 阶段 0
 * @see \d:\WorkBuddyWorkspace\wiseadmin\docs\security-audit-skill\wise-workflow\run-1\SCOPE-REASSESSMENT.md
 */
class AuditOnlyPermission implements PermissionServiceInterface
{
    /** 是否放行（仅由 WISE_WORKFLOW_PERMISSION_MODE=allow 打开） */
    protected bool $allow;

    public function __construct()
    {
        $this->allow = strtolower((string) env('WISE_WORKFLOW_PERMISSION_MODE', 'deny')) === 'allow';
    }

    /**
     * 检查是否有权限执行指定转换
     *
     * @param Transition $transition 待执行的转换
     * @param array      $context    上下文（user_id / auto_sla / remark 等，来源见类注释）
     * @return bool 当前模式下恒为拒绝或恒为放行
     */
    public function check(Transition $transition, array $context): bool
    {
        ContextualLogger::warning('[wf-permission] ' . ($this->allow ? 'ALLOW' : 'DENY'), [
            'transition' => $transition->getName(),
            'from'       => $transition->getFrom(),
            'to'         => $transition->getTo(),
            'user_id'    => $context['user_id'] ?? null,
            'auto_sla'   => $context['auto_sla'] ?? null,
            'subject'    => $context['subject'] ?? null,
        ]);

        return $this->allow;
    }
}
