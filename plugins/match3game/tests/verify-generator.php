<?php
/**
 * 随机关卡生成器 + AI 模拟器自检（Phase 2）
 *
 *   php src/plugins/match3game/tests/verify-generator.php
 *
 * 覆盖：
 *   1. DifficultyCurve     难度曲线与公式（05 §B7.1 / 02 §7.3）
 *   2. Match3LevelGenerator generator / template 两种模式的确定性与合法性
 *   3. LevelSimulator      贪心 AI 的确定性与棋盘构建
 *   4. LevelGenerationService 生成编排（校验 + 体检 + 降级）
 */

declare(strict_types=1);

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
require $base . 'Match3OpsValidator.php';
require $base . 'Match3ValidationResult.php';
require $base . 'Match3ReplayEngineInterface.php';
require $base . 'Match3NullReplayEngine.php';
require $base . 'LevelGenerationService.php';

use plugin\match3game\service\level\DifficultyCurve;
use plugin\match3game\service\level\LevelGenerationService;
use plugin\match3game\service\level\LevelSchemaFactory;
use plugin\match3game\service\level\LevelSimulator;
use plugin\match3game\service\level\LevelValidator;
use plugin\match3game\service\level\Match3LevelGenerator;

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

/** 生成基底（提供 palette / 默认规则） */
function baseSchema(): array
{
    return [
        'schema'  => 'match3/level@1',
        'code'    => 'M3-0101',
        'name'    => '基底',
        'chapter' => '第一章',
        'board'   => ['cols' => 9, 'rows' => 9, 'cellSize' => 76, 'origin' => ['c' => 0, 'r' => 0]],
        'palette' => ['gems' => ['red_gem', 'blue_gem', 'yellow_gem', 'green_gem', 'purple_gem', 'orange_gem'], 'theme' => 'orchard'],
        'regions' => [[
            'id'      => 'main',
            'origin'  => ['c' => 0, 'r' => 0],
            'gravity' => 'down',
            'layers'  => ['terrain' => array_merge(['SSSSSSSSS'], array_fill(0, 8, 'ooooooooo')), 'entity' => [], 'overlay' => []],
            'spawn'   => ['mode' => 'top', 'weights' => [1, 1, 1, 1, 1, 1]],
        ]],
        'rules'   => ['mode' => 'moves', 'moves' => 30, 'minMatch' => 3, 'goals' => [['type' => 'score', 'count' => 2000]], 'starScores' => [2000, 4000, 6000]],
    ];
}

// ======================================================================
section('1. DifficultyCurve —— 难度曲线（05 §B7.1）');
// ======================================================================

check('D(1,0) = 1.0', abs(DifficultyCurve::D(1, 0) - 1.0) < 1e-9, (string) DifficultyCurve::D(1, 0));
check('D(2,0) = 1.25', abs(DifficultyCurve::D(2, 0) - 1.25) < 1e-9);
check('D(3,0) = 1.5', abs(DifficultyCurve::D(3, 0) - 1.5) < 1e-9);
check('D(1,4) = 1.16', abs(DifficultyCurve::D(1, 4) - 1.16) < 1e-9, (string) DifficultyCurve::D(1, 4));
check('D 随遗物强度上升', DifficultyCurve::D(1, 0, 5.0) > DifficultyCurve::D(1, 0));

check('moves(D=1) = 25', DifficultyCurve::moves(1.0) === 25, (string) DifficultyCurve::moves(1.0));
// 05 §B7.1：D=1.85 → 25 / 1.85^0.6 = 17.2 → 17
check('moves(D=1.85) = 17', DifficultyCurve::moves(1.85) === 17, (string) DifficultyCurve::moves(1.85));
check('moves 单调递减', DifficultyCurve::moves(1.0) > DifficultyCurve::moves(2.0) && DifficultyCurve::moves(2.0) > DifficultyCurve::moves(3.0));
check('moves 不低于下限', DifficultyCurve::moves(99.0) >= DifficultyCurve::MIN_MOVES);

