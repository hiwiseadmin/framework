<?php
/**
 * Node 重放引擎自检（**后端复用前端 TS 算法**，算法单一实现）
 *
 *   php src/plugins/match3game/tests/verify-node-replay.php
 *
 * 【为什么需要这个脚本】
 *   `mode=node` 把"算分"的权力交给了另一个进程。引入的风险不再是"分数算得对不对"
 *   （那是同一份 TS 代码的事），而是**这条桥本身**：
 *     · 子进程起不来 / 超时 / 输出不成 JSON → 必须降级，绝不能误杀玩家
 *     · 磁盘上的 bundle 是**旧版本**（陈旧构建）→ 会用旧算法判新客户端 = 事故
 *     · bundle 缺失 → 必须回落 PHP 引擎并告警，而不是让结算整体失败
 *
 * 【覆盖】
 *   1. 与客户端向量逐值一致（72 例：客户端产出的 ops，node 引擎必须复现同一结果）
 *   2. 与 PHP 引擎的关系：swap-only 完全一致；prop 清格类**按已知分歧不一致**（换引擎的依据）
 *   3. 降级语义：非法 op / 不支持 schema / 道具未定 / ops 超长 → 返回 null（不抛异常）
 *   4. 沉默故障防线：陈旧 bundle（fillVersion 不符）、子进程超时、bundle 缺失回落
 *
 * 【bundle 缺失时】
 *   本脚本打印 SKIP 并以 0 退出（不改判 CI）—— 构建步骤在**前端仓库**，
 *   后端 CI 不该因为"没 npm install"而红。要在 CI 里真正生效：
 *       cd match3game && npm ci && npm run build:replay
 */

declare(strict_types=1);

$base = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require $base . 'StableStringify.php';
require $base . 'Match3Random.php';
require $base . 'Match3Board.php';
require $base . 'LevelSchemaFactory.php';
require $base . 'Match3OpsValidator.php';
require $base . 'Match3ValidationResult.php';
require $base . 'Match3ReplayEngineInterface.php';
require $base . 'Match3NullReplayEngine.php';
require $base . 'Match3FillVersion.php';
require $base . 'Match3PhpReplayEngine.php';
require $base . 'Match3NodeReplayEngine.php';
require $base . 'ReplayEngineFactory.php';

use plugin\match3game\service\level\Match3NodeReplayEngine;
use plugin\match3game\service\level\Match3PhpReplayEngine;
use plugin\match3game\service\level\Match3ReplayEngineInterface;
use plugin\match3game\service\level\ReplayEngineFactory;

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

$repoRoot    = dirname(__DIR__, 4);
$bundlePath  = $repoRoot . '/match3game/dist/replay-engine.mjs';
$vectorsFile = $repoRoot . '/match3game/vectors/replay-vectors-ts.json';

/* ======================================================================
 *  前置：bundle / node 是否就绪
 * ====================================================================== */

exec('node --version 2>&1', $nodeOut, $nodeCode);
$nodeVersion = $nodeCode === 0 ? trim(implode(' ', $nodeOut)) : '';

if (!is_file($bundlePath)) {
    echo "SKIP：bundle 不存在 —— {$bundlePath}\n";
    echo "      构建方式：cd match3game && npm ci && npm run build:replay\n";
    echo "通过 0 项，失败 0 项\n";
    exit(0);
}

if ($nodeVersion === '') {
    echo "SKIP：PATH 中找不到 node（mode=node 会回落 PHP 引擎）\n";
    echo "通过 0 项，失败 0 项\n";
    exit(0);
}

if (!is_file($vectorsFile)) {
    echo "SKIP：客户端向量不存在 —— {$vectorsFile}\n";
    echo "      生成方式：cd match3game && npm run vectors:replay\n";
    echo "通过 0 项，失败 0 项\n";
    exit(0);
}

echo "==============================================================\n";
echo " Node 重放引擎自检（后端复用前端 TS 算法）\n";
echo " bundle : " . $bundlePath . "\n";
echo " node   : " . $nodeVersion . "   PHP " . PHP_VERSION . "\n";
echo "==============================================================\n";

