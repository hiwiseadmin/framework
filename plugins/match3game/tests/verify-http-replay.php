<?php
declare(strict_types=1);

/**
 * HTTP 重放引擎（`replay_engine.mode = 'http'`）自检
 *
 * 【为什么需要这个脚本】`mode=node` 有 `verify-node-replay.php` 盯着它的"沉默故障防线"
 * （陈旧 bundle → 降级），但 **`mode=http` 一个用例都没有** —— 而它恰恰是**当前配置的生产模式**
 * （`config/match3.php` → `replay_engine.mode = 'http'`）。
 *
 * 2026-10-02 真实事故：常驻重放服务进程仍是 09-29 之前的引擎（磁盘 bundle 已更新但**没重启**），
 * 用它去校验新客户端 → 第三关 M3-0103「初见寒冰」（首次出现冰）合法通关被判
 * `invalid_op[1]:swapRejected:no_match`（11001）。链路缺两道保险：
 *   ① `fill_version` 没随算法改动 bump（已补：v2 → v3）；
 *   ② `Match3HttpReplayEngine` 没有 node 引擎那样的**陈旧引擎检测**（本脚本锁住它）。
 *
 * 【形态】零依赖、不连真实服务：脚本自己用 PHP 内置 server 起一个**伪造的常驻重放服务**
 * （router 文件临时生成在系统临时目录，进程退出即终止），逐个验证四条防线。
 *
 * 运行：php src/plugins/match3game/tests/verify-http-replay.php
 */

$base = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require $base . 'Match3ReplayEngineInterface.php';
require $base . 'Match3FillVersion.php';
require $base . 'Match3HttpReplayEngine.php';

use plugin\match3game\service\level\Match3FillVersion;
use plugin\match3game\service\level\Match3HttpReplayEngine;
use plugin\match3game\service\level\Match3ReplayEngineInterface;

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ✓ {$name}\n";
    } else {
        $failed++;
        echo "  ✗ {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n{$title}\n";
}

/* ======================================================================
 *  前置：curl / proc_open 是否可用（不可用则 SKIP，与 node 版同风格）
 * ====================================================================== */

if (!function_exists('curl_init')) {
    echo "SKIP：未启用 curl（mode=http 必用）\n通过 0 项，失败 0 项\n";
    exit(0);
}
if (!function_exists('proc_open')) {
    echo "SKIP：proc_open 被禁用（无法起伪造服务）\n通过 0 项，失败 0 项\n";
    exit(0);
}

echo "==============================================================\n";
echo " HTTP 重放引擎自检（mode=http · 常驻 Node 服务）\n";
echo ' PHP ' . PHP_VERSION . '   fill_version ' . Match3FillVersion::CURRENT . "\n";
echo "==============================================================\n";

/* ======================================================================
 *  伪造常驻服务：{/health, /replay} 两个端点，版本/行为由 router 文件写死
 * ====================================================================== */

$ROUTER_TPL = <<<'PHP'
<?php
$fv   = '__FV__';
$mode = '__MODE__';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
header('Content-Type: application/json; charset=utf-8');

// 'no-health'：故意让 /health 404（模拟老服务没这个端点）—— 探不通不该阻塞重放
if ($path === '/health' && $mode !== 'no-health') {
    echo json_encode(['ok' => true, 'service' => 'match3-replay', 'fillVersion' => $fv]);
    return;
}
if ($path === '/replay') {
    if ($mode === 'invalid') {
        echo json_encode(['ok' => false, 'kind' => 'invalid', 'reason' => 'invalid_op[1]:swapRejected:no_match']);
        return;
    }
    echo json_encode([
        'ok'        => true,
        'score'     => 1234,
        'moves'     => 7,
        'goals'     => ['score' => 1234],
        'maxCombo'  => 3,
        'fillVersion' => $fv,
    ]);
    return;
}
http_response_code(404);
echo '{"ok":false,"kind":"degraded","reason":"not_found"}';
PHP;

/** @var array<int,resource> 需要收尾的进程 */
$procs = [];

