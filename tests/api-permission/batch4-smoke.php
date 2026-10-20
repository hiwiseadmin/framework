<?php
declare(strict_types=1);

/**
 * 批次 4 冒烟（用户写操作 + 二次验证 + 审计）
 *
 * 覆盖：
 *   - POST /api/admin/write-session 的密码校验与失败计数
 *   - AdminApiWriteGuard 的无 token / 错误 token / 正确 token 三条分支
 *   - save / update / reset-password / assign-role / status 的业务规则
 *   - 非超管的越权拦截、超管保护、自我禁用保护
 *   - 写操作审计落库
 *
 * 用法：
 *   php tests/api-permission/batch4-smoke.php
 *
 * 约束：全部夹具在事务内创建，结束 rollback；写会话缓存 key 结束后清理。
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\admin\model\Admin as AdminModel;
use app\api\controller\admin\Admin as AdminApi;
use app\api\controller\admin\Auth as AuthApi;
use app\api\middleware\AdminApiWriteGuard;
use think\facade\Cache;
use think\facade\Db;
use wise\api\response\ResponseWrapper;
use wise\auth\service\RbacManager;
use wise\operationlog\model\OperationLogModel;

$results = [];

function smoke(string $name, callable $fn): void
{
    global $results;

    try {
        [$ok, $detail] = $fn();
    } catch (\Throwable $e) {
        $ok     = false;
        $detail = get_class($e) . ': ' . $e->getMessage();
    }

    $results[] = ['name' => $name, 'ok' => (bool) $ok, 'detail' => (string) $detail];
}

/**
 * @return array{0: int, 1: array}
 */
function body_of(think\Response $response): array
{
    $data = $response->getData();

    return [$response->getCode(), is_array($data) ? $data : []];
}

function api_request(array $params = [], ?int $adminId = null, array $headers = []): think\Request
{
    $request = app()->make(think\Request::class, [], true);

    if ($adminId !== null) {
        $request->admin = AdminModel::find($adminId);
    }

    if ($headers !== []) {
        $request->withHeader($headers);
    }

    $request->withGet($params);
    $request->withPost($params);

    return $request;
}

/**
 * 为指定管理员伪造一个有效写会话（等价于 write-session 成功后的缓存状态）
 *
 * 登记到 $GLOBALS['seeded_write_tokens']，结束时精确删除 —— 不要用 Cache::clear()，
 * 那会把框架/业务缓存一起清掉（配置、字典、权限缓存）。
 */
function seed_write_token(int $adminId): string
{
    $token = bin2hex(random_bytes(16));

    Cache::set('admin_api:write:' . $adminId . ':' . sha1($token), true, 600);

    $GLOBALS['seeded_write_tokens'][] = [$adminId, $token];

    return $token;
}

$GLOBALS['seeded_write_tokens'] = [];

/* ---------------------------------------------------------------------
 * 夹具
 * ------------------------------------------------------------------ */

Db::startTrans();

$now    = date('Y-m-d H:i:s');
$stamp  = substr((string) time(), -6);
$guard  = new AdminApiWriteGuard(app(ResponseWrapper::class));
$api    = new AdminApi();

$targetId = (int) Db::name('admins')->insertGetId([
    'username'    => '_tc_w_target_' . $stamp,
    'password'    => password_hash('Tc@123456', PASSWORD_DEFAULT),
    'nickname'    => 'TC 目标账号',
    'status'      => 1,
    'create_time' => $now,
    'update_time' => $now,
]);

$actorId = (int) Db::name('admins')->insertGetId([
    'username'    => '_tc_w_actor_' . $stamp,
    'password'    => password_hash('Tc@123456', PASSWORD_DEFAULT),
    'nickname'    => 'TC 普通账号',
    'status'      => 1,
    'create_time' => $now,
    'update_time' => $now,
]);

/* ---------------------------------------------------------------------
 * write-session
 * ------------------------------------------------------------------ */

smoke('write-session：密码错误 → 401', static function () use ($actorId) {
    $auth = new AuthApi();
    $req  = api_request(['password' => 'wrong-password'], $actorId);
    $req->setMethod('POST');

    [$code, $body] = body_of($auth->writeSession($req));

    return [
        $code === 401 && ($body['code'] ?? -1) !== 0,
        "HTTP={$code} remaining=" . ($body['data']['remaining'] ?? '-'),
    ];
});