check('gemCount(D=1) = 6', DifficultyCurve::gemCount(1.0) === 6);
check('gemCount(D=2) = 5', DifficultyCurve::gemCount(2.0) === 5);
check('gemCount(D=3.5) = 4', DifficultyCurve::gemCount(3.5) === 4);
check('gemCount 夹在 [4,6]', DifficultyCurve::gemCount(99.0) === 4 && DifficultyCurve::gemCount(0.0) === 6);

check('overlayDensity 夹在 [0,0.6]', DifficultyCurve::overlayDensity(99.0) <= 0.6);
check('stars 三档递增', (function () {
    $s = DifficultyCurve::stars(2000);
    return count($s) === 3 && $s[0] < $s[1] && $s[1] < $s[2];
})());

// ======================================================================
section('2. Match3LevelGenerator —— generator 模式');
// ======================================================================

$gen = new Match3LevelGenerator();

$g1 = $gen->generate(baseSchema(), 12345, 1.6, '2-3');
$g2 = $gen->generate(baseSchema(), 12345, 1.6, '2-3');

check('同 (seed, D, nodeId) 生成结果完全一致',
    LevelSchemaFactory::fingerprint($g1) === LevelSchemaFactory::fingerprint($g2));

$g3 = $gen->generate(baseSchema(), 999, 1.6, '2-3');
check('不同 seed 生成不同关卡',
    LevelSchemaFactory::fingerprint($g1) !== LevelSchemaFactory::fingerprint($g3));

check('输出通过 LevelValidator', LevelValidator::validate($g1) === [], implode('; ', LevelValidator::validate($g1)));

$region = $g1['regions'][0];
check('board 尺寸与 terrain 一致',
    count($region['layers']['terrain']) === $g1['board']['rows']
    && strlen($region['layers']['terrain'][0]) === $g1['board']['cols']);
check('terrain 行长度一致', (function () use ($region) {
    $len = strlen($region['layers']['terrain'][0]);
    foreach ($region['layers']['terrain'] as $row) {
        if (strlen($row) !== $len) {
            return false;
        }
    }
    return true;
})());
check('entity / overlay 与 terrain 同尺寸',
    count($region['layers']['entity']) === count($region['layers']['terrain'])
    && count($region['layers']['overlay']) === count($region['layers']['terrain']));

check('颜色数 = gemCount(D=1.6) = 5', count($g1['palette']['gems']) === DifficultyCurve::gemCount(1.6), (string) count($g1['palette']['gems']));
check('spawn.weights 与颜色数同长', count($region['spawn']['weights']) === count($g1['palette']['gems']));
check('moves = DifficultyCurve::moves(D)', (int) $g1['rules']['moves'] === DifficultyCurve::moves(1.6));
check('random.seedPolicy=fixed 且 seed 已钉住', ($g1['random']['seedPolicy'] ?? '') === 'fixed' && (int) $g1['random']['seed'] === 12345);
check('goals 非空且 count > 0', (function () use ($g1) {
    foreach ($g1['rules']['goals'] as $goal) {
        if ((int) ($goal['count'] ?? 0) <= 0) {
            return false;
        }
    }
    return $g1['rules']['goals'] !== [];
})());

// 100 个 seed 全部合法
$bad = '';
for ($s = 1; $s <= 100; $s++) {
    $sv = $gen->generate(baseSchema(), $s * 7919, 1.0 + ($s % 40) / 10, "1-{$s}");
    $e  = LevelValidator::validate($sv);
    if ($e !== []) {
        $bad = "seed={$s}: " . implode('; ', $e);
        break;
    }
}
check('100 个随机 seed 全部通过 LevelValidator', $bad === '', $bad);

// ======================================================================
section('3. Match3LevelGenerator —— template 模式');
// ======================================================================

