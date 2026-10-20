<?php

declare(strict_types=1);

/**
 * S5-3 · **逐回合 checkpoint 比对**自检
 *
 *   php src/plugins/match3game/tests/verify-checkpoint-replay.php
 *
 * 【它锁的是什么】
 *   L1b 只校验 checkpoints 的**结构**（t 严格递增 / h 非空），`h` 的值对不对只有
 *   L3 重放引擎能验 —— 本脚本验的就是"**真引擎真的能产出逐回合指纹，且与客户端同源**"：
 *
 *   ① 引擎产出      node / php 两条路径都产出 `turnFingerprints`（8 位 hex）
 *   ② 跨语言同源    同一批 ops，node 与 php 的逐回合指纹**逐值一致**（swap-only 用例）
 *   ③ 端到端        把引擎自己的指纹当"客户端上报"→ 放行；**篡改任一回合** →
 *                   观察期放行 + 产出明细；`checkpoint_strict=true` → `board_mismatch` 拒单
 *   ④ 位置与保守性  客户端把 checkpoints 放在**提交体顶层**（`core/submitter.ts` 的口径）；
 *                   任一侧缺失回合 → 跳过，不判违规（宁可放过，不可误杀）
 *   ⑤ 配置就位      `config/match3.php` 有 `checkpoint_strict` / `fingerprints` 两个键
 *
 * 【为什么 ③ 用"引擎自己的指纹"当客户端上报】
 *   客户端 `BattleLoop.recordCheckpoints` 与 TS 重放器走的是**同一份代码**，
 *   所以"诚实客户端"的 checkpoints 必然等于重放产出的 `turnFingerprints`。
 *   这是本套件里唯一能证明"两端口径同源"的方式；真机语料回填（S2-7）后再补真实样本。
 *
 * 【跳过策略】node 引擎需要 `dist/replay-engine-v*.mjs`（`npm run build:replay && npm run archive:replay`）；
 *   缺失时 §1/②/③ 的 node 部分 SKIP，**不算失败**（与 `verify-node-replay.php` 同口径）。
 */

$base = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require $base . 'StableStringify.php';
require $base . 'Match3Random.php';
require $base . 'LevelSchemaFactory.php';
require $base . 'Match3Board.php';
require $base . 'LevelValidator.php';
require $base . 'Match3OpsValidator.php';
require $base . 'Match3ValidationResult.php';
require $base . 'Match3ReplayEngineInterface.php';
require $base . 'Match3NullReplayEngine.php';
require $base . 'Match3FillVersion.php';
require $base . 'Match3PhpReplayEngine.php';
require $base . 'Match3NodeReplayEngine.php';
require $base . 'SubmissionValidator.php';

use plugin\match3game\service\level\Match3FillVersion;
use plugin\match3game\service\level\Match3NodeReplayEngine;
use plugin\match3game\service\level\Match3PhpReplayEngine;
use plugin\match3game\service\level\SubmissionValidator;

$passed   = 0;
$failed   = 0;
$skipped  = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ✓ {$name}\n";
        return;
    }

    $failed++;
    echo "  ✗ {$name}" . ($detail !== '' ? " —— {$detail}" : '') . "\n";
}

function skip(string $name, string $why = ''): void
{
    global $skipped;

    $skipped++;
    echo "  ⊘ {$name}" . ($why !== '' ? "（{$why}）" : '') . "\n";
}

function section(string $title): void
{
    echo "\n── {$title} ──\n";
}

/** 仓库根：src/plugins/match3game/tests → 上溯 4 级 */
$repoRoot = dirname(__DIR__, 4);

/* ======================================================================
 *  语料：客户端导出的黄金向量（swap-only 子集）
 *
 *  为什么只用 swap-only：php 引擎是**回滚基线**，它在"清格类道具"上与客户端
 *  有已知分歧（combo 档位 / 补货时机，见 `replay-divergence-report.php-engine.md`）。
 *  跨语言指纹比对只在 swap-only 上有意义 —— prop 用例的指纹分歧是**已知且已接受**的。
 * ====================================================================== */
$vectorsPath = $repoRoot . '/match3game/vectors/replay-vectors-ts.json';
$rawVectors  = is_file($vectorsPath) ? file_get_contents($vectorsPath) : null;
$vectors     = is_string($rawVectors) ? json_decode($rawVectors, true) : null;

if (!is_array($vectors) || empty($vectors['cases']) || !is_array($vectors['cases'])) {
    fwrite(STDERR, "[S5-3] 向量文件缺失或不合法: {$vectorsPath}\n");
    fwrite(STDERR, "[S5-3] 请先导出: cd match3game && npm run vectors:replay\n");
    exit(1);
}

