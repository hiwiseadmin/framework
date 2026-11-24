<?php
/**
 * ============================================================
 * AI 工具安全闸门验收（B-2 DoD：越权 / 需审批 / destructive / external）
 * ============================================================
 *
 * 四类用例＼�：
 *   1. 越权      未获授权、跨类别通配（只读通配开写）必须被拒
 *   2. 需审批    写操作默认不误拦；能力自身要求确认时策略只能加严
 *   3. destructive  risk=high（删除/出库）与 access=admin 强制确认
 *   4. external   外部 MCP 工具（mcp:*）强制确认 + 能力目录标 external + 默认关闭
 *
 * 本脚本只做**判定层**验收（不写库、不创建审批单）：
 *   - 授权：CapabilityAuthorizer::evaluateGranted()
 *   - 审批：ApprovalPolicy::resolve() / decideForTool()
 *   - 目录：CapabilityCatalog::build()
 * 端到端链路（真实审批单落库、批准后消费放行）由 wise-agent 单测覆盖。
 *
 * 用法：`php build/verify-ai-tool-guard.php`（或 `npm run verify:ai-guard`）
 * 退出码：0 全通过；1 存在未通过项
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use wise\agent\capability\CapabilityCatalog;
use wise\agent\contract\ToolInterface;
use wise\agent\security\ApprovalPolicy;
use wise\agent\security\CapabilityAccess;
use wise\agent\security\CapabilityAuthorizer;
use wise\agent\security\CapabilityDescriptor;
use wise\agent\tool\ToolRegistry;

echo "[verify-ai-tool-guard] AI 工具安全闸门\n";

$fail = 0;

/**
 * @param string $name
 * @param bool   $ok
 * @param string $detail
 */
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '[PASS] ' : '[FAIL] '), $name, $detail === '' ? '' : (' :: ' . $detail), "\n";
}

/**
 * 验收用工具桩（可参数化 access / risk / confirm）
 */
final class AcceptanceTool implements ToolInterface
{
    public function __construct(
        private string $name,
        private string $permission,
        private string $access,
        private string $risk,
        private bool $confirm
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return '验收用工具';
    }

    public function getParameters(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments): string
    {
        return 'ok';
    }

    public function getPermission(): string
    {
        return $this->permission;
    }

    public function requireConfirmation(): bool
    {
        return $this->confirm;
    }

    public function getAccess(): string
    {
        return $this->access;
    }

    public function getRiskLevel(): string
    {
        return $this->risk;
    }
}

/**
 * @param string $name
 */
function descriptor(
    string $name,
    string $permission,
    string $access,
    string $risk
): CapabilityDescriptor {
    return new CapabilityDescriptor(CapabilityDescriptor::KIND_TOOL, $name, $permission, $access, $risk);
}

$authorizer = new CapabilityAuthorizer();
$policy     = new ApprovalPolicy();

$writeCap = descriptor('demo.update', 'ai.tool.demo_update', CapabilityAccess::WRITE, CapabilityAccess::RISK_MEDIUM);

$noPermission = $authorizer->evaluateGranted($writeCap, []);
check(
    '越权-1 无任何权限 → 拒绝',
    !$noPermission->allowed && $noPermission->decidedBy === 'rbac',
    "decidedBy={$noPermission->decidedBy}"
);

$readOnly = $authorizer->evaluateGranted($writeCap, [CapabilityAccess::wildcardIdent(CapabilityAccess::READ)]);
check(
    '越权-2 只读通配不能开写操作',
    !$readOnly->allowed,
    'granted=ai.access.read.* → ' . ($readOnly->allowed ? 'allowed' : 'denied')
);

$wrongFineGrained = $authorizer->evaluateGranted($writeCap, ['ai.tool.other_tool']);
check(
    '越权-3 其他工具的细粒度权限不能串用',
    !$wrongFineGrained->allowed,
    'granted=ai.tool.other_tool'
);

$accessWildcard = $authorizer->evaluateGranted(
    $writeCap,
    [CapabilityAccess::wildcardIdent(CapabilityAccess::WRITE)]
);
check('越权-4 命中写入类别通配 → 放行', $accessWildcard->allowed, $accessWildcard->reason);