$tpl = [
    'mode'     => 'template',
    'template' => [
        'terrain' => [
            'SSSSSSSSS',
            'ooooooooo', 'ooooooooo', 'ooooooooo',
            'ooooooooo', 'ooooooooo', 'ooooooooo',
        ],
        'slots'   => [
            ['id' => 'ice', 'rect' => [2, 2, 6, 3], 'fill' => 'overlay:ice1', 'density' => 1.0],
            ['id' => 'st',  'rect' => [0, 5, 2, 5], 'fill' => 'blocker:stone', 'density' => 0.5],
        ],
    ],
];

$t1 = $gen->generateByConfig(baseSchema(), $tpl, 555, 1.2, 't-1');
$t2 = $gen->generateByConfig(baseSchema(), $tpl, 555, 1.2, 't-1');

check('template 确定性', LevelSchemaFactory::fingerprint($t1) === LevelSchemaFactory::fingerprint($t2));
check('template 通过 LevelValidator', LevelValidator::validate($t1) === [], implode('; ', LevelValidator::validate($t1)));
check('template 沿用策划 terrain', $t1['regions'][0]['layers']['terrain'] === $tpl['template']['terrain']);

$iceRows = array_slice($t1['regions'][0]['layers']['overlay'], 2, 2);
$iceCount = 0;
foreach ($iceRows as $row) {
    $iceCount += substr_count($row, 'I');
}
check('slot overlay:ice1 已铺冰（2 行 × 5 列 = 10 格）', $iceCount === 10, "实际 {$iceCount}");

$stoneCount = substr_count(implode('', array_slice($t1['regions'][0]['layers']['entity'], 5, 1)), 'X');
check('slot blocker:stone 已置石块', $stoneCount >= 1, "实际 {$stoneCount}");

// 没有生成器的模板 → 自动补出生成器行
$noSpawn = $tpl;
$noSpawn['template']['terrain'] = array_fill(0, 6, 'ooooooooo');
$t3 = $gen->generateByConfig(baseSchema(), $noSpawn, 7, 1.0, 't-2');
check('模板缺 S 时自动补生成器行', str_contains(implode('', $t3['regions'][0]['layers']['terrain']), 'S'));
check('补 S 后通过 LevelValidator', LevelValidator::validate($t3) === [], implode('; ', LevelValidator::validate($t3)));

// ======================================================================
section('4. LevelSimulator —— 贪心 AI');
// ======================================================================

$sim = new LevelSimulator();

$s1 = $sim->playOnce($g1, 12345, 0);
$s2 = $sim->playOnce($g1, 12345, 0);
check('同 (schema, seed, k) 试玩结果一致', $s1 === $s2);
check('结果是 win/moves/score/goals/collected/cleared/turns 结构',
    array_keys($s1) === ['win', 'moves', 'score', 'goals', 'collected', 'cleared', 'turns'], implode(',', array_keys($s1)));
check('步数不超过 rules.moves', $s1['moves'] <= (int) $g1['rules']['moves']);
check('分数非负', $s1['score'] >= 0);

$state = $sim->buildState($g1, 12345);
check('生成器行不可站立（color = null）', $state['color'][0] === array_fill(0, $state['cols'], null));
check('可玩行已填色', $state['color'][1][0] !== null);

$hasMove = $sim->hasValidMove($g1, 12345);
check('hasValidMove 返回布尔', is_bool($hasMove));

$agg = $sim->simulate($g1, 12345, 10);
check('simulate 返回 passRate/wins/tries 等字段', isset($agg['passRate'], $agg['wins'], $agg['tries'], $agg['avgMoves'], $agg['avgScore']));
check('simulate 返回 avgCollected/avgCleared', isset($agg['avgCollected'], $agg['avgCleared']));
check('passRate 落在 [0,1]', $agg['passRate'] >= 0 && $agg['passRate'] <= 1);
check('wins <= tries', $agg['wins'] <= $agg['tries']);
check('avgCollected 键来自调色板', (function () use ($agg, $g1) {
    foreach (array_keys($agg['avgCollected']) as $k) {
        if (!in_array($k, $g1['palette']['gems'], true)) {
            return false;
        }
    }
    return true;
})());