$engine = new Match3NodeReplayEngine(['node' => ['script' => $bundlePath]]);
$phpEngine = new Match3PhpReplayEngine();

$vectors = json_decode((string) file_get_contents($vectorsFile), true);
$schemas = (array) ($vectors['schemas'] ?? []);
$cases   = (array) ($vectors['cases'] ?? []);

$comparable = array_values(array_filter($cases, static fn (array $c): bool => ($c['compare'] ?? true) !== false));
$plain      = $schemas['plain9x9'] ?? null;
$goodOps    = (array) ($comparable[0]['ops'] ?? []);
// ⚠️ 必须用该用例自己的 seed：换 seed 就是换棋盘，ops 必然被重放器判为非法
$goodSeed   = (int) ($comparable[0]['seed'] ?? 0);

if (!is_array($plain) || $goodOps === []) {
    echo "SKIP：向量文件缺少 plain9x9 / 首个用例 ops\n";
    echo "通过 0 项，失败 0 项\n";
    exit(0);
}

/* ======================================================================
 *  1. 与客户端向量逐值一致
 * ====================================================================== */

section('1. 与客户端向量逐值一致（ops 来自客户端 BattleLoop）');

/**
 * 抽样步长
 *
 * 每次 replay = 启动一个 node 子进程（实测 ~90ms），全量 72 例要 ~19 秒，
 * 会让 `run-all.php`（设计目标"秒级"）失去意义。这里做**等距抽样**：
 * 用例是按 schema/玩家模型成块生成的，每 3 个取 1 个仍能覆盖全部形态。
 * 需要全量时传 `--all`（CI 里可挂独立 job）。
 */
$checkAll     = in_array('--all', $argv ?? [], true);
$sampleStep   = $checkAll ? 1 : 3;
$mismatches = [];
$degraded   = [];
$checked    = 0;
$skipped    = 0;

foreach ($comparable as $caseIndex => $case) {
    if ($caseIndex % $sampleStep !== 0) {
        $skipped++;
        continue;
    }

    $schemaName = (string) ($case['schema'] ?? '');
    $schema     = $schemas[$schemaName] ?? null;
    if (!is_array($schema)) {
        continue;
    }

    $ts     = (array) ($case['ts'] ?? []);
    $result = $engine->replay($schema, (int) ($case['seed'] ?? 0), (array) ($case['ops'] ?? []));

    if ($result === null) {
        $degraded[] = (string) ($case['id'] ?? '?');
        continue;
    }

    $checked++;

    $norm = static function ($map): array {
        $arr = is_array($map) ? $map : [];
        $out = [];
        foreach ($arr as $k => $v) {
            $out[(string) $k] = (int) $v;
        }
        ksort($out);

        return $out;
    };

    $tsGoals = $norm($ts['goals'] ?? []);
    unset($tsGoals['score']);
    $reGoals = $norm($result['goals'] ?? []);
    unset($reGoals['score']);

    $diff = array_filter([
        'score'     => (int) ($ts['score'] ?? 0) !== (int) $result['score'],
        'moves'     => (int) ($ts['moves'] ?? 0) !== (int) $result['moves'],
        'maxCombo'  => (int) ($ts['maxCombo'] ?? 0) !== (int) $result['maxCombo'],
        'goals'     => $tsGoals !== $reGoals,
        'collected' => $norm($ts['collected'] ?? []) !== $norm($result['collected'] ?? []),
        'cleared'   => $norm($ts['cleared'] ?? []) !== $norm($result['cleared'] ?? []),
        'fingerprint' => (string) ($ts['boardFingerprint'] ?? '') !== (string) $result['boardFingerprint'],
    ]);

    if ($diff !== []) {
        $mismatches[] = (string) ($case['id'] ?? '?') . ' [' . implode(',', array_keys($diff)) . ']';
    }
}

check(
    "逐值一致（抽检 {$checked} 例"
    . ($skipped > 0 ? "，跳过 {$skipped} 例；全量请加 --all" : '（全量）')
    . ')',
    $mismatches === [] && $degraded === [],
    $mismatches === [] ? '' : '不一致: ' . implode(' | ', array_slice($mismatches, 0, 5))
);
check(
    '无降级（node 引擎对所有用例都给出了结果）',
    $degraded === [],
    implode(', ', array_slice($degraded, 0, 5))
);

