<?php
/**
 * P0 上线自检：**非法 op 拒单** + **strict 口径切换**
 *
 *   php src/plugins/match3game/tests/verify-strict-rollout.php
 *
 * 【为什么这两件事必须一起上】
 *   · 只切 `strict` 不堵非法 op → 攻击者塞一条坏 op 就能让 L3 降级失效，`strict` 形同虚设；
 *   · 只堵非法 op 不切 `strict` → 口径仍是 `upper_bound + flag`，作弊只被"标记"不被拦。
 *   两者是一套闭环，所以共用一个门禁脚本。
 *
 * 【覆盖】
 *   1. 配置就位（防"文档说切了、配置其实没切"）
 *   2. `invalid` → 拒单（含 L1 抓不到的那类：L1 合法但重放不成线的交换）
 *   3. `degraded` → 仍放行（**有意的产品权衡**，显式锁住，避免被误当 bug"修掉"）
 *   4. strict 行为：诚实通过 / 虚报拦下 / 少报也拦（双向）
 *   5. 错误码映射 11001~11005 + 漂移护栏
 *
 * bundle 缺失时第 2/7 节自动 SKIP（Node 引擎相关），其余仍执行。
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
require $base . 'SubmissionValidator.php';

// Match3ErrorCode 要实现 wise\support\exception\ErrorCodeInterface：
// 优先 require 真实接口（纯契约、零依赖）；包结构变化时用 stub 兜底
$errorCodeInterface = dirname(__DIR__, 4) . '/src/packages/wiseadmin/wise-support/src/exception/ErrorCodeInterface.php';
require is_file($errorCodeInterface) ? $errorCodeInterface : __DIR__ . '/stub/ErrorCodeInterface.php';
require __DIR__ . '/../exception/Match3ErrorCode.php';

use plugin\match3game\exception\Match3ErrorCode;
use plugin\match3game\service\level\Match3FillVersion;
use plugin\match3game\service\level\Match3NodeReplayEngine;
use plugin\match3game\service\level\Match3OpsValidator;
use plugin\match3game\service\level\Match3PhpReplayEngine;
use plugin\match3game\service\level\Match3ReplayEngineInterface;
use plugin\match3game\service\level\SubmissionValidator;

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

function skip(string $title): void
{
    echo "  - SKIP {$title}\n";
}

$repoRoot    = dirname(__DIR__, 4);
$bundlePath  = $repoRoot . '/match3game/dist/replay-engine.mjs';
$vectorsFile = $repoRoot . '/match3game/vectors/replay-vectors-ts.json';
$configFile  = __DIR__ . '/../config/match3.php';

echo "==============================================================\n";
echo " P0 上线自检：非法 op 拒单 + strict 口径\n";
echo ' PHP ' . PHP_VERSION . "\n";
echo "==============================================================\n";

/* ======================================================================
 *  1. 配置就位
 * ====================================================================== */

section('1. 配置就位');

$config = require $configFile;
$replayCfg = (array) ($config['replay_engine'] ?? []);
$subCfg    = (array) ($config['submission'] ?? []);

// node | http **都合法**：两种模式跑的都是同一份前端 TS 算法（`match3game/dist/replay-engine.mjs`），
// 差别只在"每次 spawn 子进程 / 常驻服务"（部署方式，见 13 §三十四）。
// 这条断言真正要拦的是"出现第三种引擎实现（即算法分叉）"，所以列白名单而不是钉死一个值。
$replayMode = (string) ($replayCfg['mode'] ?? '');
check('replay_engine.mode ∈ {node, http}（算法单一实现：复用前端 TS）', in_array($replayMode, ['node', 'http'], true), 'mode=' . ($replayMode !== '' ? $replayMode : '?'));
check('submission.compare_mode = strict', ($subCfg['compare_mode'] ?? '') === 'strict', 'compare_mode=' . ($subCfg['compare_mode'] ?? '?'));
check('submission.upper_bound_action = reject（回滚到上界口径时也要拦）', ($subCfg['upper_bound_action'] ?? '') === 'reject', 'action=' . ($subCfg['upper_bound_action'] ?? '?'));
check('submission.score_tolerance ≤ 0.05', (float) ($subCfg['score_tolerance'] ?? 1) <= 0.05, 'tolerance=' . ($subCfg['score_tolerance'] ?? '?'));

/* ---- fill_version 握手就位（strict 的保险丝，见 12-next-phase-plan §4.6） ---- */