// 覆盖层：冰关目标可达性（clear 目标应能被破除）
$iceSchema = $g1;
$iceSchema['rules']['goals'] = [['type' => 'clear', 'layer' => 'ice', 'count' => 1]];
$overlayRows = $iceSchema['regions'][0]['layers']['overlay'];
$overlayRows[2] = substr_replace($overlayRows[2], 'IIIIIIIII', 0, 9);
$iceSchema['regions'][0]['layers']['overlay'] = $overlayRows;
$iceRes = $sim->playOnce($iceSchema, 4242, 0);
check('clear 目标可被破除（冰层计数 > 0 或已达目标）',
    $iceRes['win'] || ($iceRes['goals']['ice'] ?? 0) > 0, json_encode($iceRes['goals']));

// ======================================================================
section('5. LevelGenerationService —— 目标反标定');
// ======================================================================

$svcFast = new LevelGenerationService($gen, $sim, [
    'pass_rate_range' => [0.35, 0.85],
    'max_retries'     => 2,
    'sim_tries'       => 4,
]);

// 先把目标统一成 score，便于断言标定公式
$scoreBoard = $g1;
$scoreBoard['rules']['goals'] = [['type' => 'score', 'count' => 2000]];

$cal = $svcFast->calibrate($scoreBoard, 12345, 1.6);
check('calibrate 返回 schema/goalSource/goalCounts/probing',
    isset($cal['schema'], $cal['goalSource'], $cal['goalCounts'], $cal['probing']));
check('goalSource = calibrated', $cal['goalSource'] === 'calibrated');

$probing = $cal['probing']['avgScore'] ?? 0;
check('探针 avgScore > 0（目标设为不可达成，AI 跑满步数）', $probing > 0, "avgScore={$probing}");

$scoreGoal = 0;
foreach ($cal['schema']['rules']['goals'] as $gg) {
    if (($gg['type'] ?? '') === 'score') {
        $scoreGoal = (int) $gg['count'];
    }
}
check('score 目标 = avgScore × ratio(1.6∈[0.90,1.15])',
    $scoreGoal >= (int) round($probing * 0.85) && $scoreGoal <= (int) round($probing * 1.16),
    "goal={$scoreGoal}, avg={$probing}");

$stars = $cal['schema']['rules']['starScores'];
check('三星阈值跟随目标（一星 = 目标）', (int) $stars[0] === $scoreGoal);
check('三星阈值递增', $stars[0] < $stars[1] && $stars[1] < $stars[2]);
check('反标定后仍通过 LevelValidator', LevelValidator::validate($cal['schema']) === [], implode('; ', LevelValidator::validate($cal['schema'])));

// clear 目标：上限 = 棋盘实际覆盖物数量（构造一张只有第 2 行 9 格冰的棋盘）
$iceBoard = $g1;
$ovRows   = $iceBoard['regions'][0]['layers']['overlay'];
$rows     = count($ovRows);
$ovRows   = array_fill(0, $rows, str_repeat('.', 9));
$ovRows[2] = 'IIIIIIIII';
$iceBoard['regions'][0]['layers']['overlay'] = $ovRows;
$iceBoard['rules']['goals'] = [['type' => 'clear', 'layer' => 'ice', 'count' => 99]];

$calIce   = $svcFast->calibrate($iceBoard, 777, 1.0);
$iceCount = LevelGenerationService::overlayCounts($iceBoard)['ice'] ?? 0;
$iceGoal  = (int) $calIce['schema']['rules']['goals'][0]['count'];
check('overlayCounts 统计正确（9 格冰）', $iceCount === 9, "实际 {$iceCount}");
check('clear 目标不超过棋盘实际覆盖物', $iceGoal > 0 && $iceGoal <= $iceCount, "goal={$iceGoal}, 上限={$iceCount}");

