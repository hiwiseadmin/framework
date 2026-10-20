<?php
declare(strict_types=1);

use app\api\middleware\AdminApiAuth;
use app\api\middleware\AdminApiPermission;
use think\console\Input as ConsoleInput;
use think\console\Output as ConsoleOutput;
use think\facade\Db;
use wise\api\response\ResponseWrapper;
use wise\auth\facade\Rbac;
use wise\auth\service\RbacManager;
use wise\command\ApiPermissionCheck;

/**
 * 后台 App 权限（/api/admin/*）验收集 —— T6
 *
 * 覆盖《后台App权限-落地方案》第 7 节的 TC-01 ~ TC-12：
 *   鉴权链（JwtAuth → AdminApiAuth → AdminApiPermission）在此拆成后两段直接调用，
 *   跳过 HTTP 与 JWT 签名（TC-07/08/09 已覆盖验签之后的语义），
 *   换取「一条命令在本地 / CI 跑完、不依赖前端与服务器」。
 *
 * 用法：
 *   php tests/api-permission/run.php
 *   php tests/api-permission/run.php --keep     # 保留夹具数据（排障用）
 *
 * 设计约束：
 *   1. 全部数据库夹具在事务内创建，结束时 rollback —— 不污染业务数据；
 *   2. rollback 后清一次 wise-auth 缓存，避免夹具 ID 的权限缓存被后续请求复用；
 *   3. 单个用例抛异常记为 FAIL，不中断整轮（便于一次看全问题）；
 *   4. 退出码：全部通过 0，否则 1（可直接进 CI）。
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

$keepFixtures = in_array('--keep', $argv, true);

/* ---------------------------------------------------------------------
 * 断言框架
 * ------------------------------------------------------------------ */

$results = [];

/**
 * @param string   $id    用例编号
 * @param string   $title 用例标题
 * @param callable $fn    返回 [bool $ok, string $detail]
 */
function case_run(string $id, string $title, callable $fn): void
{
    global $results;

    try {
        [$ok, $detail] = $fn();
    } catch (\Throwable $e) {
        $ok     = false;
        $detail = get_class($e) . ': ' . $e->getMessage();
    }

    $results[] = ['id' => $id, 'title' => $title, 'ok' => (bool) $ok, 'detail' => (string) $detail];
}

/* ---------------------------------------------------------------------
 * 夹具：临时角色 + 有权限账号 + 无权限账号 + 禁用账号
 * ------------------------------------------------------------------ */

$fixtures = [
    'role_id'     => 0,
    'perm_id'     => 0,
    'allowed_id'  => 0,
    'denied_id'   => 0,
    'disabled_id' => 0,
];

$permIdent = 'admin.admin.index';

Db::startTrans();

