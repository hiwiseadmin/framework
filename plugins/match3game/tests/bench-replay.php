<?php

declare(strict_types=1);

/**
 * 压测（S5-5）—— 结算与榜单两条热路径的延迟基线
 *
 *   php src/plugins/match3game/tests/bench-replay.php [--rounds=10] [--engine=node|php] [--redis=host:port] [--json=file] [--gate]
 *
 * 【为什么不是一个 CI 门禁脚本】
 *   它测的是**耗时**，而 CI 机器性能不可控（用 p95 当门禁必然偶发红）。
 *   定位：本地/预发跑出基线 → 把数字填进 `docs/match3/12-next-phase-plan.md` §14.8，
 *   需要卡阈值时显式加 `--gate`（阈值见 `THRESHOLDS`，可按部署环境改）。
 *
 * 【能测什么 / 不能测什么】
 *   ✅ 重放引擎单次延迟（node 子进程 / php 内核）—— 提交路径里最贵的一环，且**不需要 DB**
 *   ✅ 榜单 ZSet 读（全服榜 2000 人 / 群榜 500 人）—— 需要 Redis，连不上自动 SKIP
 *   ❌ 端到端 `battle/submit`（需要框架 + DB）：只能上预发用 ab/wrk 打，本脚本打印做法
 *
 * 【退出码】0 = 跑完（或 `--gate` 且达标）；2 = 环境缺失（向量/bundle）；3 = `--gate` 未达标
 */

$base = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require $base . 'StableStringify.php';
require $base . 'Match3Random.php';
require $base . 'LevelSchemaFactory.php';
require $base . 'Match3Board.php';
require $base . 'LevelValidator.php';
require $base . 'LevelSimulator.php';
require $base . 'Match3OpsValidator.php';
require $base . 'Match3ValidationResult.php';
require $base . 'Match3ReplayEngineInterface.php';
require $base . 'Match3NullReplayEngine.php';
require $base . 'Match3FillVersion.php';
require $base . 'Match3PhpReplayEngine.php';
require $base . 'Match3NodeReplayEngine.php';

use plugin\match3game\service\level\Match3FillVersion;
use plugin\match3game\service\level\Match3NodeReplayEngine;
use plugin\match3game\service\level\Match3PhpReplayEngine;

/** 阈值（ms）—— `--gate` 时判定；按部署环境调 */
const THRESHOLDS = [
    'replay_p95' => 300.0, // 单次重放：提交整体预算 800ms，重放不该吃掉超过 1/3
    'rank_p95'   => 200.0, // 好友榜 / 榜单读
];

// ---- 参数 ----
$rounds    = 10;
$engineArg = 'node';
$redisArg  = '';
$jsonOut   = '';
$gate      = false;

foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--rounds=')) {
        $rounds = max(1, (int) substr($arg, strlen('--rounds=')));
        continue;
    }
    if (str_starts_with($arg, '--engine=')) {
        $engineArg = substr($arg, strlen('--engine='));
        continue;
    }
    if (str_starts_with($arg, '--redis=')) {
        $redisArg = substr($arg, strlen('--redis='));
        continue;
    }
    if (str_starts_with($arg, '--json=')) {
        $jsonOut = substr($arg, strlen('--json='));
        continue;
    }
    if ($arg === '--gate') {
        $gate = true;
    }
}

$repoRoot = dirname(__DIR__, 4);

/** 百分位（线性插值） */
function percentile(array $sorted, float $p): float
{
    if ($sorted === []) {
        return 0.0;
    }
    $idx = (count($sorted) - 1) * $p;
    $lo  = (int) floor($idx);
    $hi  = (int) ceil($idx);

    return $lo === $hi ? (float) $sorted[$lo] : (float) ($sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($idx - $lo));
}

/** 统计一组耗时（ms） */
function stats(array $ms): array
{
    $sorted = $ms;
    sort($sorted, SORT_NUMERIC);

    return [
        'n'    => count($sorted),
        'avg'  => round(array_sum($sorted) / max(1, count($sorted)), 1),
        'p50'  => round(percentile($sorted, 0.50), 1),
        'p95'  => round(percentile($sorted, 0.95), 1),
        'max'  => round((float) ($sorted[count($sorted) - 1] ?? 0), 1),
    ];
}