smoke('write-session：密码正确 → 返回 write_token', static function () use ($actorId) {
    $auth = new AuthApi();
    $req  = api_request(['password' => 'Tc@123456'], $actorId);
    $req->setMethod('POST');

    [$code, $body] = body_of($auth->writeSession($req));
    $token         = (string) ($body['data']['write_token'] ?? '');

    if ($token !== '') {
        Cache::delete('admin_api:write:' . $actorId . ':' . sha1($token));
    }

    return [$code === 200 && $token !== '', "HTTP={$code} token_len=" . strlen($token)];
});

/* ---------------------------------------------------------------------
 * 写守卫
 * ------------------------------------------------------------------ */

smoke('写守卫：无 X-Write-Token → 403 require_write_token', static function () use ($guard) {
    $req  = api_request([], 1);
    $resp = $guard->handle($req, static fn () => json(['passed' => true]));
    [$code, $body] = body_of($resp);

    return [
        $code === 403 && ($body['data']['require_write_token'] ?? false) === true,
        "HTTP={$code}",
    ];
});

smoke('写守卫：错误 token → 403 write_token_invalid', static function () use ($guard) {
    $req  = api_request([], 1, ['X-Write-Token' => 'not-a-real-token']);
    $resp = $guard->handle($req, static fn () => json(['passed' => true]));
    [$code, $body] = body_of($resp);

    return [
        $code === 403 && ($body['data']['reason'] ?? '') === 'write_token_invalid',
        "HTTP={$code}",
    ];
});

smoke('写守卫：正确 token → 放行并写审计日志', static function () use ($guard) {
    $before = OperationLogModel::where('channel', 'admin-api')->count();

    $token = seed_write_token(1);
    $req   = api_request([], 1, ['X-Write-Token' => $token]);
    $resp  = $guard->handle($req, static fn () => json(['passed' => true]));

    $after = OperationLogModel::where('channel', 'admin-api')->count();

    return [
        (bool) ($resp->getData()['passed'] ?? false) && $after === $before + 1,
        "log {$before} -> {$after}",
    ];
});

/* ---------------------------------------------------------------------
 * 业务写操作（超管身份）
 * ------------------------------------------------------------------ */

smoke('save：创建管理员（含角色同步）', static function () use ($guard, $api, $stamp) {
    $token = seed_write_token(1);
    $req   = api_request([
        'username'         => '_tc_created_' . $stamp,
        'password'         => 'Tc@123456',
        'password_confirm' => 'Tc@123456',
        'nickname'         => 'TC 新建账号',
        'status'           => 1,
    ], 1, ['X-Write-Token' => $token]);

    $resp          = $guard->handle($req, static fn () => $api->save($req));
    [$code, $body] = body_of($resp);

    return [
        $code === 200 && ($body['code'] ?? -1) === 0 && ($body['data']['id'] ?? 0) > 0,
        "HTTP={$code} id=" . ($body['data']['id'] ?? '-') . ' msg=' . ($body['msg'] ?? ''),
    ];
});

smoke('update：修改昵称（不传 role_ids 不清空角色）', static function () use ($guard, $api, $targetId) {
    $token = seed_write_token(1);
    $req   = api_request(['nickname' => 'TC 改名后'], 1, ['X-Write-Token' => $token]);

    $resp          = $guard->handle($req, static fn () => $api->update($req, $targetId));
    [$code, $body] = body_of($resp);

    $nickname = (string) Db::name('admins')->where('id', $targetId)->value('nickname');

    return [
        $code === 200 && $nickname === 'TC 改名后',
        "HTTP={$code} nickname={$nickname} msg=" . ($body['msg'] ?? ''),
    ];
});

smoke('reset-password：重置目标账号密码', static function () use ($guard, $api, $targetId) {
    $token = seed_write_token(1);
    $req   = api_request([
        'password'         => 'New@123456',
        'password_confirm' => 'New@123456',
    ], 1, ['X-Write-Token' => $token]);

    $resp          = $guard->handle($req, static fn () => $api->resetPassword($req, $targetId));
    [$code, $body] = body_of($resp);

    $hash = (string) Db::name('admins')->where('id', $targetId)->value('password');

    return [
        $code === 200 && password_verify('New@123456', $hash),
        "HTTP={$code} verified=" . var_export(password_verify('New@123456', $hash), true),
    ];
});

smoke('reset-password：拒绝对超管(id=1)操作', static function () use ($guard, $api) {
    $token = seed_write_token(1);
    $req   = api_request(['password' => 'X@123456', 'password_confirm' => 'X@123456'], 1, ['X-Write-Token' => $token]);

    $resp          = $guard->handle($req, static fn () => $api->resetPassword($req, 1));
    [$code, $body] = body_of($resp);

    return [$code === 403, "HTTP={$code} msg=" . ($body['msg'] ?? '')];
});

