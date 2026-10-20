<?php
declare(strict_types=1);

/**
 * 批次 3 接口冒烟（附件 / 字典 / 角色只读 / 会员只读）
 *
 * 以超管身份、进程内直接调用控制器方法，断言 HTTP 200 与关键字段存在。
 *
 * 用法：
 *   php tests/api-permission/batch3-smoke.php
 *
 * 约束：只读、幂等、不产生数据；退出码 0/1。
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
 * 新建请求实例（TP 的 param() 有缓存，复用单例会让参数串味）
 */
function api_request(array $params = []): think\Request
{
    $request        = app()->make(think\Request::class, [], true);
    $request->admin = app\admin\model\Admin::find(1);

    $request->withGet($params);

    return $request;
}

/**
 * @return array{0: int, 1: array}
 */
function body_of(think\Response $response): array
{
    $data = $response->getData();

    return [$response->getCode(), is_array($data) ? $data : []];
}

/**
 * @param string[] $keys 需要存在于 data 中的键
 */
function assert_ok(think\Response $response, array $keys = []): array
{
    [$code, $body] = body_of($response);

    if ($code !== 200 || ($body['code'] ?? -1) !== 0) {
        return [false, "HTTP {$code} code=" . ($body['code'] ?? '?') . ' msg=' . ($body['msg'] ?? '')];
    }

    $data = is_array($body['data'] ?? null) ? $body['data'] : [];

    $missed = array_values(array_filter($keys, static fn (string $k): bool => !array_key_exists($k, $data)));

    return [
        $missed === [],
        $missed === [] ? 'keys: ' . implode(',', array_slice(array_keys($data), 0, 6)) : 'missing: ' . implode(',', $missed),
    ];
}

/* ---------------------------------------------------------------------
 * 附件
 * ------------------------------------------------------------------ */

smoke('GET attachments', static function () {
    $c = new app\api\controller\admin\Attachment();

    return assert_ok($c->index(api_request(['page' => 1, 'limit' => 5])), ['total', 'list']);
});

smoke('GET attachments/categories', static function () {
    $c = new app\api\controller\admin\Attachment();

    return assert_ok($c->categories(api_request()), ['categories']);
});

smoke('GET attachments/{id}（不存在 → 404 而非 500）', static function () {
    $c = new app\api\controller\admin\Attachment();
    [$code, $body] = body_of($c->detail(api_request(['id' => 999999]), 999999));

    return [$code < 500 && ($body['code'] ?? -1) !== 0, "HTTP={$code} code=" . ($body['code'] ?? '?')];
});

/* ---------------------------------------------------------------------
 * 字典
 * ------------------------------------------------------------------ */

smoke('GET dicts', static function () {
    $c = new app\api\controller\admin\Dict();

    return assert_ok($c->index(api_request(['page' => 1, 'limit' => 5])), ['list', 'total']);
});

smoke('GET dicts/{code} + /items（取库里第一条字典）', static function () {
    $code = (string) (wise\config\model\DictType::value('dict_code') ?? '');

    if ($code === '') {
        return [true, '库里暂无字典数据，跳过'];
    }

    $c = new app\api\controller\admin\Dict();

    [$ok1, $d1] = assert_ok($c->show(api_request(['dict_code' => $code]), $code));
    [$ok2, $d2] = assert_ok($c->items(api_request(['dict_code' => $code]), $code), ['list']);

    return [
        $ok1 && $ok2,
        "code={$code} detail=" . ($ok1 ? 'ok' : $d1) . ' items=' . ($ok2 ? 'ok' : $d2),
    ];
});

/* ---------------------------------------------------------------------
 * 角色（只读）
 * ------------------------------------------------------------------ */

smoke('GET roles', static function () {
    $c = new app\api\controller\admin\Role();

    return assert_ok($c->index(api_request(['page' => 1, 'limit' => 5])), ['total', 'list']);
});

smoke('GET roles/tree', static function () {
    $c = new app\api\controller\admin\Role();

    return assert_ok($c->tree(api_request()), ['tree']);
});

smoke('GET roles/{id}', static function () {
    $id = (int) (think\facade\Db::name('auth_roles')->value('id') ?? 0);
    if ($id <= 0) {
        return [true, '无角色数据，跳过'];
    }

    $c = new app\api\controller\admin\Role();

    return assert_ok($c->detail(api_request(['role_id' => $id]), $id), ['id', 'name', 'all_permission_ids']);
});

/* ---------------------------------------------------------------------
 * 会员（只读，复用 wise-member 的 adminapi 控制器）
 * ------------------------------------------------------------------ */

foreach ([
    'member/levels' => static fn () => new wise\member\controller\adminapi\Level($GLOBALS['app']),
    'member/tags' => static fn () => new wise\member\controller\adminapi\Tag($GLOBALS['app']),
] as $label => $factory) {
    smoke("GET {$label}", static function () use ($factory) {
        $controller = $factory();

        return assert_ok($controller->index(api_request()));
    });
}

smoke('GET member/statistics/overview', static function () {
    $controller = new wise\member\controller\adminapi\Statistic($GLOBALS['app']);

    return assert_ok($controller->overview(api_request()));
});

/* ---------------------------------------------------------------------
 * 输出
 * ------------------------------------------------------------------ */

$passed = 0;
$failed = 0;

echo PHP_EOL, '批次 3 接口冒烟', PHP_EOL;
echo str_repeat('-', 92), PHP_EOL;
printf("%-44s %-6s %s\n", '接口', '结果', '详情');
echo str_repeat('-', 92), PHP_EOL;

foreach ($results as $row) {
    $row['ok'] ? $passed++ : $failed++;
    printf(
        "%-44s %-6s %s\n",
        mb_substr($row['name'], 0, 42),
        $row['ok'] ? 'PASS' : 'FAIL',
        mb_substr(strip_tags($row['detail']), 0, 38)
    );
}

echo str_repeat('-', 92), PHP_EOL;
printf('合计 %d 条：PASS %d / FAIL %d%s', count($results), $passed, $failed, PHP_EOL);

exit($failed === 0 ? 0 : 1);