function section(string $title): void
{
    echo "\n── {$title} ──\n";
}

$report = [
    'ts'      => date('c'),
    'php'     => PHP_VERSION,
    'os'      => PHP_OS_FAMILY,
    'rounds'  => $rounds,
    'engine'  => $engineArg,
    'replay'  => [],
    'rank'    => null,
    'skipped' => [],
];

// ======================================================================
section('1. 重放引擎单次延迟');
// ======================================================================

$vectorsPath = $repoRoot . '/match3game/vectors/replay-vectors-ts.json';
$rawVectors  = is_file($vectorsPath) ? file_get_contents($vectorsPath) : null;
$vectors     = is_string($rawVectors) ? json_decode($rawVectors, true) : null;

if (!is_array($vectors) || empty($vectors['cases'])) {
    fwrite(STDERR, "[bench] 向量文件缺失: {$vectorsPath}\n");
    fwrite(STDERR, "[bench] 先跑: cd match3game && npm run vectors:replay\n");
    exit(2);
}

$schemas = (array) ($vectors['schemas'] ?? []);

/** 按 ops 规模分三档（小/中/大）—— 延迟主要随 ops 条数增长 */
$buckets = ['small' => null, 'mid' => null, 'large' => null];
foreach ($vectors['cases'] as $case) {
    if (!is_array($case) || ($case['compare'] ?? false) !== true) {
        continue;
    }
    $ops = $case['ops'] ?? null;
    if (!is_array($ops) || $ops === []) {
        continue;
    }
    if (!isset($schemas[$case['schema'] ?? ''])) {
        continue;
    }
    $n    = count($ops);
    $slot = $n <= 5 ? 'small' : ($n <= 15 ? 'mid' : 'large');
    if ($buckets[$slot] === null) {
        $buckets[$slot] = $case;
    }
}

$archived = $repoRoot . '/match3game/dist/replay-engine-' . Match3FillVersion::CURRENT . '.mjs';
$bundle   = is_file($archived) ? $archived : $repoRoot . '/match3game/dist/replay-engine.mjs';

$engine     = null;
$engineName = '';
if ($engineArg === 'php') {
    $engine     = new Match3PhpReplayEngine(['fingerprints' => true]);
    $engineName = 'php（回滚基线）';
} elseif (is_file($bundle)) {
    $engine     = new Match3NodeReplayEngine(['node' => ['script' => $bundle]]);
    $engineName = 'node（' . basename($bundle) . '，与客户端同源）';
} else {
    $report['skipped'][] = '重放引擎：bundle 缺失（cd match3game && npm run build:replay && npm run archive:replay）';
    echo "  ⊘ 重放引擎压测跳过（bundle 缺失）\n";
}

if ($engine !== null) {
    echo "  引擎: {$engineName}   每档 {$rounds} 次\n\n";
    echo "  " . str_pad('档位', 8) . str_pad('ops', 6) . str_pad('avg', 10) . str_pad('p50', 10) . str_pad('p95', 10) . "max\n";

    foreach ($buckets as $slot => $case) {
        if ($case === null) {
            continue;
        }
        $schema = $schemas[$case['schema']];
        $seed   = (int) $case['seed'];
        $ops    = $case['ops'];

        $ms = [];
        for ($i = 0; $i < $rounds; $i++) {
            $t0    = microtime(true);
            $engine->replay($schema, $seed, $ops);
            $ms[]  = (microtime(true) - $t0) * 1000;
        }

        $st                   = stats($ms);
        $st['ops']            = count($ops);
        $report['replay'][$slot] = $st;

        echo "  " . str_pad($slot, 8) . str_pad((string) count($ops), 6)
            . str_pad((string) $st['avg'], 10) . str_pad((string) $st['p50'], 10)
            . str_pad((string) $st['p95'], 10) . (string) $st['max'] . " ms\n";
    }
}