$prefixWildcard = $authorizer->evaluateGranted($writeCap, ['ai.tool.*']);
check('越权-5 命中 ai.tool.* 前缀通配 → 放行', $prefixWildcard->allowed, $prefixWildcard->reason);

$allCapability = $authorizer->evaluateGranted($writeCap, [CapabilityAccess::ALL_IDENT]);
check('越权-6 命中「全部 AI 能力」→ 放行', $allCapability->allowed, $allCapability->reason);

$plainWriteTool = new AcceptanceTool(
    'demo.update',
    'ai.tool.demo_update',
    CapabilityAccess::WRITE,
    CapabilityAccess::RISK_MEDIUM,
    false
);

$plainRequirement = $policy->decideForTool($plainWriteTool, $writeCap);
check(
    '审批-1 普通写操作默认不强制审批（不误拦）',
    !$plainRequirement->required(),
    "mode={$plainRequirement->mode}"
);

$confirmTool = new AcceptanceTool(
    'demo.publish',
    'ai.tool.demo_publish',
    CapabilityAccess::WRITE,
    CapabilityAccess::RISK_MEDIUM,
    true
);

$confirmRequirement = $policy->decideForTool(
    $confirmTool,
    descriptor('demo.publish', 'ai.tool.demo_publish', CapabilityAccess::WRITE, CapabilityAccess::RISK_MEDIUM)
);
check(
    '审批-2 能力自身要求确认 → 策略加严为 confirm',
    $confirmRequirement->required() && $confirmRequirement->mode === 'confirm',
    "mode={$confirmRequirement->mode} rule={$confirmRequirement->rule}"
);

$highRisk = descriptor('demo.delete', 'ai.tool.demo_delete', CapabilityAccess::WRITE, CapabilityAccess::RISK_HIGH);
$highRiskRequirement = $policy->resolve($highRisk);
check(
    '高危-1 risk=high（删除/出库）→ 强制确认',
    $highRiskRequirement->required() && $highRiskRequirement->mode === 'confirm',
    "rule={$highRiskRequirement->rule}"
);

$adminAccess = descriptor('demo.reset', 'ai.tool.demo_reset', CapabilityAccess::ADMIN, CapabilityAccess::RISK_MEDIUM);
$adminRequirement = $policy->resolve($adminAccess);
check(
    '高危-2 access=admin（管理类）→ 强制确认',
    $adminRequirement->required() && $adminRequirement->mode === 'confirm',
    "rule={$adminRequirement->rule}"
);

$externalTool = new AcceptanceTool(
    'remote_weather',
    'mcp:weather',
    CapabilityAccess::WRITE,
    CapabilityAccess::RISK_HIGH,
    true
);

$externalCap = descriptor('mcp:weather.remote_weather', 'mcp:weather', CapabilityAccess::WRITE, CapabilityAccess::RISK_HIGH);

$externalRequirement = $policy->decideForTool($externalTool, $externalCap);
check(
    '外部-1 外部工具强制确认（能力自身 + risk=high 双保险）',
    $externalRequirement->required() && $externalRequirement->mode === 'confirm',
    "rule={$externalRequirement->rule}"
);

$registry = ToolRegistry::newInstance();
$registry->register($externalTool, null, 'mcp:weather');

$catalog = CapabilityCatalog::build($registry);
$entry   = $catalog[0] ?? [];

check(
    '外部-2 能力目录标记 external=true',
    ($entry['external'] ?? false) === true,
    'permission=' . ($entry['permission'] ?? '')
);

check(
    '外部-3 外部能力默认关闭（default_enabled=false）',
    ($entry['default_enabled'] ?? true) === false
);

check(
    '外部-4 权限标识带 mcp: 前缀（可按前缀配规则）',
    str_starts_with((string) ($entry['permission'] ?? ''), 'mcp:')
);

echo $fail === 0
    ? "\n[verify-ai-tool-guard] 四类用例全部通过\n"
    : "\n[verify-ai-tool-guard] 失败 {$fail} 项\n";

exit($fail === 0 ? 0 : 1);
