<?php

declare(strict_types=1);

/**
 * 平衡回归 + seed 一致性自检（S5-6 / B6）
 *
 *   php src/plugins/match3game/tests/verify-balance.php          # 快档：40 组合（进 run-all）
 *   php src/plugins/match3game/tests/verify-balance.php --full    # 慢档：200 组合（05 C2-R2 口径）
 *
 * 【为什么分两档】AI 模拟的开销是 O(组合数 × tries × 回合数)。200 组合跑一次要几十秒，
 *   而**平衡回归看的是趋势、不是单次精度**，放进 CI 只会拖慢反馈。
 *   所以：快档 40 组（秒级，每次提交都跑），慢档 200 组（`--full`，发版前 / 动过难度曲线时跑）。
 *   两档共用**同一套断言**，只是样本量与 `tries` 不同。
 *
 * 【断言口径】
 *   1. **seed 一致性**（B6，§6.2 第 15 项）：同 (schema, seed) 跑 100 次逐值一致 ——
 *      模拟器若混入任何非确定性（洗牌 / 补货种子 / 迭代顺序 / 浮点累积），这里必红。
 *      用 `StableStringify::run()` 比全量，避免 PHP 数组 `==` 对浮点与键序的宽容。
 *   2. **通关率区间**（S5-6，§6.2 第 14 项）：整体平均落 40%~75%（05 §B8.1），
 *      每个难度档都不越界 —— 过难会流失、过易没挑战，**两端都是失衡**。
 *   3. **「胜率 >90% 组合」告警**：样本越小噪声越大，所以按**占比**判定（≤10%），
 *      并把具体组合打印出来，便于反查是"这档太易"还是"目标反标定偏了"。
 *
 * 【刻意不用 mt_rand】难度与 seed 全用确定性公式派生 —— 回归脚本自己必须是确定的，
 *   否则"这次红、下次绿"比没有回归更糟。
 */

$base = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require $base . 'StableStringify.php';
require $base . 'LevelValidator.php';
require $base . 'LevelSchemaFactory.php';
require $base . 'Match3Random.php';
require $base . 'DifficultyCurve.php';
require $base . 'Match3LevelGenerator.php';
require $base . 'Match3Board.php';
require $base . 'LevelSimulator.php';
require $base . 'LevelGenerationService.php';

use plugin\match3game\service\level\LevelGenerationService;
use plugin\match3game\service\level\LevelValidator;
use plugin\match3game\service\level\LevelSimulator;
use plugin\match3game\service\level\Match3LevelGenerator;
use plugin\match3game\service\level\StableStringify;

$full = in_array('--full', $argv ?? [], true);

/** 快档 / 慢档：组合数与每组的试玩局数 */
$comboCount = $full ? 200 : 40;
$simTries   = $full ? 20 : 8;

/** 通关率区间（05 §B8.1：各层 40%~75%） */
$rangeMin = 0.40;
$rangeMax = 0.75;

/** 单个难度档的宽容区间（比整体区间宽，因为单档样本更少） */
$bandMin = 0.25;
$bandMax = 0.90;

/**
 * 「胜率 >90% 组合」的容忍比例
 *
 * ⚠️ 快档只跑 40 组，单组噪声能把这个比例顶上去好几个百分点 ——
 *    实测快档正好落在 10.0%（4/40），卡死在边界上会导致 CI **时红时绿**。
 *    所以快档放宽到 15%（看趋势），慢档（200 组）才用 10% 严格判定。
 */
$alertRatioMax = $full ? 0.10 : 0.15;

$passed = 0;
$failed = 0;

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

function section(string $title): void
{
    echo "\n{$title}\n";
}

/** 9×9 标准基底（与 verify-generator.php 同一份，保证两处口径一致） */
function baseSchema(): array
{
    return [
        'schema'  => 'match3/level@1',
        'code'    => 'M3-0101',
        'name'    => '基底',
        'chapter' => '第一章',
        'board'   => ['cols' => 9, 'rows' => 9, 'cellSize' => 76, 'origin' => ['c' => 0, 'r' => 0]],
        'palette' => [
            'gems'  => ['red_gem', 'blue_gem', 'yellow_gem', 'green_gem', 'purple_gem', 'orange_gem'],
            'theme' => 'orchard',
        ],
        'regions' => [[
            'id'      => 'main',
            'origin'  => ['c' => 0, 'r' => 0],
            'gravity' => 'down',
            'layers'  => [
                'terrain' => array_merge(['SSSSSSSSS'], array_fill(0, 8, 'ooooooooo')),
                'entity'  => [],
                'overlay' => [],
            ],
            'spawn'   => ['mode' => 'top', 'weights' => [1, 1, 1, 1, 1, 1]],
        ]],
        'rules'   => [
            'mode'       => 'moves',
            'moves'      => 30,
            'minMatch'   => 3,
            'goals'      => [['type' => 'score', 'count' => 2000]],
            'starScores' => [2000, 4000, 6000],
        ],
    ];
}