$fill = Match3FillVersion::CURRENT;

// 与 TS 源码**逐字**比对（parse 而非运行，避免依赖 node）
$tsSchemaSrc = (string) @file_get_contents($repoRoot . '/match3game/src/core/schema.ts');
$tsFill      = '';
if (preg_match("/export const FILL_VERSION = '([^']+)'/", $tsSchemaSrc, $m) === 1) {
    $tsFill = $m[1];
}
check(
    'fill_version：PHP 常量与 TS 源码逐字一致',
    $tsFill !== '' && $fill === $tsFill,
    "php={$fill} ts={$tsFill}"
);

check(
    'fill_version：三个引擎的 FILL_VERSION 与常量对齐',
    Match3PhpReplayEngine::FILL_VERSION === $fill
    && Match3NodeReplayEngine::FILL_VERSION === $fill
);

$battleSrc = (string) @file_get_contents(__DIR__ . '/../service/BattleService.php');
$rogueSrc  = (string) @file_get_contents(__DIR__ . '/../controller/api/Rogue.php');
check(
    'battle/start & battle/latest 已下发 fill_version',
    substr_count($battleSrc, "'fill_version' => Match3FillVersion::CURRENT") >= 2
);
check(
    'rogue/enter 已下发 fill_version',
    str_contains($rogueSrc, "\$data['fill_version'] = Match3FillVersion::CURRENT")
);

/* ======================================================================
 *  2. invalid → 拒单
 * ====================================================================== */

section('2. invalid → 拒单（不降级）');

// 向量文件提供合法 schema（L1 需要 region 才能判定坐标）；bundle 提供 Node 引擎
$vectorsOk = is_file($vectorsFile);
$bundleOk  = is_file($bundlePath);
$vectors   = $vectorsOk ? json_decode((string) file_get_contents($vectorsFile), true) : null;
$schema    = (array) ($vectors['schemas']['plain9x9'] ?? []);
$nodeReady = $vectorsOk && $bundleOk && $schema !== [];

if ($schema === []) {
    skip('第 2~4 节（向量文件缺失：npm run vectors:replay）');
} else {
    // 先用假引擎验证"管道"：引擎返回 INVALID_KEY 时，校验器必须拒单而不是放行
    $fakeInvalid = new class implements Match3ReplayEngineInterface {
        public function replay($schema, int $seed, array $ops, string $fillVersion = ''): ?array
        {
            return [Match3ReplayEngineInterface::INVALID_KEY => 'fake'];
        }

        public function isAvailable(): bool
        {
            return true;
        }
    };

    $probe = [
        'seed'  => 1,
        'ops'   => [['t' => 'swap', 'a' => [1, 1], 'b' => [2, 1]]],
        'claim' => ['score' => 0, 'movesUsed' => 1],
    ];

    $res = (new SubmissionValidator($fakeInvalid, ['compare_mode' => 'strict']))->validateSubmission($schema, $probe);
    check(
        '引擎报 invalid → 拒单且 errorCode=invalid_op',
        !$res->passed && $res->errorCode === SubmissionValidator::ERR_INVALID_OP,
        'errorCode=' . (string) ($res->errorCode ?? 'null')
    );

    // 假引擎返回 null（能力不足）→ 必须仍然放行（对照组，证明两条路没写反）
    $fakeNull = new class implements Match3ReplayEngineInterface {
        public function replay($schema, int $seed, array $ops, string $fillVersion = ''): ?array
        {
            return null;
        }

        public function isAvailable(): bool
        {
            return true;
        }
    };
    $res = (new SubmissionValidator($fakeNull, ['compare_mode' => 'strict']))->validateSubmission($schema, $probe);
    check('对照：引擎报 null（能力不足）→ 仍放行弱校验', $res->passed && $res->replayed === false);
}