$schemas = (array) ($vectors['schemas'] ?? []);

/** 挑出"纯交换、可比对、规模适中"的用例 */
$swapCases = [];
foreach ($vectors['cases'] as $case) {
    if (!is_array($case) || ($case['compare'] ?? false) !== true) {
        continue;
    }
    $ops = $case['ops'] ?? null;
    if (!is_array($ops) || $ops === [] || count($ops) > 25) {
        continue;
    }
    foreach ($ops as $op) {
        if (!is_array($op) || ($op['t'] ?? '') !== 'swap') {
            continue 2;
        }
    }
    $schema = $schemas[$case['schema'] ?? ''] ?? null;
    if (!is_array($schema)) {
        continue;
    }
    // L1 会按 `rules.moves` 卡交换次数，超过就拒单（那不是本脚本要验的东西）
    $moves = (int) (($schema['rules']['moves'] ?? 0));
    if ($moves > 0 && count($ops) > $moves) {
        continue;
    }

    $swapCases[] = $case;
}

section('1. 语料与引擎可用性');

check('黄金向量可用（swap-only 子集 ≥ 3 条）', count($swapCases) >= 3, '实际 ' . count($swapCases) . ' 条');

$archived = $repoRoot . '/match3game/dist/replay-engine-' . Match3FillVersion::CURRENT . '.mjs';
$bundle   = is_file($archived) ? $archived : $repoRoot . '/match3game/dist/replay-engine.mjs';
$nodeReady = is_file($bundle);

if ($nodeReady) {
    check('找到重放 bundle: ' . basename($bundle), true);
} else {
    skip('node 引擎相关用例', 'bundle 缺失：cd match3game && npm run build:replay && npm run archive:replay');
}

$nodeEngine = $nodeReady ? new Match3NodeReplayEngine(['node' => ['script' => $bundle]]) : null;
$phpEngine  = new Match3PhpReplayEngine(['fingerprints' => true]);

/* ======================================================================
 *  2. 引擎真的产出逐回合指纹
 * ====================================================================== */
section('2. 引擎产出 turnFingerprints');

$case0 = $swapCases[0];
$schema0 = $schemas[$case0['schema']];

$phpOut = $phpEngine->replay($schema0, (int) $case0['seed'], $case0['ops']);
check('php 引擎产出 turnFingerprints', is_array($phpOut) && !empty($phpOut['turnFingerprints']));
check(
    'php 指纹是 8 位十六进制',
    is_array($phpOut) && !empty($phpOut['turnFingerprints'])
        && count(array_filter((array) $phpOut['turnFingerprints'], static fn ($h): bool => (bool) preg_match('/^[0-9a-f]{8}$/', (string) $h)))
            === count((array) ($phpOut['turnFingerprints'] ?? []))
);
check(
    'php 指纹条数 = 交换次数',
    is_array($phpOut) && count((array) ($phpOut['turnFingerprints'] ?? [])) === count($case0['ops'])
);

$nodeOut = null;
if ($nodeEngine !== null) {
    $nodeOut = $nodeEngine->replay($schema0, (int) $case0['seed'], $case0['ops']);
    check('node 引擎产出 turnFingerprints', is_array($nodeOut) && !empty($nodeOut['turnFingerprints']));
    check(
        'node 指纹是 8 位十六进制',
        is_array($nodeOut) && !empty($nodeOut['turnFingerprints'])
            && count(array_filter((array) $nodeOut['turnFingerprints'], static fn ($h): bool => (bool) preg_match('/^[0-9a-f]{8}$/', (string) $h)))
                === count((array) ($nodeOut['turnFingerprints'] ?? []))
    );
    // 终局指纹必须与逐回合的**最后一条**一致（同一次重放、同一个棋盘）
    $last = is_array($nodeOut) ? (array) ($nodeOut['turnFingerprints'] ?? []) : [];
    check(
        '终局 boardFingerprint = 最后一条逐回合指纹',
        $last !== [] && end($last) === ($nodeOut['boardFingerprint'] ?? null),
        'last=' . (string) (end($last) ?: '') . ' board=' . (string) ($nodeOut['boardFingerprint'] ?? '')
    );
} else {
    skip('node 引擎产出 turnFingerprints', 'bundle 缺失');
    skip('node 指纹是 8 位十六进制', 'bundle 缺失');
    skip('终局 boardFingerprint = 最后一条逐回合指纹', 'bundle 缺失');
}

/* ======================================================================
 *  3. 跨语言同源：node 与 php 的逐回合指纹逐值一致（swap-only）
 * ====================================================================== */
