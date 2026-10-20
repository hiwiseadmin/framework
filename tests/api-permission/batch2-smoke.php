<?php
declare(strict_types=1);

/**
 * 批次 2 接口冒烟（工作台 / 消息 / 任务 / 日志）
 *
 * 验收集（run.php）覆盖的是权限链语义；本脚本覆盖「新控制器能否真正返回数据」：
 * 以超管身份、进程内直接调用控制器方法，断言 HTTP 200 与关键字段存在。
 *
 * 用法：
 *   php tests/api-permission/batch2-smoke.php
 *
 * 设计约束：
 *   - 只读：不做任何写操作（消息标记已读、任务取消/重试均不调用）；
 *   - 幂等：可重复执行，不产生数据；
 *   - 退出码：全部通过 0，否则 1。
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

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
 * 构造一个带参数、带登录管理员的请求
 *
 * 必须每次新建 Request 实例：TP 的 `Request::param()` 会缓存解析结果，
 * 复用容器里的单例会让上一个用例的 GET 参数残留（真实 HTTP 场景每次都新建，不受影响）。
 */
function api_request(array $params = []): think\Request
{
    $request        = app()->make(think\Request::class, [], true);
    $request->admin = app\admin\model\Admin::find(1);

    $request->withGet($params);

    return $request;
}

/**
 * 取响应体（数组）与 HTTP 码
 *
 * @return array{0: int, 1: array}
 */
function body_of(think\Response $response): array
{
    $data = $response->getData();

    return [$response->getCode(), is_array($data) ? $data : []];
}

/**
 * 断言：成功响应 + data 中含指定键（支持点号仅一层）
 *
 * @param string[] $keys
 */
function assert_ok(string $label, think\Response $response, array $keys = []): array
{
    [$code, $body] = body_of($response);

    if ($code !== 200 || ($body['code'] ?? -1) !== 0) {
        return [false, "HTTP {$code} code=" . ($body['code'] ?? '?') . ' msg=' . ($body['msg'] ?? '')];
    }

    $data   = $body['data'] ?? [];
    $data   = is_array($data) ? $data : [];
    $missed = array_values(array_filter($keys, static fn (string $k): bool => !array_key_exists($k, $data)));

    return [
        $missed === [],
        $missed === [] ? 'data keys: ' . implode(',', array_slice(array_keys($data), 0, 6)) : 'missing: ' . implode(',', $missed),
    ];
}

/* ---------------------------------------------------------------------
 * 工作台
 * ------------------------------------------------------------------ */

smoke('GET dashboard', static function () {
    $controller = new app\api\controller\admin\Dashboard();
    $response   = $controller->index(api_request());

    return assert_ok('dashboard', $response, ['layout', 'catalog', 'entries']);
});

smoke('GET dashboard/widgets（批取）', static function () {
    $catalog = app\common\dashboard\DashboardRegistry::catalog();
    $first   = is_array($catalog) ? (array_values($catalog)[0] ?? []) : [];
    $key     = (string) ($first['key'] ?? '');

    if ($key === '') {
        return [false, 'catalog 为空，无法取面板'];
    }

    $controller = new app\api\controller\admin\Dashboard();
    $response   = $controller->widgets(api_request(['keys' => [$key]]));

    [$code, $body] = body_of($response);
    $data          = $body['data'] ?? [];

    return [
        $code === 200 && isset($data['data']),
        "key={$key} HTTP={$code}",
    ];
});

/* ---------------------------------------------------------------------
 * 消息中心
 * ------------------------------------------------------------------ */

smoke('GET messages', static function () {
    $controller = new app\api\controller\admin\Message();
    $response   = $controller->index(api_request(['page' => 1, 'limit' => 5]));

    return assert_ok('messages', $response);
});

smoke('GET messages/stats', static function () {
    $controller = new app\api\controller\admin\Message();
    $response   = $controller->stats(api_request());

    return assert_ok('messages/stats', $response);
});

/* ---------------------------------------------------------------------
 * 任务中心
 * ------------------------------------------------------------------ */

smoke('GET tasks', static function () {
    $controller = new app\api\controller\admin\Task();
    $response   = $controller->index(api_request(['page' => 1, 'limit' => 5]));

    return assert_ok('tasks', $response, ['total', 'list']);
});

smoke('GET tasks/progress（不存在的 ID 返回空列表）', static function () {
    $controller = new app\api\controller\admin\Task();
    $response   = $controller->progress(api_request(['ids' => [999999]]));

    [$code, $body] = body_of($response);

    return [
        $code === 200 && ($body['code'] ?? -1) === 0 && array_key_exists('list', $body['data'] ?? []),
        "HTTP={$code}",
    ];
});

smoke('GET tasks/{id}（不存在 → 业务错误，不 500）', static function () {
    $controller = new app\api\controller\admin\Task();
    $response   = $controller->show(api_request(['id' => 999999]), 999999);

    [$code, $body] = body_of($response);

    return [
        $code < 500 && ($body['code'] ?? -1) !== 0,
        "HTTP={$code} code=" . ($body['code'] ?? '?'),
    ];
});

/* ---------------------------------------------------------------------
 * 日志（三类）
 * ------------------------------------------------------------------ */

foreach ([
    'logs/operation' => 'operation',
    'logs/login'     => 'login',
    'logs/api'       => 'api',
] as $label => $method) {
    smoke("GET {$label}", static function () use ($method) {
        $controller = new app\api\controller\admin\Log();
        $response   = $controller->{$method}(api_request(['page' => 1, 'limit' => 5]));

        return assert_ok($method, $response, ['total', 'list']);
    });
}

/* ---------------------------------------------------------------------
 * 导航（重构后回归）
 * ------------------------------------------------------------------ */

smoke('GET menus（重构后回归）', static function () {
    $controller = new app\api\controller\admin\Menu();
    $response   = $controller->index(api_request());

    return assert_ok('menus', $response, ['menus']);
});

/* ---------------------------------------------------------------------
 * 输出
 * ------------------------------------------------------------------ */

$passed = 0;
$failed = 0;

echo PHP_EOL, '批次 2 接口冒烟', PHP_EOL;
echo str_repeat('-', 92), PHP_EOL;
printf("%-42s %-6s %s\n", '接口', '结果', '详情');
echo str_repeat('-', 92), PHP_EOL;

foreach ($results as $row) {
    $row['ok'] ? $passed++ : $failed++;
    printf(
        "%-42s %-6s %s\n",
        mb_substr($row['name'], 0, 40),
        $row['ok'] ? 'PASS' : 'FAIL',
        mb_substr(strip_tags($row['detail']), 0, 40)
    );
}

echo str_repeat('-', 92), PHP_EOL;
printf('合计 %d 条：PASS %d / FAIL %d%s', count($results), $passed, $failed, PHP_EOL);

exit($failed === 0 ? 0 : 1);