// ======================================================================
section('2. 榜单 ZSet 读（Redis）');
// ======================================================================

$redis = null;
if (class_exists('Redis')) {
    try {
        $r    = new Redis();
        $host = '127.0.0.1';
        $port = 6379;
        if ($redisArg !== '' && str_contains($redisArg, ':')) {
            [$host, $port] = explode(':', $redisArg, 2);
            $port          = (int) $port;
        }
        if (@$r->connect($host, $port, 0.5)) {
            $redis = $r;
        }
    } catch (\Throwable $e) {
        $redis = null;
    }
}

if ($redis === null) {
    $report['skipped'][] = '榜单 ZSet：Redis 不可达（--redis=host:port）';
    echo "  ⊘ Redis 不可达，榜单读压测跳过\n";
    echo "     线上执行: php src/plugins/match3game/tests/bench-replay.php --redis=127.0.0.1:6379\n";
} else {
    $keyGlobal = 'match3:rank:bench:global';
    $keyGroup  = 'match3:rank:bench:group:g1';

    // 造数据：全服榜 2000 人、群榜 500 人
    foreach ([$keyGlobal => 2000, $keyGroup => 500] as $key => $size) {
        $redis->del($key);
        $pipe = $redis->multi(Redis::PIPELINE);
        for ($i = 1; $i <= $size; $i++) {
            $pipe->zAdd($key, (float) ($size - $i), (string) (100000 + $i));
        }
        $pipe->exec();
    }

    $rankStats = [];
    foreach ([['全服榜 top100', $keyGlobal, 2000], ['群榜 top100', $keyGroup, 500]] as [$label, $key, $size]) {
        $ms = [];
        for ($i = 0; $i < $rounds; $i++) {
            $t0  = microtime(true);
            $redis->zRevRange($key, 0, 99, true);
            $ms[] = (microtime(true) - $t0) * 1000;
        }
        $st                       = stats($ms);
        $st['members']            = $size;
        $rankStats[$label]        = $st;

        echo "  " . str_pad($label, 16) . "members={$size}  avg={$st['avg']}ms  p50={$st['p50']}ms  p95={$st['p95']}ms  max={$st['max']}ms\n";
    }

    $report['rank'] = $rankStats;

    $redis->del($keyGlobal);
    $redis->del($keyGroup);
}

// ======================================================================
section('3. 端到端提交（需预发环境）');
// ======================================================================

echo <<<TXT
  本脚本不覆盖端到端（需要框架 + DB）。预发环境做法：

    # 含重放的提交（最贵的一条路径）
    ab -n 200 -c 10 -p submit.json -T application/json \
       http://<host>/api/match3game/battle/submit
    # 期望：P95 < 800ms（其中重放占上面第 1 节测出的数）

TXT;

// ======================================================================
section('4. 结论');
// ======================================================================

$violations = [];
foreach ($report['replay'] as $slot => $st) {
    if ((float) $st['p95'] > THRESHOLDS['replay_p95']) {
        $violations[] = "重放 {$slot} 档 p95 {$st['p95']}ms > " . THRESHOLDS['replay_p95'] . 'ms';
    }
}
foreach ((array) ($report['rank'] ?? []) as $label => $st) {
    if ((float) $st['p95'] > THRESHOLDS['rank_p95']) {
        $violations[] = "{$label} p95 {$st['p95']}ms > " . THRESHOLDS['rank_p95'] . 'ms';
    }
}

if ($violations === []) {
    echo "  ✓ 未超阈值（replay p95 < " . THRESHOLDS['replay_p95'] . "ms，rank p95 < " . THRESHOLDS['rank_p95'] . "ms）\n";
} else {
    foreach ($violations as $v) {
        echo "  ✗ {$v}\n";
    }
}

if ($jsonOut !== '') {
    $report['violations'] = $violations;
    @file_put_contents($jsonOut, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "\n  报告已写入: {$jsonOut}\n";
}

echo "\n";

if ($gate && $violations !== []) {
    exit(3);
}

exit(0);