section('3. 跨语言：node 与 php 逐回合指纹一致（swap-only）');

if ($nodeEngine === null) {
    skip('跨语言逐回合指纹一致', 'bundle 缺失');
} else {
    $checked = 0;
    $diffs   = [];
    foreach (array_slice($swapCases, 0, 8) as $case) {
        $schema = $schemas[$case['schema']];
        $seed   = (int) $case['seed'];

        $a = $nodeEngine->replay($schema, $seed, $case['ops']);
        $b = $phpEngine->replay($schema, $seed, $case['ops']);

        if (!is_array($a) || !is_array($b)) {
            continue; // 任一侧降级 → 不计入（降级原因由别处观测）
        }
        $fa = (array) ($a['turnFingerprints'] ?? []);
        $fb = (array) ($b['turnFingerprints'] ?? []);
        if ($fa === [] || $fb === []) {
            continue;
        }

        $checked++;
        if ($fa !== $fb) {
            $diffs[] = (string) $case['id'];
        }
    }

    check('至少比对 5 条用例', $checked >= 5, '实际 ' . $checked . ' 条');
    check('逐回合指纹跨语言逐值一致', $diffs === [], '不一致用例: ' . implode(', ', $diffs));
}

/* ======================================================================
 *  4. 端到端：诚实提交放行 / 篡改被拒（真实 node 引擎）
 * ====================================================================== */
section('4. 端到端：checkpoints 与重放逐回合比对');

/**
 * 用真引擎跑一条用例，构造一次提交
 *
 * @param array<int,array<string,mixed>>|null $overrideCheckpoints null = 用引擎指纹（诚实客户端）
 */
function submitCase($engine, array $schemas, array $case, ?array $overrideCheckpoints, bool $strict): array
{
    $schema = $schemas[$case['schema']];
    $seed   = (int) $case['seed'];

    $replayed = $engine->replay($schema, $seed, $case['ops']);
    if (!is_array($replayed)) {
        return ['replayed' => null, 'passed' => null, 'errorCode' => null, 'violations' => []];
    }

    // 客户端上报口径：`{t, h}`，顶层 `checkpoints`（`core/submitter.ts`）
    $checkpoints = $overrideCheckpoints;
    if ($checkpoints === null) {
        foreach ((array) ($replayed['turnFingerprints'] ?? []) as $t => $h) {
            $checkpoints[] = ['t' => (int) $t, 'h' => (string) $h];
        }
    }

    $sv = new SubmissionValidator($engine, [
        'compare_mode'       => 'strict',
        'max_ops'            => 500,
        'checkpoint_strict'  => $strict,
    ]);

    $res = $sv->validateSubmission($schema, [
        'seed'        => $seed,
        'ops'         => $case['ops'],
        'checkpoints' => $checkpoints,
        'claim'       => [
            'score'     => (int) ($case['ts']['score'] ?? 0),
            'movesUsed' => (int) ($case['ts']['moves'] ?? 0),
            'passed'    => true,
            'goals'     => (array) ($case['ts']['goals'] ?? []),
        ],
    ]);

    return [
        'replayed'   => $replayed,
        'passed'     => $res->passed,
        'errorCode'  => $res->errorCode,
        'violations' => $sv->getCheckpointViolations(),
        'meta'       => $res->meta,
    ];
}

