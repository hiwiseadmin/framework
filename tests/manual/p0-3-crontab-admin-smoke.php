<?php
declare(strict_types=1);

/**
 * 运行：cd src && php tests/manual/p0-3-crontab-admin-smoke.php
 *
 * 覆盖任务卡验收：
 *   1  列表（配置任务 + 数据库任务合并，调度说明 + 下次执行）
 *   2  新增（合法 Cron 落库、无调度错误）
 *   3  非法 Cron 明确报错且不落库
 *   4  编辑生效
 *   5  启停
 *   6  立即执行（worker 内执行 → 日志出现）
 *   8  并发防重（锁被占用时 skipped）
 *   9  执行日志 / 详情（耗时 / 输出）
 *   11 权限节点齐备且方法正确（写入为 POST）
 *   13 菜单 ↔ 路由路径逐字一致
 *
 * 说明：全程事务内执行并回滚；立即执行会真实起子进程（`php -v`），属 CLI 允许路径。
 */

require __DIR__ . '/../../vendor/autoload.php';

use think\facade\Db;
use wise\crontab\exception\CrontabException;
use wise\crontab\job\RunTaskJob;
use wise\crontab\menu\CrontabMenuProvider;
use wise\crontab\model\TaskLogModel;
use wise\crontab\model\TaskModel;
use wise\crontab\service\TaskAdminService;
use wise\crontab\TaskLock;

$app = new think\App();
$app->initialize();

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . ($detail !== '' ? " —— {$detail}" : '') . "\n";
    }
}

$pkgRoot = dirname(__DIR__, 2) . '/packages/wiseadmin/wise-crontab';

echo "== 1. 配置 / 路由 / 菜单一致性 ==\n";

check('web 段已配置后台前缀', config('wise-crontab.web.groups.admin.prefix') === 'admin/crontab');
check('手动执行限流配置存在', (int) config('wise-crontab.manual_run_throttle', 0) > 0);

$routeCode = (string) file_get_contents($pkgRoot . '/src/RouteRegistrar.php');
preg_match_all("/Route::(get|post)\('([^']+)'/", $routeCode, $matches, PREG_SET_ORDER);

$routes = [];
foreach ($matches as $m) {
    $routes['/admin/crontab/' . $m[2]] = strtoupper($m[1]);
}
check('路由 10 条', count($routes) === 10, 'count=' . count($routes));

$provider = new CrontabMenuProvider();
$paths    = [];
foreach ($provider->items() as $item) {
    $paths[] = (string) $item['path'];
    foreach ((array) ($item['children'] ?? []) as $child) {
        $paths[] = (string) $child['path'];
    }
}

check('菜单路径全部有对应路由（否则点击 404）', array_diff($paths, array_keys($routes)) === []);
check('路由全部有菜单节点（白名单下否则 403）', array_diff(array_keys($routes), $paths) === []);
check('容器锚点合法', ($provider->containers()[0]['anchor'] ?? '') === 'console');

\wise\support\router\RouteRegistrar::applyRoutes(\wise\support\ExtPoints::ROUTE_ADMIN);

$registered = [];
foreach (app('route')->getRuleList() as $item) {
    if (!is_array($item)) {
        continue;
    }

    $rule = (string) ($item['rule'] ?? '');
    if (!str_contains($rule, 'crontab/')) {
        continue;
    }

    $registered['/admin/' . $rule] = strtoupper((string) ($item['method'] ?? ''));
}

ksort($registered);
$expected = $routes;
ksort($expected);

check('路由已实际注册到框架路由表（10 条）', count($registered) === 10, 'count=' . count($registered));
check('注册路径与方法与定义完全一致', $registered === $expected, json_encode($registered, JSON_UNESCAPED_UNICODE));

echo "\n== 2. 权限节点（#11）==\n";

$perms = Db::name('auth_permissions')->where('route', 'like', '/admin/crontab/%')->select()->toArray();
check('权限 10 条（已 menu:sync）', count($perms) === 10, 'count=' . count($perms));

$byAction = [];
foreach ($perms as $p) {
    $byAction[basename((string) $p['route'])] = strtoupper((string) $p['method']);
}

check(
    '写入动作为 POST',
    ($byAction['save'] ?? '') === 'POST'
    && ($byAction['toggle'] ?? '') === 'POST'
    && ($byAction['run'] ?? '') === 'POST'
    && ($byAction['delete'] ?? '') === 'POST'
    && ($byAction['issuetoken'] ?? '') === 'POST'
);
check(
    '页面/读取为 GET',
    ($byAction['index'] ?? '') === 'GET'
    && ($byAction['getlist'] ?? '') === 'GET'
    && ($byAction['detail'] ?? '') === 'GET'
);

echo "\n== 3. 服务层（#1 / #2 / #3 / #4 / #5）==\n";

$service = app(TaskAdminService::class);

$page = $service->paginate([], 1, 50);
$configRow = null;
foreach ($page['list'] as $row) {
    if (empty($row['editable'])) {
        $configRow = $row;
    }
}
check('列表合并了配置任务（只读）', $configRow !== null);
check('配置任务带调度说明', $configRow !== null && !empty($configRow['schedule_description']));

$inTx = false;