smoke('assign-role：超管给目标分配角色', static function () use ($guard, $api, $targetId) {
    $roleId = (int) Db::name('auth_roles')->where('slug', '<>', (string) config('wise-auth.super_role'))->value('id');
    if ($roleId <= 0) {
        return [true, '无可用角色，跳过'];
    }

    $token = seed_write_token(1);
    $req   = api_request(['role_ids' => [$roleId]], 1, ['X-Write-Token' => $token]);

    $resp          = $guard->handle($req, static fn () => $api->assignRole($req, $targetId));
    [$code, $body] = body_of($resp);

    $count = (int) Db::name('auth_user_role')
        ->where('user_id', $targetId)
        ->where('role_id', $roleId)
        ->count();

    return [$code === 200 && $count === 1, "HTTP={$code} rows={$count}"];
});

smoke('status：禁用目标账号', static function () use ($guard, $api, $targetId) {
    $token = seed_write_token(1);
    $req   = api_request([], 1, ['X-Write-Token' => $token]);

    $resp          = $guard->handle($req, static fn () => $api->status($req, $targetId));
    [$code, $body] = body_of($resp);

    $status = (int) Db::name('admins')->where('id', $targetId)->value('status');

    return [$code === 200 && $status === 0, "HTTP={$code} status={$status}"];
});

smoke('status：拒绝切换自己的状态', static function () use ($guard, $api) {
    $token = seed_write_token(1);
    $req   = api_request([], 1, ['X-Write-Token' => $token]);

    $resp          = $guard->handle($req, static fn () => $api->status($req, 1));
    [$code, $body] = body_of($resp);

    return [$code === 403, "HTTP={$code} msg=" . ($body['msg'] ?? '')];
});

/* ---------------------------------------------------------------------
 * 越权拦截（非超管）
 * ------------------------------------------------------------------ */

smoke('update：非超管改他人 → 403', static function () use ($guard, $api, $actorId, $targetId) {
    // 让 actor 不是超管
    app(RbacManager::class)->clearAllCache();

    $token = seed_write_token($actorId);
    $req   = api_request(['nickname' => '越权改名'], $actorId, ['X-Write-Token' => $token]);

    $resp          = $guard->handle($req, static fn () => $api->update($req, $targetId));
    [$code, $body] = body_of($resp);

    return [$code === 403, "HTTP={$code} msg=" . ($body['msg'] ?? '')];
});

smoke('assign-role：非超管给自己加角色 → 403', static function () use ($guard, $api, $actorId) {
    $roleId = (int) Db::name('auth_roles')->value('id');

    $token = seed_write_token($actorId);
    $req   = api_request(['role_ids' => [$roleId]], $actorId, ['X-Write-Token' => $token]);

    $resp          = $guard->handle($req, static fn () => $api->assignRole($req, $actorId));
    [$code, $body] = body_of($resp);

    return [$code === 403, "HTTP={$code} msg=" . ($body['msg'] ?? '')];
});

/* ---------------------------------------------------------------------
 * 收尾
 * ------------------------------------------------------------------ */

Db::rollback();

// 精确清理本次写入的写会话 / 失败计数，不动其它缓存
foreach ($GLOBALS['seeded_write_tokens'] as [$adminId, $token]) {
    Cache::delete('admin_api:write:' . $adminId . ':' . sha1($token));
}
foreach ([$targetId, $actorId, 1] as $id) {
    Cache::delete('admin_api:write_fail:' . $id);
}

app(RbacManager::class)->clearAllCache();

$passed = 0;
$failed = 0;

echo PHP_EOL, '批次 4 冒烟（用户写操作）', PHP_EOL;
echo str_repeat('-', 96), PHP_EOL;
printf("%-46s %-6s %s\n", '用例', '结果', '详情');
echo str_repeat('-', 96), PHP_EOL;

foreach ($results as $row) {
    $row['ok'] ? $passed++ : $failed++;
    printf(
        "%-46s %-6s %s\n",
        mb_substr($row['name'], 0, 44),
        $row['ok'] ? 'PASS' : 'FAIL',
        mb_substr(strip_tags($row['detail']), 0, 36)
    );
}

echo str_repeat('-', 96), PHP_EOL;
printf('合计 %d 条：PASS %d / FAIL %d%s', count($results), $passed, $failed, PHP_EOL);
echo '夹具已回滚，业务数据未变更', PHP_EOL;

exit($failed === 0 ? 0 : 1);