try {
    $permId = (int) Db::name('auth_permissions')
        ->where('ident', $permIdent)
        ->where('status', 1)
        ->value('id');

    if ($permId <= 0) {
        throw new RuntimeException("前置条件不满足：权限节点 {$permIdent} 不存在，请先跑 php think api:perm-check");
    }
    $fixtures['perm_id'] = $permId;

    $stamp = substr((string) time(), -6);
    $now   = date('Y-m-d H:i:s');

    $roleId = (int) Db::name('auth_roles')->insertGetId([
        'name'        => 'TC App 权限角色',
        'slug'        => '_tc_app_' . $stamp,
        'description' => 'tests/api-permission/run.php 临时夹具（事务回滚）',
        'parent_id'   => 0,
        'path'        => '',
        'level'       => 0,
        'is_inherit'  => 1,
        'sort'        => 0,
        'status'      => 1,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $fixtures['role_id'] = $roleId;

    Db::name('auth_role_permission')->insert([
        'role_id'       => $roleId,
        'permission_id' => $permId,
    ]);

    $makeAdmin = static function (string $suffix, int $status) use ($stamp, $now): int {
        return (int) Db::name('admins')->insertGetId([
            'username'    => '_tc_app_' . $suffix . '_' . $stamp,
            'password'    => password_hash('Tc@123456', PASSWORD_DEFAULT),
            'nickname'    => 'TC ' . $suffix,
            'status'      => $status,
            'create_time' => $now,
            'update_time' => $now,
        ]);
    };

    $fixtures['allowed_id']  = $makeAdmin('allowed', 1);
    $fixtures['denied_id']   = $makeAdmin('denied', 1);
    $fixtures['disabled_id'] = $makeAdmin('disabled', 0);

    Db::name('auth_user_role')->insert([
        'user_type'   => 'admin',
        'user_id'     => $fixtures['allowed_id'],
        'role_id'     => $roleId,
        'create_time' => $now,
        'update_time' => $now,
    ]);
} catch (\Throwable $e) {
    Db::rollback();
    fwrite(STDERR, '夹具创建失败，无法继续：' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// 夹具就绪后清缓存，确保 checkAny 读到新数据
Rbac::clearAllCache();

/* ---------------------------------------------------------------------
 * 调用辅助
 * ------------------------------------------------------------------ */

$wrapper = app(ResponseWrapper::class);
$authMw  = new AdminApiAuth($wrapper);
$permMw  = new AdminApiPermission($wrapper);

/**
 * 走一遍「AdminApiAuth → AdminApiPermission」
 *
 * @param  array|null $payload  伪造的 jwt_payload（null = 未带 token）
 * @param  string[]   $idents   路由声明的 ident（空数组 = 挂了中间件但未声明）
 * @param  bool       $withPerm 是否挂 AdminApiPermission（对应 A 组 / B 组）
 * @return array{passed: bool, code: int, msg: string, reason: string}
 */
function chain(?array $payload = null, array $idents = [], bool $withPerm = true): array
{
    global $authMw, $permMw;

    $request              = app('request');
    $request->jwt_payload = $payload;
    $request->admin       = null;

    $next = static fn () => json(['passed' => true]);

    $response = $authMw->handle($request, $next);
    $body     = $response->getData();

    if (($body['passed'] ?? false) !== true) {
        return [
            'passed' => false,
            'code'   => $response->getCode(),
            'msg'    => (string) ($body['msg'] ?? ''),
            'reason' => (string) ($body['data']['reason'] ?? ''),
        ];
    }

    if (!$withPerm) {
        return ['passed' => true, 'code' => 200, 'msg' => '', 'reason' => ''];
    }

    $response = $permMw->handle($request, $next, ...$idents);
    $body     = $response->getData();

    return [
        'passed' => ($body['passed'] ?? false) === true,
        'code'   => $response->getCode(),
        'msg'    => (string) ($body['msg'] ?? ''),
        'reason' => (string) ($body['data']['reason'] ?? ''),
    ];
}

/**
 * 构造 jwt_payload（sub = admin:{id}）
 */
function payload(int $adminId, array $override = []): array
{
    return array_merge([
        'sub'   => 'admin:' . $adminId,
        'ver'   => app(RbacManager::class)->getUserPermissionVersion($adminId, 'admin'),
        'scope' => 'access',
    ], $override);
}

/**
 * 以指定参数跑一次 api:perm-check
 *
 * @return array{0: int, 1: string} [退出码, 输出文本]
 */
function run_perm_check(string ...$args): array
{
    $command = new ApiPermissionCheck();
    $output  = new ConsoleOutput('buffer');
    // Input 的 tokens 只承载「命令之后的参数」：脚本名与命令名不参与 bind，
    // 否则会被当成多余的位置参数抛 Too many arguments
    $input   = new ConsoleInput($args);

    $code = $command->run($input, $output);

    return [(int) $code, (string) $output->fetch()];
}

/* ---------------------------------------------------------------------
 * TC-01 ~ TC-10：鉴权链语义
 * ------------------------------------------------------------------ */

case_run('TC-01', '超管（super_admin_ids）调 B 组接口', static function () {
    $r = chain(payload(1), ['admin.admin.index']);

    return [$r['passed'], $r['passed'] ? '放行' : "拦截 code={$r['code']} msg={$r['msg']}"];
});

case_run('TC-02', '角色持有该 ident 的账号', static function () use ($fixtures) {
    $r = chain(payload($fixtures['allowed_id']), ['admin.admin.index']);

    return [$r['passed'], $r['passed'] ? '放行' : "拦截 code={$r['code']} msg={$r['msg']}"];
});

case_run('TC-03', '角色无该 ident 的账号', static function () use ($fixtures) {
    $r = chain(payload($fixtures['denied_id']), ['admin.admin.index']);

    return [
        !$r['passed'] && $r['code'] === 403 && $r['reason'] === 'not_granted',
        "code={$r['code']} reason={$r['reason']}",
    ];
});

case_run('TC-04', '路由声明了库里不存在的 ident', static function () use ($fixtures) {
    $r = chain(payload($fixtures['allowed_id']), ['admin.admin.nonexistent']);

    return [
        !$r['passed'] && $r['code'] === 403 && $r['reason'] === 'ident_missing',
        "code={$r['code']} reason={$r['reason']}",
    ];
});

case_run('TC-05', 'B 组挂了中间件但未写 ident（fail_closed）', static function () use ($fixtures) {
    $r = chain(payload($fixtures['allowed_id']), []);

    return [
        !$r['passed'] && $r['code'] === 403 && str_contains($r['msg'], '未声明权限标识'),
        "code={$r['code']} msg={$r['msg']}",
    ];
});

case_run('TC-06', 'A 组接口（无业务权限账号，仅验身份）', static function () use ($fixtures) {
    $r = chain(payload($fixtures['denied_id']), [], false);

    return [$r['passed'], $r['passed'] ? '放行' : "拦截 code={$r['code']} msg={$r['msg']}"];
});

case_run('TC-07', '未携带 token', static function () {
    $r = chain(null, ['admin.admin.index']);

    return [!$r['passed'] && $r['code'] === 401, "code={$r['code']} msg={$r['msg']}"];
});

case_run('TC-08', 'scope=pending_2fa 的 token', static function () use ($fixtures) {
    $r = chain(payload($fixtures['allowed_id'], ['scope' => 'pending_2fa']), ['admin.admin.index']);

    return [
        !$r['passed'] && $r['code'] === 401 && str_contains($r['msg'], '双因素'),
        "code={$r['code']} msg={$r['msg']}",
    ];
});

case_run('TC-09', '角色变更后旧 token（ver 不一致）', static function () use ($fixtures) {
    $id      = $fixtures['allowed_id'];
    $version = app(RbacManager::class)->incrementPermissionVersion($id, 'admin'); // 模拟角色变更
    $r       = chain(payload($id, ['ver' => $version - 1]), ['admin.admin.index']);

    return [
        !$r['passed'] && $r['code'] === 401 && str_contains($r['msg'], '权限已变更'),
        "code={$r['code']} msg={$r['msg']}",
    ];
});

case_run('TC-10', '账号 status=0（被禁用）', static function () use ($fixtures) {
    $r = chain(payload($fixtures['disabled_id']), ['admin.admin.index']);

    return [
        !$r['passed'] && $r['code'] === 403 && str_contains($r['msg'], '禁用'),
        "code={$r['code']} msg={$r['msg']}",
    ];
});

/* ---------------------------------------------------------------------
 * TC-11 ~ TC-12：对账命令
 * ------------------------------------------------------------------ */

case_run('TC-11', 'api:perm-check 在真实路由文件上执行', static function () {
    [$code, $text] = run_perm_check();

    $firstLine = trim((string) strtok($text, "\n"));

    return [$code === 0 && str_contains($text, '合计'), "exit={$code} | {$firstLine}"];
});

case_run('TC-12', 'api:perm-check --strict 遇 ERROR 退出码 1 + did you mean', static function () use ($app) {
    $file = $app->getRuntimePath() . 'tc_api_perm_routes.php';

    file_put_contents($file, <<<'PHP'
<?php
use app\api\middleware\AdminApiPermission;

Route::get('tc', 'x')->middleware([[AdminApiPermission::class, 'admin.admin.indexx']]);
PHP);

    [$code, $text] = run_perm_check('--route-file=' . $file, '--strict');

    @unlink($file);

    $ok = $code === 1 && str_contains($text, 'did you mean') && str_contains($text, 'ERROR');

    return [
        $ok,
        "exit={$code} did_you_mean=" . (str_contains($text, 'did you mean') ? 'yes' : 'no'),
    ];
});

/* ---------------------------------------------------------------------
 * 收尾
 * ------------------------------------------------------------------ */

if ($keepFixtures) {
    Db::commit();
    $note = '夹具已保留（--keep）：role=' . $fixtures['role_id']
        . ' allowed=' . $fixtures['allowed_id']
        . ' denied=' . $fixtures['denied_id']
        . ' disabled=' . $fixtures['disabled_id'];
} else {
    Db::rollback();
    $note = '夹具已回滚，业务数据未变更';
}

Rbac::clearAllCache();

/* ---------------------------------------------------------------------
 * 输出
 * ------------------------------------------------------------------ */

$passed = 0;
$failed = 0;

echo PHP_EOL, '后台 App 权限验收集（T6）', PHP_EOL;
echo str_repeat('-', 100), PHP_EOL;
printf("%-7s %-44s %-6s %s\n", '用例', '场景', '结果', '详情');
echo str_repeat('-', 100), PHP_EOL;

foreach ($results as $row) {
    $row['ok'] ? $passed++ : $failed++;
    printf(
        "%-7s %-44s %-6s %s\n",
        $row['id'],
        mb_substr($row['title'], 0, 42),
        $row['ok'] ? 'PASS' : 'FAIL',
        mb_substr(strip_tags($row['detail']), 0, 58)
    );
}

echo str_repeat('-', 100), PHP_EOL;
printf('合计 %d 条：PASS %d / FAIL %d%s', count($results), $passed, $failed, PHP_EOL);
echo $note, PHP_EOL, PHP_EOL;

exit($failed === 0 ? 0 : 1);