if (!$nodeReady) {
    skip('Node 引擎端到端（bundle 缺失：npm ci && npm run build:replay）');
} else {
    $nodeEngine = new Match3NodeReplayEngine(['node' => ['script' => $bundlePath]]);

    // 扫出一步"L1 合法但重放拒绝"的交换（初始盘无存量连线时才会出现）
    $attackSeed = -1;
    $attackOp   = null;
    for ($seed = 1; $seed <= 20 && $attackSeed < 0; $seed++) {
        for ($c = 0; $c < 8 && $attackSeed < 0; $c++) {
            $op = ['t' => 'swap', 'a' => [$c, 0], 'b' => [$c + 1, 0]];
            if (Match3OpsValidator::validate($schema, [$op]) !== []) {
                continue;
            }
            $r = $nodeEngine->replay($schema, $seed, [$op]);
            if (is_array($r) && isset($r[Match3ReplayEngineInterface::INVALID_KEY])) {
                $attackSeed = $seed;
                $attackOp   = $op;
            }
        }
    }

    check('找到「L1 合法但重放拒绝」的攻击样本', $attackSeed > 0, '未找到（测试前提失效）');

    if ($attackSeed > 0) {
        // claim 刻意取"看起来合理"的分：分报得离谱会被 L2 先拦，就测不到 L3 的拒单
        $svStrict = new SubmissionValidator($nodeEngine, ['compare_mode' => 'strict']);
        $res = $svStrict->validateSubmission($schema, [
            'seed'  => $attackSeed,
            'ops'   => [$attackOp],
            'claim' => ['score' => 500, 'movesUsed' => 1],
        ]);

        check(
            '端到端：L1 放过、L3 判非法 → 拒单（P0 修的绕过口）',
            !$res->passed && $res->errorCode === SubmissionValidator::ERR_INVALID_OP,
            'errorCode=' . (string) ($res->errorCode ?? 'null')
        );
        echo "    ↳ 样本: seed={$attackSeed}, op=" . json_encode($attackOp) . "\n";
        echo "    ↳ 这类 op L1 判合法（相邻/可交换），只有重放能看出它不成线。\n";
    }

    // ---- 引擎自身的分类正确性 ----
    $isInvalid = static fn (?array $r): bool => is_array($r) && isset($r[Match3ReplayEngineInterface::INVALID_KEY]);

    check('node：不相邻交换 → invalid', $isInvalid($nodeEngine->replay($schema, 1, [['t' => 'swap', 'a' => [0, 0], 'b' => [2, 0]]])));
    check('node：未知 op 类型 → invalid', $isInvalid($nodeEngine->replay($schema, 1, [['t' => 'teleport']])));
    check('node：rainbow 道具 → null（引擎能力，不误判作弊）', $nodeEngine->replay($schema, 1, [['t' => 'prop', 'p' => 'rainbow', 'at' => [0, 0]]]) === null);
}

/* ======================================================================
 *  3. degraded → 仍放行（有意的产品权衡）
 * ====================================================================== */

section('3. degraded → 仍放行（有意的产品权衡）');

if (!$nodeReady) {
    skip('degraded 放行（需 bundle）');
} else {
    $exotic = $vectors['schemas']['plain9x9'];
    $exotic['regions'][0]['layers']['terrain'][0] = '>oooooooo'; // 传送带：重放引擎不忠实模拟

    $svStrict = new SubmissionValidator($nodeEngine, ['compare_mode' => 'strict']);
    $res = $svStrict->validateSubmission($exotic, [
        'seed'  => 1,
        'ops'   => [['t' => 'swap', 'a' => [1, 1], 'b' => [2, 1]]],
        'claim' => ['score' => 5000, 'movesUsed' => 1],
    ]);

    check(
        '引擎能力不足（传送带地形）→ 放行弱校验 + replayed=false',
        $res->passed && $res->replayed === false,
        'passed=' . var_export($res->passed, true)
    );
    echo "    ↳ 这是**有意为之**：宁可放过不可误杀（06 §九）。\n";
    echo "    ↳ 代价：这类关卡无 L3 保护。缓解手段是限制此类关卡占比 + 埋点监控降级率。\n";
}

/* ======================================================================
 *  4. strict 行为（诚实通过 / 虚报拦下 / 少报也拦）
 * ====================================================================== */

section('4. strict 行为');