$versionProbe = $engine->replay($plain, $goodSeed, $goodOps);
check(
    '填充算法版本一致（fillVersion）',
    is_array($versionProbe) && (string) ($versionProbe['fillVersion'] ?? '') === Match3NodeReplayEngine::FILL_VERSION,
    is_array($versionProbe) ? 'bundle=' . (string) ($versionProbe['fillVersion'] ?? '?') : '降级'
);

/* ======================================================================
 *  2. 与 PHP 引擎的关系
 * ====================================================================== */

section('2. 与 PHP 引擎的关系（换引擎的依据）');

$swapOnlyDiff   = 0;
$swapOnlyTotal  = 0;
$propDiff       = 0;
$propTotal      = 0;

foreach ($comparable as $caseIndex => $case) {
    if ($caseIndex % $sampleStep !== 0) {
        continue; // 与 §1 同一抽样口径（每次比较要起 2 个子进程）
    }

    $schema = $schemas[(string) ($case['schema'] ?? '')] ?? null;
    if (!is_array($schema)) {
        continue;
    }

    $ops     = (array) ($case['ops'] ?? []);
    $seed    = (int) ($case['seed'] ?? 0);
    $hasProp = false;
    foreach ($ops as $op) {
        if (is_array($op) && ($op['t'] ?? '') === 'prop') {
            $hasProp = true;
            break;
        }
    }

    $nodeRes = $engine->replay($schema, $seed, $ops);
    $phpRes  = $phpEngine->replay($schema, $seed, $ops);
    if ($nodeRes === null || $phpRes === null) {
        continue;
    }

    $same = (int) $nodeRes['score'] === (int) $phpRes['score']
        && (string) $nodeRes['boardFingerprint'] === (string) $phpRes['boardFingerprint'];

    if ($hasProp) {
        $propTotal++;
        if (!$same) {
            $propDiff++;
        }
    } else {
        $swapOnlyTotal++;
        if (!$same) {
            $swapOnlyDiff++;
        }
    }
}

check(
    "纯 swap 用例：node 与 php 结果完全一致（{$swapOnlyTotal} 例）",
    $swapOnlyDiff === 0,
    "不一致 {$swapOnlyDiff} 例"
);
check(
    '含 prop 用例：node 与 php **按已知分歧不一致**（证明换引擎不是"没差别"）',
    $propTotal > 0 && $propDiff > 0,
    "prop 用例 {$propTotal} 例，其中不一致 {$propDiff} 例"
);

/* ======================================================================
 *  3. 降级语义（一律返回 null，不抛异常、不误杀）
 * ====================================================================== */

section('3. 降级语义与拒单语义');

$isInvalid = static fn (?array $r): bool => is_array($r) && isset($r[Match3ReplayEngineInterface::INVALID_KEY]);

check(
    '不相邻的交换 → invalid（拒单，**不再**降级）',
    $isInvalid($engine->replay($plain, 42, [['t' => 'swap', 'a' => [0, 0], 'b' => [2, 0]]]))
);

check(
    '未知操作类型 → invalid（拒单）',
    $isInvalid($engine->replay($plain, 42, [['t' => 'teleport', 'a' => [0, 0]]]))
);

$exotic = $plain;
$exotic['regions'][0]['layers']['terrain'][0] = '>oooooooo';
check('传送带地形 → 降级', $engine->replay($exotic, 42, $goodOps) === null);

check(
    'rainbow 道具（语义未定）→ 降级',
    $engine->replay($plain, 42, [['t' => 'prop', 'p' => 'rainbow', 'at' => [0, 0]]]) === null
);

check(
    'ops 超长（>2000）→ 降级',
    $engine->replay($plain, 42, array_fill(0, 2001, ['t' => 'swap', 'a' => [0, 0], 'b' => [0, 1]])) === null
);

$empty = $engine->replay($plain, 42, []);
check('空 ops → 合法（0 分），不是降级', is_array($empty) && (int) $empty['score'] === 0);