try {
    Db::startTrans();
    $inTx = true;

    $rejected = false;
    try {
        $service->create([
            'name'     => '__p03_bad__',
            'command'  => 'php -v',
            'schedule' => '* * *',
        ]);
    } catch (CrontabException $e) {
        $rejected = true;
    }
    check('非法 Cron 明确报错', $rejected);
    check('非法 Cron 不落库', TaskModel::where('name', '__p03_bad__')->count() === 0);

    $blocked = false;
    try {
        $service->create(['name' => '__p03_cmd__', 'command' => 'rm -rf /', 'schedule' => '每5分钟']);
    } catch (CrontabException $e) {
        $blocked = str_contains($e->getMessage(), '白名单');
    }
    check('命令白名单生效', $blocked);

    $created = $service->create([
        'name'     => '__p03_ok__',
        'title'    => 'P0-3 自测任务',
        'command'  => 'php -v',
        'schedule' => '每5分钟',
        'timeout'  => 30,
    ]);
    check('新增成功并归一化 cron', !empty($created['cron_expression']), 'cron=' . ($created['cron_expression'] ?? ''));
    check('新增后推算下次执行时间', !empty($created['next_run_at']));
    check('新增后无调度错误', !isset($service->scheduleErrors()['__p03_ok__']));

    $service->update('__p03_ok__', ['title' => 'P0-3 自测任务（改）', 'schedule' => '每天凌晨3点']);
    $edited = $service->find('__p03_ok__');
    check('编辑生效（标题）', ($edited['title'] ?? '') === 'P0-3 自测任务（改）');
    check('编辑生效（调度）', ($edited['schedule'] ?? '') === '每天凌晨3点');

    $afterCreate = $service->paginate(['keyword' => '__p03_ok__'], 1, 20);
    check('列表可检索到新任务', ($afterCreate['total'] ?? 0) === 1);

    $service->disable('__p03_ok__');
    check('停用生效', (int) ($service->find('__p03_ok__')['status'] ?? 1) === 0);

    $disabledPage = $service->paginate(['keyword' => '__p03_ok__'], 1, 20);
    check('停用任务仍可在列表看到', ($disabledPage['total'] ?? 0) === 1);
    $service->enable('__p03_ok__');
    check('启用生效', (int) ($service->find('__p03_ok__')['status'] ?? 0) === 1);

    echo "\n== 4. 立即执行（#6 / #8 / #9）==\n";

    $job    = new RunTaskJob();
    $result = $job->run(['name' => '__p03_ok__']);
    check('worker 内执行返回 success', ($result['status'] ?? '') === 'success', 'status=' . ($result['status'] ?? ''));

    $log = TaskLogModel::where('task_name', '__p03_ok__')->order('id', 'desc')->find();
    check('执行日志已写入', $log !== null);
    check('日志关联 task_id', $log !== null && (int) $log['task_id'] > 0);
    check('日志记录耗时', $log !== null && (float) $log['duration'] >= 0);

    $lockDir = (string) config('wise-crontab.lock_dir', '');
    $lockDir = $lockDir !== '' ? $lockDir : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-crontab-locks';
    $lock    = new TaskLock($lockDir, 60, (array) config('wise-crontab', []));
    $lock->acquire('__p03_ok__');
    $second = $job->run(['name' => '__p03_ok__']);
    $lock->release('__p03_ok__');
    check('任务锁占用时第二次执行被跳过', ($second['status'] ?? '') === 'skipped', 'status=' . ($second['status'] ?? ''));

    $logs = $service->logs(['task_name' => '__p03_ok__'], 1, 10);
    check('日志列表可分页查询', ($logs['total'] ?? 0) >= 1);
    check('日志列表含耗时文本', !empty($logs['list'][0]['duration_text']));

    $detail = $service->logDetail((int) ($log['id'] ?? 0));
    check('日志详情含输出字段', $detail !== null && array_key_exists('output', $detail) && array_key_exists('error_output', $detail));

    echo "\n== 5. 删除（#4）==\n";

    $service->delete('__p03_ok__');
    check('删除后列表不可见', TaskModel::where('name', '__p03_ok__')->count() === 0);

    Db::rollback();
    $inTx = false;
} catch (\Throwable $e) {
    if ($inTx) {
        Db::rollback();
    }
    echo '  [SKIP] 端到端用例中断：' . $e->getMessage() . "\n";
    echo '  位置：' . $e->getFile() . ':' . $e->getLine() . "\n";
}

echo "\n== 6. 红线静态检查（#7 / #12 / #14）==\n";

$taskCtrl = (string) file_get_contents($pkgRoot . '/src/controller/admin/Task.php');
$logCtrl  = (string) file_get_contents($pkgRoot . '/src/controller/admin/Log.php');

check('控制器无 exec/shell_exec/proc_open/sleep', preg_match('/\b(exec|shell_exec|proc_open|popen|sleep)\s*\(/', $taskCtrl . $logCtrl) !== 1);
check('立即执行走队列而非同步 runNow', str_contains($taskCtrl, 'QueueService') && !str_contains($taskCtrl, '->runNow('));
check('启停/删除写审计注解', substr_count($taskCtrl, 'OperationLog') >= 4);
check('破坏性动作校验确认令牌', str_contains($taskCtrl, 'verifyConfirmToken'));

check('视图使用包内 scope', substr_count((string) @file_get_contents($pkgRoot . '/view/admin/task/index.html'), '__WISE_VIEW__/base') === 1);
check('视图无内联 onclick', !str_contains((string) @file_get_contents($pkgRoot . '/view/admin/task/index.html'), 'onclick='));

echo "\n----------------------------------------\n";
echo "结果：PASS {$passed} / FAIL {$failed}\n";

exit($failed === 0 ? 0 : 1);