/** @return string 服务基址 */
function bootFake(string $fv, string $mode, int $port): string
{
    global $ROUTER_TPL, $procs;

    $router = sys_get_temp_dir() . '/m3-http-router-' . $port . '.php';
    $log    = sys_get_temp_dir() . '/m3-http-router-' . $port . '.log';
    file_put_contents($router, str_replace(['__FV__', '__MODE__'], [$fv, $mode], $ROUTER_TPL));
    @unlink($log);

    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
        [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes
    );

    if (is_resource($proc)) {
        $procs[] = $proc;
        $procs[] = $router;
        $procs[] = $log;
    }

    // 等内置 server 起来（本机通常 <100ms，给足余量以免 CI 抖动）
    usleep(500_000);

    return "http://127.0.0.1:{$port}";
}

register_shutdown_function(static function () use (&$procs): void {
    foreach ($procs as $item) {
        if (is_resource($item)) {
            proc_terminate($item);
            proc_close($item);
        } elseif (is_string($item) && is_file($item)) {
            @unlink($item);
        }
    }
});

$schema  = ['schema' => 'match3/level@1', 'board' => ['rows' => 9, 'cols' => 9], 'regions' => []];
$ops     = [['t' => 'swap', 'a' => [0, 0], 'b' => [1, 0]]];
$seed    = 42;

/* ======================================================================
 *  ① 防线：陈旧常驻服务（版本不符）→ 降级，绝不采信它的结论
 * ====================================================================== */

section('1. 陈旧服务防线（本次事故的直接成因）');

$staleUrl = bootFake('v0', 'ok', 19101);
$stale    = new Match3HttpReplayEngine($staleUrl, 1500, 1);

check(
    '服务 fillVersion 与本地不符 → 降级（返回 null，不采信分数/不拒单）',
    $stale->replay($schema, $seed, $ops) === null
);

$staleInvalidUrl = bootFake('v0', 'invalid', 19102);
$staleInvalid    = new Match3HttpReplayEngine($staleInvalidUrl, 1500, 1);

check(
    '陈旧服务报 invalid（线上形态）→ **仍降级**（不误杀玩家）',
    $staleInvalid->replay($schema, $seed, $ops) === null
);

/* ======================================================================
 *  ② 版本一致 → 正常采信（扁平字段解析不能坏）
 * ====================================================================== */

section('2. 版本一致 → 正常返回');

$okUrl = bootFake(Match3FillVersion::CURRENT, 'ok', 19103);
$ok    = new Match3HttpReplayEngine($okUrl, 1500, 1);
$okRes = $ok->replay($schema, $seed, $ops);

check('fillVersion 一致 → 返回结果（不误降级）', is_array($okRes) && isset($okRes['score']));
check('扁平字段解析：score / moves / goals / maxCombo', ($okRes['score'] ?? null) === 1234
    && ($okRes['moves'] ?? null) === 7
    && ($okRes['goals'] ?? null) === ['score' => 1234]
    && ($okRes['maxCombo'] ?? null) === 3);

/* ======================================================================
 *  ③ 版本一致 + 服务报 invalid → 拒单语义（P0 绕过口不能开）
 * ====================================================================== */

section('3. 版本一致 + invalid → 拒单（不降级）');

$invalidUrl = bootFake(Match3FillVersion::CURRENT, 'invalid', 19104);
$invalid    = new Match3HttpReplayEngine($invalidUrl, 1500, 1);
$invalidRes = $invalid->replay($schema, $seed, $ops);

check(
    '版本一致时报 invalid → 返回 INVALID_KEY（上层 invalid_op 拒单）',
    is_array($invalidRes)
    && isset($invalidRes[Match3ReplayEngineInterface::INVALID_KEY])
    && str_contains((string) $invalidRes[Match3ReplayEngineInterface::INVALID_KEY], 'swapRejected:no_match')
);

/* ======================================================================
 *  ④ 探不通 / 不可达：不得因为"探测失败"就阻断或误判
 * ====================================================================== */

section('4. 探测失败与不可达');

$noHealthUrl = bootFake(Match3FillVersion::CURRENT, 'no-health', 19105);
$noHealth    = new Match3HttpReplayEngine($noHealthUrl, 1500, 1);

check(
    '/health 不存在（老服务）→ 不阻断，仍按 /replay 结论处理',
    is_array($noHealth->replay($schema, $seed, $ops))
);

$dead = new Match3HttpReplayEngine('http://127.0.0.1:19199', 500, 1);
check('服务不可达 → 降级（返回 null）', $dead->replay($schema, $seed, $ops) === null);

/* ======================================================================
 *  汇总
 * ====================================================================== */

echo "\n----------------------------------------\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