if ($nodeEngine === null) {
    skip('端到端 checkpoint 比对（node）', 'bundle 缺失');
} else {
    // ① 诚实客户端：checkpoints = 引擎指纹 → 放行且无违规
    $r = submitCase($nodeEngine, $schemas, $case0, null, false);
    check('诚实 checkpoints → 通过', ($r['passed'] ?? false) === true, 'errorCode=' . (string) ($r['errorCode'] ?? 'null'));
    check('诚实 checkpoints → 无违规明细', ($r['violations'] ?? []) === []);
    // S5-4 埋点消费的就是这个标量（`Match3Funnel` 按版本聚合它）
    check(
        'meta.checkpointMismatch = 0（诚实提交）',
        (int) ($r['meta']['checkpointMismatch'] ?? -1) === 0,
        'got ' . var_export($r['meta']['checkpointMismatch'] ?? null, true)
    );

    // ② 篡改某一回合（观察期：放行 + 记录，不误杀）
    $honest = [];
    foreach ((array) ($r['replayed']['turnFingerprints'] ?? []) as $t => $h) {
        $honest[] = ['t' => (int) $t, 'h' => (string) $h];
    }
    $tampered = $honest;
    $mid      = (int) floor(count($tampered) / 2);
    if ($mid < count($tampered)) {
        $tampered[$mid]['h'] = 'deadbeef';
    }

    $r2 = submitCase($nodeEngine, $schemas, $case0, $tampered, false);
    check('篡改 checkpoints → 观察期放行（不误杀）', ($r2['passed'] ?? false) === true);
    check('篡改 checkpoints → 产出违规明细', ($r2['violations'] ?? []) !== []);
    check(
        'meta.checkpointMismatch = 1（篡改 1 条）',
        (int) ($r2['meta']['checkpointMismatch'] ?? -1) === 1,
        'got ' . var_export($r2['meta']['checkpointMismatch'] ?? null, true)
    );
    check(
        '违规明细指向被篡改的回合',
        count($r2['violations'] ?? []) === 1 && str_contains((string) ($r2['violations'][0] ?? ''), 'deadbeef'),
        implode(' | ', $r2['violations'] ?? [])
    );

    // ③ 篡改 + checkpoint_strict=true → board_mismatch（→ 11004）
    $r3 = submitCase($nodeEngine, $schemas, $case0, $tampered, true);
    check(
        '篡改 + checkpoint_strict=true → board_mismatch 拒单',
        ($r3['passed'] ?? true) === false && $r3['errorCode'] === SubmissionValidator::ERR_BOARD_MISMATCH,
        'passed=' . var_export($r3['passed'] ?? null, true) . ' errorCode=' . (string) ($r3['errorCode'] ?? 'null')
    );

    // ④ 保守性：客户端多报一个"引擎没跑到"的回合 → 跳过，不判违规
    $extra   = $honest;
    $extra[] = ['t' => 9999, 'h' => 'ffffffff'];
    $r4 = submitCase($nodeEngine, $schemas, $case0, $extra, true);
    check('引擎缺失该回合 → 跳过（不判违规、不拒单）', ($r4['passed'] ?? false) === true);

    // ⑤ 兼容口径：checkpoints 塞在 claim 里也能取到（老测试/老客户端写法）
    $sv = new SubmissionValidator($nodeEngine, ['compare_mode' => 'strict', 'checkpoint_strict' => true]);
    $res = $sv->validateSubmission($schemas[$case0['schema']], [
        'seed'  => (int) $case0['seed'],
        'ops'   => $case0['ops'],
        'claim' => [
            'score'       => (int) ($case0['ts']['score'] ?? 0),
            'movesUsed'   => (int) ($case0['ts']['moves'] ?? 0),
            'passed'      => true,
            'goals'       => (array) ($case0['ts']['goals'] ?? []),
            'checkpoints' => $tampered,
        ],
    ]);
    check(
        'checkpoints 在 claim 内时同样生效（兼容口径）',
        $res->passed === false && $sv->getCheckpointViolations() !== []
    );
}

/* ======================================================================
 *  5. 结构口径：t 必须严格递增（客户端去重的理由）
 * ====================================================================== */
section('5. 结构口径：t 严格递增');

$svDup = new SubmissionValidator(new Match3PhpReplayEngine(), ['compare_mode' => 'strict']);
$dupSchema = $schemas[$case0['schema']];
$res = $svDup->validateSubmission($dupSchema, [
    'seed'        => (int) $case0['seed'],
    'ops'         => [['t' => 'swap', 'a' => [1, 1], 'b' => [2, 1]]],
    'checkpoints' => [['t' => 1, 'h' => 'aaaaaaaa'], ['t' => 1, 'h' => 'bbbbbbbb']],
    'claim'       => ['score' => 0, 'movesUsed' => 1],
]);
check(
    't 重复 → invalid_checkpoint（客户端必须先去重）',
    $res->errorCode === SubmissionValidator::ERR_CHECKPOINT,
    'errorCode=' . (string) ($res->errorCode ?? 'null')
);

/* ======================================================================
 *  6. 配置就位（防"文档说开了、配置没开"）
 * ====================================================================== */
section('6. 配置键就位');

$configSrc = is_file($repoRoot . '/src/plugins/match3game/config/match3.php')
    ? (string) file_get_contents($repoRoot . '/src/plugins/match3game/config/match3.php')
    : '';

check("config 含 'checkpoint_strict' 键", str_contains($configSrc, "'checkpoint_strict'"));
check("config 含 'fingerprints' 键", str_contains($configSrc, "'fingerprints'"));

echo "\n通过 {$passed} 项，失败 {$failed} 项" . ($skipped > 0 ? "，跳过 {$skipped} 项" : '') . "\n";
exit($failed === 0 ? 0 : 1);