// 没有冰的棋盘不应产出 clear:ice 目标
$noIce = $gen->generate(baseSchema(), 31337, 1.0, 'z');
$hasIce = (LevelGenerationService::overlayCounts($noIce)['ice'] ?? 0) > 0;
$goalTypes = array_column($noIce['rules']['goals'], 'type');
check('无冰棋盘不会产生 clear:ice 目标', $hasIce || !in_array('clear', $goalTypes, true) || (function () use ($noIce) {
    foreach ($noIce['rules']['goals'] as $gg) {
        if (($gg['type'] ?? '') === 'clear' && ($gg['layer'] ?? '') === 'ice') {
            return false;
        }
    }
    return true;
})());

// ======================================================================
section('6. LevelGenerationService —— 生成编排与通关率体检');
// ======================================================================

$svc = new LevelGenerationService($gen, $sim, [
    'pass_rate_range' => [0.35, 0.85],
    'max_retries'     => 2,
    'sim_tries'       => 4,
]);

$out = $svc->generate(baseSchema(), ['mode' => 'generator'], 20260910, 1.3, '3-1');
check('返回 schema', is_array($out['schema']) && $out['schema'] !== []);
check('返回 quality/attempts/degraded/goalSource', isset($out['quality'], $out['attempts'], $out['degraded'], $out['goalSource']));
check('产出通过 LevelValidator', LevelValidator::validate($out['schema']) === [], implode('; ', LevelValidator::validate($out['schema'])));
check('attempts >= 1', $out['attempts'] >= 1);
check('通关率落在 passRateRange', (float) $out['quality']['passRate'] >= 0.35 && (float) $out['quality']['passRate'] <= 0.85,
    'passRate=' . $out['quality']['passRate']);

$inspect = $svc->inspect($out['schema'], 20260910, 4);
check('inspect.valid = true', $inspect['valid'] === true);
check('inspect 含 passRate/hasValidMove', isset($inspect['passRate'], $inspect['hasValidMove']));

$bad = baseSchema();
$bad['regions'][0]['layers']['terrain'] = array_fill(0, 5, 'oo');
$inspectBad = $svc->inspect($bad, 1, 2);
check('inspect 对非法 schema 返回 valid=false 与 errors', $inspectBad['valid'] === false && $inspectBad['errors'] !== []);

// 换 seed 重试：区间设为不可能达成（通关率恒 ≤1），必然走重试 + 降级
$strict = new LevelGenerationService($gen, $sim, [
    'pass_rate_range' => [1.5, 2.0],
    'max_retries'     => 1,
    'sim_tries'       => 2,
]);
$strictOut = $strict->generate(baseSchema(), ['mode' => 'generator'], 42, 1.0, 'x');
check('窄区间下走重试/降级分支且仍产出合法关卡',
    $strictOut['degraded'] === true && LevelValidator::validate($strictOut['schema']) === [],
    json_encode(['attempts' => $strictOut['attempts'], 'errors' => $strictOut['errors']]));

// ======================================================================
section('7. 通关率区间抽查（各难度档）');
// ======================================================================

$summaries = [];
$allInBand = true;
foreach ([1.0, 1.45, 1.85] as $D) {
    $r    = $svc->generate(baseSchema(), ['mode' => 'generator'], 1000 + (int) ($D * 100), $D, "d{$D}");
    $rate = (float) $r['quality']['passRate'];
    if ($rate < 0.35 || $rate > 0.85) {
        $allInBand = false;
    }
    $summaries[] = sprintf('D=%s→%.2f', $D, $rate);
}
check('各难度档通关率均在 [0.35, 0.85]', $allInBand, implode(', ', $summaries));

// ======================================================================
echo "\n----------------------------------------\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