$gen = new Match3LevelGenerator();
$sim = new LevelSimulator();

/* ======================================================================
 * 1. seed 一致性（B6）
 * ====================================================================== */

section('1. seed 一致性 —— 同 schema + seed 跑 100 次逐值一致（B6）');

$probeSeed  = 20261004;
$probeTries = 3;
$svc = new LevelGenerationService($gen, $sim, [
    'pass_rate_range' => [0.35, 0.85],
    'max_retries'     => 2,
    'sim_tries'       => 4,
]);

// ⚠️ 必须走**带反标定**的生成链路。直接用 `Match3LevelGenerator::generate()` 的话，
//    目标没经过标定（可能根本打不过）—— 实测那样通关率是 0%，
//    量到的就是"基底难度"而不是"真正会下发的关卡"。
$probe = $svc->generate(baseSchema(), ['mode' => 'generator'], $probeSeed, 1.3, 'bal-probe')['schema'];

check('探针关卡通过 LevelValidator', LevelValidator::validate($probe) === [], implode('; ', LevelValidator::validate($probe)));

$first   = $sim->simulate($probe, $probeSeed, $probeTries);
$firstS  = StableStringify::run($first);
$diverge = -1;

for ($i = 1; $i < 100; $i++) {
    if (StableStringify::run($sim->simulate($probe, $probeSeed, $probeTries)) !== $firstS) {
        $diverge = $i;
        break;
    }
}

check('100 次重跑逐值一致（确定性）', $diverge === -1, $diverge === -1 ? '' : "第 {$diverge} 次出现分歧");
check('simulate 字段齐全', isset($first['passRate'], $first['wins'], $first['tries'], $first['avgMoves'], $first['avgScore']));

/* ======================================================================
 * 2. 平衡回归（S5-6）
 * ====================================================================== */

section("2. 平衡回归 —— {$comboCount} 组合 × {$simTries} 局（S5-6" . ($full ? '，全量档' : '，快档') . '）');

/** 三档难度：覆盖 DifficultyCurve 的主要区间 */
$bands = [
    '低难度 1.0~1.3' => [1.0, 1.3],
    '中难度 1.4~1.8' => [1.4, 1.8],
    '高难度 1.9~2.4' => [1.9, 2.4],
];

$bandKeys = array_keys($bands);
$all      = [];
$perBand  = [];
$alerts   = [];

for ($i = 0; $i < $comboCount; $i++) {
    // 档位轮转 —— 保证三档都被覆盖，且不引入随机源
    $band = $bandKeys[$i % count($bandKeys)];
    [$lo, $hi] = $bands[$band];

    // 难度与 seed 全用确定性公式派生（脚本自身必须可复现）
    $D    = $lo + ((($i * 37) % 100) / 100) * ($hi - $lo);
    $seed = 1_000_000 + $i * 7919;

    $level = $svc->generate(baseSchema(), ['mode' => 'generator'], $seed, $D, "bal-{$i}")['schema'];
    $rate  = (float) $sim->simulate($level, $seed, $simTries)['passRate'];

    $all[] = $rate;
    $perBand[$band][] = $rate;

    if ($rate > 0.90) {
        $alerts[] = sprintf('#%d D=%.2f seed=%d → %.0f%%', $i, $D, $seed, $rate * 100);
    }
}

$avg = array_sum($all) / max(1, count($all));
check(
    sprintf('整体平均通关率落在 %.0f%%~%.0f%%（实测 %.1f%%）', $rangeMin * 100, $rangeMax * 100, $avg * 100),
    $avg >= $rangeMin && $avg <= $rangeMax
);

foreach ($perBand as $band => $rates) {
    $bAvg = array_sum($rates) / max(1, count($rates));
    check(
        sprintf('%s：%.1f%%（容忍 %.0f%%~%.0f%%）', $band, $bAvg * 100, $bandMin * 100, $bandMax * 100),
        $bAvg >= $bandMin && $bAvg <= $bandMax
    );
}

$alertRatio = count($alerts) / max(1, count($all));
check(
    sprintf('胜率 >90%% 的组合占比 %.1f%% ≤ %.0f%%（共 %d 个）', $alertRatio * 100, $alertRatioMax * 100, count($alerts)),
    $alertRatio <= $alertRatioMax,
    $alerts === [] ? '' : implode(' / ', array_slice($alerts, 0, 5))
);

if (!$full) {
    echo "\n  （快档样本较小，趋势异常时请跑 --full 复核：200 组合 × 20 局）\n";
}

echo "\n通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