if (!$nodeReady) {
    skip('strict 行为（需 bundle）');
} else {
    // 取一条真实的客户端 ops（S0 向量），用引擎算出"诚实 claim"
    $honestCase = null;
    foreach ($vectors['cases'] as $case) {
        if (($case['compare'] ?? true) !== false && ($case['schema'] ?? '') === 'plain9x9') {
            $honestCase = $case;
            break;
        }
    }

    $replayResult = $nodeEngine->replay($schema, (int) $honestCase['seed'], $honestCase['ops']);
    $claimGoals   = (array) ($replayResult['goals'] ?? []);
    unset($claimGoals['score']);

    $honest = [
        'seed'  => (int) $honestCase['seed'],
        'ops'   => $honestCase['ops'],
        'claim' => [
            'score'    => (int) $replayResult['score'],
            'movesUsed'=> (int) $replayResult['moves'],
            'goals'    => $claimGoals,
            'maxCombo' => (int) $replayResult['maxCombo'],
            'passed'   => true,
        ],
    ];

    $svStrict = new SubmissionValidator($nodeEngine, ['compare_mode' => 'strict']);

    $res = $svStrict->validateSubmission($schema, $honest);
    check('诚实提交 → 通过且 replayed=true', $res->passed && $res->replayed === true, implode('; ', $res->errors));
    check('诚实提交 → 分数取重放值', (int) ($res->meta['score'] ?? -1) === (int) $replayResult['score']);

    $inflated = $honest;
    $inflated['claim']['score'] = (int) $replayResult['score'] * 3;
    $res = $svStrict->validateSubmission($schema, $inflated);
    check('虚报 3 倍 → result_mismatch 拒单', !$res->passed && $res->errorCode === SubmissionValidator::ERR_RESULT_MISMATCH);

    $under = $honest;
    $under['claim']['score'] = 0;
    $res = $svStrict->validateSubmission($schema, $under);
    check('少报（0 分）→ **也拒单**（strict 是双向比对）', !$res->passed && $res->errorCode === SubmissionValidator::ERR_RESULT_MISMATCH);

    $lieGoals = $honest;
    $lieGoals['claim']['goals'] = ['red_gem' => 99999];
    $res = $svStrict->validateSubmission($schema, $lieGoals);
    check('虚报目标进度 → result_mismatch 拒单', !$res->passed && $res->errorCode === SubmissionValidator::ERR_RESULT_MISMATCH);

    // 回滚口径：upper_bound + reject 下，诚实仍然通过（避免"回滚即停服"）
    $ub = new SubmissionValidator($nodeEngine, ['compare_mode' => 'upper_bound', 'upper_bound_action' => 'reject', 'score_tolerance' => 0.05]);
    check('回滚口径 upper_bound+reject：诚实提交仍通过', $ub->validateSubmission($schema, $honest)->passed);
    check('回滚口径 upper_bound+reject：虚报被拦', !$ub->validateSubmission($schema, $inflated)->passed);
}

/* ======================================================================
 *  5. 错误码映射 + 漂移护栏
 * ====================================================================== */

section('5. 错误码映射（11001~11005）');

check('invalid_op → 11001', Match3ErrorCode::fromSubmitError('invalid_op')->value === 11001);
check('invalid_claim → 11002', Match3ErrorCode::fromSubmitError('invalid_claim')->value === 11002);
check('result_mismatch → 11003', Match3ErrorCode::fromSubmitError('result_mismatch')->value === 11003);
check('board_mismatch → 11004', Match3ErrorCode::fromSubmitError('board_mismatch')->value === 11004);
check('invalid_checkpoint → 11005', Match3ErrorCode::fromSubmitError('invalid_checkpoint')->value === 11005);
check(
    '未识别的 reason（如 duration_too_short）→ 回落 10008',
    Match3ErrorCode::fromSubmitError('duration_too_short') === Match3ErrorCode::SETTLE_INVALID
);

// 漂移护栏：SubmissionValidator 里任何 ERR_* 常量改名/新增，都必须在这里被看见
$mapped = [
    SubmissionValidator::ERR_INVALID_OP         => 11001,
    SubmissionValidator::ERR_INVALID_CLAIM      => 11002,
    SubmissionValidator::ERR_RESULT_MISMATCH    => 11003,
    SubmissionValidator::ERR_BOARD_MISMATCH     => 11004,
    SubmissionValidator::ERR_CHECKPOINT         => 11005,
];
$drift = [];
foreach ($mapped as $reason => $expected) {
    if (Match3ErrorCode::fromSubmitError($reason)->value !== $expected) {
        $drift[] = $reason;
    }
}
check('漂移护栏：5 个 ERR_* 常量与映射表逐一对齐', $drift === [], implode(', ', $drift));

// 源码护栏：BattleService 必须真的用了这张映射表（防"改了映射没接上"）
$battleSrc = (string) file_get_contents(__DIR__ . '/../service/BattleService.php');
check('BattleService 已接入 fromSubmitError（不再一律回 10008）', str_contains($battleSrc, 'Match3ErrorCode::fromSubmitError('));

// ======================================================================
echo "\n----------------------------------------\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
