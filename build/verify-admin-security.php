<?php
/**
 * 后台安全访问策略验收
 *
 * 覆盖：
 *   1. 管理员角色分配（非超管不得分配超管角色）
 *   2. 角色权限自我授予（非超管只能授予自己持有的权限）
 *   3. 权限节点变更（仅超管）
 *   4. 私有配置读写（仅超管）
 *   5. 异步任务归属（非超管只能访问自己的任务）
 *   6. 控制器接线（防止策略类存在但控制器没调用）
 *
 * 用法：php build/verify-admin-security.php（或 npm run verify:admin-security）
 * 退出码：0 全通过；1 存在失败项
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use app\common\security\AccessPolicy;

echo "[verify-admin-security] 后台安全访问策略\n";

$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '[PASS] ' : '[FAIL] '), $name, $detail === '' ? '' : (' :: ' . $detail), "\n";
}

check('超管可管理其他管理员', AccessPolicy::canManageAdmin(true, 2, 3));
check('非超管只能管理自己', AccessPolicy::canManageAdmin(false, 2, 2));
check('非超管不能管理他人', !AccessPolicy::canManageAdmin(false, 2, 3));

check('超管可分配超管角色', AccessPolicy::canAssignRoles(true, ['super_admin'], 'super_admin'));
check('非超管不能分配超管角色', !AccessPolicy::canAssignRoles(false, ['editor', 'super_admin'], 'super_admin'));
check('非超管可分配普通角色', AccessPolicy::canAssignRoles(false, ['editor'], 'super_admin'));

check('非超管不能编辑超管角色', !AccessPolicy::canEditRole(false, [2], 9, 'super_admin', 'super_admin'));
check('非超管不能编辑自己所属角色', !AccessPolicy::canEditRole(false, [2, 3], 3, 'editor', 'super_admin'));
check('非超管可编辑其他普通角色', AccessPolicy::canEditRole(false, [2], 3, 'editor', 'super_admin'));
check('超管可编辑任意角色', AccessPolicy::canEditRole(true, [1], 9, 'super_admin', 'super_admin'));

check('非超管不能授予未持有权限', !AccessPolicy::canGrantPermissions(false, [10, 11], [10, 12]));
check('非超管可授予已持有权限', AccessPolicy::canGrantPermissions(false, [10, 11], [10, 11]));
check('超管可授予任意权限', AccessPolicy::canGrantPermissions(true, [], [10, 12]));

check('非超管不能读私有配置', !AccessPolicy::canAccessConfig(false, 0));
check('非超管可读公开配置', AccessPolicy::canAccessConfig(false, 1));
check('超管可读私有配置', AccessPolicy::canAccessConfig(true, 0));

check('非超管可访问自己的任务', AccessPolicy::canAccessTask(false, 2, 2));
check('非超管不能访问他人任务', !AccessPolicy::canAccessTask(false, 2, 3));
check('超管可访问任意任务', AccessPolicy::canAccessTask(true, 2, 3));

check('非超管不能修改权限节点', !AccessPolicy::canMutatePermission(false));
check('超管可以修改权限节点', AccessPolicy::canMutatePermission(true));

$root = dirname(__DIR__);

$wireChecks = [
    'Admin::guardRoleIds 接线' => [
        $root . '/app/admin/controller/Admin.php',
        ['$this->guardRoleIds($roleIds);', 'protected function guardRoleIds'],
    ],
    'Role::guardRoleEditable / guardPermissionGrant 接线' => [
        $root . '/app/admin/controller/Role.php',
        ['$this->guardRoleEditable($role);', '$this->guardPermissionGrant(', 'protected function guardRoleEditable'],
    ],
    'Role 权限矩阵返回前端可编辑性' => [
        $root . '/app/admin/controller/Role.php',
        ["'access'", "'editableRoleIds'", "'grantablePermissionIds'", 'admin.role.getpermissionmatrix', 'admin.role.batchassign'],
    ],
    'AdminService::getPermissionIds 返回真实权限 ID' => [
        $root . '/app/admin/service/AdminService.php',
        ['resolveRolesPermissionIds', 'getPermissionIds'],
    ],
    '角色权限前端只读/不可授予态' => [
        $root . '/public/statics/admin/role.js',
        ['perm-readonly', 'perm-locked', 'data-grantable', 'getGrantableChildrenIds', 'perm-access-notice'],
    ],
    '角色权限页面提示区域与图例' => [
        $root . '/app/admin/view/role/index.html',
        ['perm-access-notice', 'l-locked', '不可授予'],
    ],
    'Permission::canMutatePermission 接线' => [
        $root . '/app/admin/controller/Permission.php',
        ['$this->canMutatePermission()', 'protected function canMutatePermission'],
    ],
    'WiseConfig::canAccessConfig 接线' => [
        $root . '/app/admin/controller/WiseConfig.php',
        ['$this->canAccessConfig($config)', 'private function canAccessConfig'],
    ],
    'AsyncTask::canAccessTask 接线' => [
        $root . '/app/admin/controller/AsyncTask.php',
        ['$this->canAccessTask($task)', "where('created_by'", 'protected function canAccessTask'],
    ],
    'API Task::canAccessTask 接线' => [
        $root . '/app/api/controller/admin/Task.php',
        ['$this->canAccessTask($admin, $task)', "where('created_by'", 'protected function canAccessTask'],
    ],
    'Index::config 清缓存鉴权' => [
        $root . '/app/admin/controller/Index.php',
        ['if (!$this->canClearCache())', '无权限清理配置缓存'],
    ],
    'ToolSecurityCheck fail-closed' => [
        $root . '/packages/wiseadmin/wise-agent/src/listener/ToolSecurityCheck.php',
        ['$this->blockUnknown($event);', 'protected function blockUnknown'],
    ],
    'ConsoleCommandToolProvider 事件链' => [
        $root . '/packages/wiseadmin/wise-agent/src/console/ConsoleCommandToolProvider.php',
        ['ToolExecute::PHASE_BEFORE', 'Event::trigger($before)'],
    ],
    'CommandTool 执行审计' => [
        $root . '/packages/wiseadmin/wise-support/src/tools/CommandTool.php',
        ['command_exec ', "Log::write(\$message, 'info')"],
    ],
];

foreach ($wireChecks as $name => [$file, $needles]) {
    $source = is_file($file) ? (string) file_get_contents($file) : '';
    $missing = [];
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            $missing[] = $needle;
        }
    }
    check($name, $missing === [], $missing === [] ? '' : 'missing: ' . implode(' | ', $missing));
}

echo $fail === 0
    ? "\n[verify-admin-security] 全部通过 ✓\n"
    : "\n[verify-admin-security] 失败 {$fail} 项\n";

exit($fail === 0 ? 0 : 1);