/* ======================================================================
 *  4. 沉默故障防线（陈旧 bundle / 超时 / 缺失回落）
 * ====================================================================== */

section('4. 沉默故障防线');

$stubDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'm3-node-stub-' . getmypid();
@mkdir($stubDir);
register_shutdown_function(static function () use ($stubDir): void {
    foreach (['stale.mjs', 'slow.mjs'] as $file) {
        @unlink($stubDir . DIRECTORY_SEPARATOR . $file);
    }
    @rmdir($stubDir);
});

// ① 陈旧 bundle：返回的 fillVersion 与本地常量不符 → 必须降级（否则用旧算法判新客户端）
file_put_contents(
    $stubDir . '/stale.mjs',
    "process.stdin.resume();process.stdin.on('end',()=>process.stdout.write(JSON.stringify({ok:true,result:{score:1,fillVersion:'v0'}})));"
);
$stale = new Match3NodeReplayEngine(['node' => ['script' => $stubDir . '/stale.mjs']]);
check('陈旧 bundle（fillVersion=v0）→ 降级', $stale->replay($plain, 42, $goodOps) === null);

// ② 子进程超时：必须在超时后返回 null，且不能把结算线程挂住
file_put_contents(
    $stubDir . '/slow.mjs',
    "process.stdin.resume();setTimeout(()=>{process.stdout.write('{}')},8000);"
);
$slow = new Match3NodeReplayEngine(['node' => ['script' => $stubDir . '/slow.mjs', 'timeout_ms' => 300]]);
$t0     = microtime(true);
$result = $slow->replay($plain, 42, $goodOps);
$costMs = (int) round((microtime(true) - $t0) * 1000);
check('子进程超时 → 降级且及时返回', $result === null && $costMs < 3000, "耗时 {$costMs}ms");

// ③ bundle 缺失：工厂必须回落 PHP 引擎（服务不中断），而不是抛异常
$missing = new Match3NodeReplayEngine(['node' => ['script' => $stubDir . '/nope.mjs']]);
check('bundle 缺失 → isAvailable()=false', $missing->isAvailable() === false);

$fallback = ReplayEngineFactory::make(['mode' => 'node', 'node' => ['script' => $stubDir . '/nope.mjs']]);
check('bundle 缺失 → 工厂回落 PHP 引擎', $fallback instanceof Match3PhpReplayEngine);

$direct = ReplayEngineFactory::make(['mode' => 'node', 'node' => ['script' => $bundlePath]]);
check('bundle 就绪 → 工厂选用 Node 引擎', $direct instanceof Match3NodeReplayEngine);

/* ======================================================================
 *  5. bundle 新鲜度（改了 TS 算法却忘记重新打包 = 线上批量误杀）
 * ====================================================================== */

section('5. bundle 新鲜度');

// 只比 bundle 真正依赖的文件：src/core/*.ts + CLI 自身
// （scripts/export-replay-vectors.ts 不参与打包，改了它不该判 stale）
$sources = [];
foreach ([$repoRoot . '/match3game/src/core', $repoRoot . '/match3game/scripts'] as $dir) {
    foreach ((array) glob($dir . '/*.ts') as $file) {
        if (basename($file) === 'export-replay-vectors.ts') {
            continue; // 向量导出器不进 bundle
        }
        $sources[] = $file;
    }
}

$newestSource = 0;
$newestFile   = '';
foreach ($sources as $file) {
    $mtime = (int) @filemtime($file);
    if ($mtime > $newestSource) {
        $newestSource = $mtime;
        $newestFile   = $file;
    }
}

$bundleTime = (int) @filemtime($bundlePath);

check(
    'bundle 不早于 TS 源码（改了算法必须 npm run build:replay）',
    $sources !== [] && $bundleTime >= $newestSource,
    $sources === []
        ? '未找到 TS 源码'
        : 'bundle ' . date('H:i:s', $bundleTime) . ' 早于 ' . basename($newestFile) . ' ' . date('H:i:s', $newestSource)
);
echo "    ↳ 这是 strict 上线后的关键保险丝：bundle 陈旧 = 用旧算法判新客户端 = 批量误杀。\n";

// ======================================================================
echo "\n----------------------------------------\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
