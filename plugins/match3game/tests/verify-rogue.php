<?php
/**
 * 肉鸽核心自检（Phase 3）
 *
 *   php src/plugins/match3game/tests/verify-rogue.php
 *
 * 覆盖（可离线验证的纯逻辑部分）：
 *   1. Match3MapGenerator   关卡树确定性与可达性
 *   2. Match3NodeService    难度公式 / 节点修正（elite、boss）
 *   3. Match3RunService     灵石公式 / 终局判定
 *   4. GoalJudge            目标判定（固定关卡与肉鸽共用）
 *   5. RelicService         遗物强度
 *
 * 需要数据库的部分（startRun/enterNode/结算）在集成测试中覆盖，本脚本不涉及。
 */

declare(strict_types=1);

$levelBase = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require $levelBase . 'StableStringify.php';
require $levelBase . 'Match3Random.php';
require $levelBase . 'DifficultyCurve.php';
require $levelBase . 'LevelSchemaFactory.php';
require $levelBase . 'LevelValidator.php';
require $levelBase . 'Match3Board.php';
require $levelBase . 'LevelSimulator.php';
require $levelBase . 'Match3LevelGenerator.php';
require $levelBase . 'LevelGenerationService.php';
require $levelBase . 'GoalJudge.php';
require $levelBase . 'Match3OpsValidator.php';
require $levelBase . 'Match3ValidationResult.php';
require $levelBase . 'Match3ReplayEngineInterface.php';
require $levelBase . 'Match3NullReplayEngine.php';
require $levelBase . 'SubmissionValidator.php';
require __DIR__ . '/../service/rogue/PatchException.php';
require __DIR__ . '/../service/rogue/PatchResult.php';
require __DIR__ . '/../service/rogue/PatchEngine.php';
require __DIR__ . '/../service/rogue/Match3NodeException.php';
require __DIR__ . '/../service/rogue/Match3MapGenerator.php';
require __DIR__ . '/../service/rogue/RogueRules.php';
require __DIR__ . '/../service/rogue/RelicService.php';
require __DIR__ . '/../service/rogue/Match3NodeService.php';

use plugin\match3game\service\level\DifficultyCurve;
use plugin\match3game\service\level\GoalJudge;
use plugin\match3game\service\rogue\Match3MapGenerator;
use plugin\match3game\service\rogue\Match3NodeException;
use plugin\match3game\service\rogue\Match3NodeService;
use plugin\match3game\service\rogue\PatchEngine;
use plugin\match3game\service\rogue\RelicService;

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

/** 标准关卡（数组形态，供节点修正测试） */
function level(): array
{
    return [
        'schema'  => 'match3/level@1',
        'code'    => 'GEN-rect9x9-1-2',
        'board'   => ['cols' => 9, 'rows' => 10, 'cellSize' => 76, 'origin' => ['c' => 0, 'r' => 0]],
        'regions' => [[
            'id'      => 'main',
            'origin'  => ['c' => 0, 'r' => 0],
            'gravity' => 'down',
            'layers'  => [
                'terrain' => array_merge(['SSSSSSSSS'], array_fill(0, 9, 'ooooooooo')),
                'entity'  => [],
                'overlay' => [],
            ],
            'spawn'   => ['mode' => 'top', 'weights' => [1, 1, 1, 1, 1, 1]],
        ]],
        'rules'   => [
            'mode'       => 'moves',
            'moves'      => 20,
            'minMatch'   => 3,
            'goals'      => [['type' => 'score', 'count' => 4000]],
            'starScores' => [4000, 6000, 8800],
        ],
    ];
}

// ======================================================================
section('1. Match3MapGenerator —— 关卡树');
// ======================================================================

$m1 = Match3MapGenerator::generate(88231, 3);
$m2 = Match3MapGenerator::generate(88231, 3);

check('同 mapSeed 生成完全一致', $m1 === $m2);
check('不同 mapSeed 生成不同', Match3MapGenerator::generate(99, 3) !== $m1);
check('节点总数 = 层数 × (4 + 1)', count($m1['nodes']) === 3 * 5, (string) count($m1['nodes']));

$bossPerLayer = [];
foreach ($m1['nodes'] as $n) {
    if ($n['type'] === 'boss') {
        $bossPerLayer[$n['layer']] = ($bossPerLayer[$n['layer']] ?? 0) + 1;
    }
}
check('每层恰好 1 个 Boss', $bossPerLayer === [1 => 1, 2 => 1, 3 => 1], json_encode($bossPerLayer));

$lastOfLayer = [];
foreach ($m1['nodes'] as $n) {
    $lastOfLayer[$n['layer']] = $n['type']; // nodes 按层顺序排列，最后一个覆盖
}
check('每层最后一个节点是 Boss', $lastOfLayer === [1 => 'boss', 2 => 'boss', 3 => 'boss']);

// ---- 关卡池（2026-09-27 线上 41003 的回归） ------------------------------------
//
// `Match3RunService` 原来只读配置里的 `stage_pool`，而 `config/match3.php` 里它是 `[]`
// → 生成器退回**占位码**（Boss 用 `M3-BOSS-{层}`）→ 地图存进 `map_data`，玩家点进去
// `41003 关卡模板不存在: M3-BOSS-1`。修法是**服务层保证池子非空**（没配就从关卡表推导），
// 这两条锁住"池子非空时所有战斗节点都用池内码"这个前提。
$pool = ['M3-0101', 'M3-0102', 'M3-0103'];
$m3   = Match3MapGenerator::generate(88231, 3, $pool);

$badCodes = [];
foreach ($m3['nodes'] as $n) {
    if (!in_array($n['type'], ['battle', 'elite', 'boss'], true)) {
        continue;
    }
    if (!in_array($n['stageCode'], $pool, true)) {
        $badCodes[] = $n['id'] . ':' . $n['type'] . '=' . ($n['stageCode'] ?? 'null');
    }
}
check('有池子时所有战斗节点都用池内关卡码（含 Boss）', $badCodes === [], implode(', ', $badCodes));

// 生成器**保留**空池的占位行为（`verify-rogue.php` 自身与其它纯逻辑调用都依赖它），
// 所以"不能发空池"这条约束必须由服务层守住 —— 见 `Match3RunService::stagePoolForMap()`。
check(
    '空池时生成器仍退回占位码（约束交给服务层）',
    Match3MapGenerator::generate(1, 1, [])['nodes'][4]['stageCode'] === 'M3-BOSS-1'
);

$starts = Match3MapGenerator::startNodeIds($m1);
check('起点为 2 个', count($starts) === 2 && $starts === ['1-0', '1-1'], json_encode($starts));

check('战斗节点已分配关卡编码', (function () use ($m1) {
    foreach ($m1['nodes'] as $n) {
        if ($n['type'] === 'battle' && empty($n['stageCode'])) {
            return false;
        }
    }
    return true;
})());

check('末层 Boss 无后继（终局）', Match3MapGenerator::nextNodeIds($m1, '3-4') === []);
check('层内节点链式相连', Match3MapGenerator::nextNodeIds($m1, '1-0') === ['1-1']);
check('层末 Boss 连到下一层起点', Match3MapGenerator::nextNodeIds($m1, '1-4') === ['2-0', '2-1']);

// 可达性
check('未通关任何节点时，只有起点可达', Match3MapGenerator::availableNodeIds($m1, []) === ['1-0', '1-1']);
check('通关 1-0 后可达 1-1', Match3MapGenerator::availableNodeIds($m1, ['1-0']) === ['1-1']);
check('跳关不可达（未通关 1-0 时 1-3 不可进）', !Match3MapGenerator::isReachable($m1, '1-3', []));
check('通关 1-0..1-3 后 1-4 可达', Match3MapGenerator::isReachable($m1, '1-4', ['1-0', '1-1', '1-2', '1-3']));
check('通关 Boss 后进入下一层起点', Match3MapGenerator::availableNodeIds($m1, ['1-4']) === ['2-0', '2-1']);

$counts = Match3MapGenerator::countByType($m1);
check('countByType 覆盖全部节点', array_sum($counts) === 15, json_encode($counts));
check('每层战斗类节点 ≥ 3/5（含 Boss，战斗下限约束）', (function () use ($m1) {
    foreach ([1, 2, 3] as $layer) {
        $combat = 0;
        foreach ($m1['nodes'] as $n) {
            if ($n['layer'] === $layer && in_array($n['type'], ['battle', 'elite', 'boss'], true)) {
                $combat++;
            }
        }
        if ($combat < Match3MapGenerator::MIN_COMBAT_PER_LAYER) {
            return false;
        }
    }
    return true;
})());

check('被改写的战斗节点已补 stageCode', (function () use ($m1) {
    foreach ($m1['nodes'] as $n) {
        if (in_array($n['type'], ['battle', 'elite', 'boss'], true) && empty($n['stageCode'])) {
            return false;
        }
    }
    return true;
})());

// 多个 mapSeed 下战斗下限都要成立
check('50 个 mapSeed 全部满足战斗下限', (function () {
    for ($s = 1; $s <= 50; $s++) {
        $m = Match3MapGenerator::generate($s * 7919, 3);
        foreach ([1, 2, 3] as $layer) {
            $combat = 0;
            foreach ($m['nodes'] as $n) {
                if ($n['layer'] === $layer && in_array($n['type'], ['battle', 'elite', 'boss'], true)) {
                    $combat++;
                }
            }
            if ($combat < Match3MapGenerator::MIN_COMBAT_PER_LAYER) {
                return false;
            }
        }
    }
    return true;
})());

// ======================================================================
section('2. Match3NodeService —— 难度与节点修正');
// ======================================================================

check('calcDifficulty 委托 DifficultyCurve', Match3NodeService::calcDifficulty(2, 3, 4) === DifficultyCurve::D(2, 3, 4.0));
check('calcDifficulty(1,0) = 1.0', Match3NodeService::calcDifficulty(1, 0) === 1.0);
check('movesFor(1.0) = 25', Match3NodeService::movesFor(1.0) === 25);
check('movesFor(1.85) = 17', Match3NodeService::movesFor(1.85) === 17, (string) Match3NodeService::movesFor(1.85));

$elite = Match3NodeService::applyNodeModifiers(level(), 'elite', 1.5);
check('elite 目标 ×1.25（4000 → 5000）', $elite['rules']['goals'][0]['count'] === 5000, (string) $elite['rules']['goals'][0]['count']);
check('elite 步数 ×0.9（20 → 18）', $elite['rules']['moves'] === 18, (string) $elite['rules']['moves']);
check('elite 不注入 actors', !isset($elite['actors']));

$boss = Match3NodeService::applyNodeModifiers(level(), 'boss', 1.85);
check('boss 目标 ×1.5（4000 → 6000）', $boss['rules']['goals'][0]['count'] === 6000, (string) $boss['rules']['goals'][0]['count']);
check('boss 注入 actors', isset($boss['actors'][0]['id']) && $boss['actors'][0]['id'] === 'boss_node');
check('boss hp = 1000 × D', (int) $boss['actors'][0]['hp'] === (int) round(1000 * 1.85), (string) $boss['actors'][0]['hp']);
check('boss 保留可判定目标（type=score，未替换为 boss 类型）', $boss['rules']['goals'][0]['type'] === 'score');

$battle = Match3NodeService::applyNodeModifiers(level(), 'battle', 1.5);
check('battle 不做任何修正', $battle === level());

check('修正后仍通过 LevelValidator', \plugin\match3game\service\level\LevelValidator::validate($boss) === [],
    implode('; ', \plugin\match3game\service\level\LevelValidator::validate($boss)));

// ======================================================================
section('3. RogueRules —— 灵石与终局（Match3RunService 委托此处）');
// ======================================================================

use plugin\match3game\service\rogue\RogueRules;

check('石公式：1 层 0 分不通关 = 100', RogueRules::metaReward(1, 0, false) === 100);
check('石公式：2 层 0 分不通关 = 200', RogueRules::metaReward(2, 0, false) === 200);
check('石公式：2 层 10000 分不通关 = 200 × 2 = 400', RogueRules::metaReward(2, 10000, false) === 400);
check('通关额外 +500', RogueRules::metaReward(3, 0, true) === 300 + 500);
check('负分按 0 处理', RogueRules::metaReward(1, -999, false) === 100);
check('isFinalNode：末层 Boss 为终局', RogueRules::isFinalNode($m1, '3-4') === true);
check('isFinalNode：中间节点不是终局', RogueRules::isFinalNode($m1, '1-0') === false);
check('起点配置含默认与 monk', isset(RogueRules::START_OPTIONS['default'], RogueRules::START_OPTIONS['monk']));
check('解锁成本表含起始步数三级', RogueRules::UNLOCK_COSTS['start_move_1'] === 500
    && RogueRules::UNLOCK_COSTS['start_move_2'] === 1000
    && RogueRules::UNLOCK_COSTS['start_move_3'] === 2000);
check('奖励区间：battle/elite/boss 递增', RogueRules::rewardRange('battle')[1] < RogueRules::rewardRange('elite')[0]
    && RogueRules::rewardRange('elite')[1] < RogueRules::rewardRange('boss')[0]);
check('遗物解锁键前缀 = relic:', RogueRules::UNLOCK_RELIC_PREFIX === 'relic:');

// ======================================================================
section('4. GoalJudge —— 目标判定');
// ======================================================================

$goals = [
    ['type' => 'collect', 'target' => 'red_gem', 'count' => 20],
    ['type' => 'clear', 'layer' => 'ice', 'count' => 10],
    ['type' => 'score', 'count' => 3000],
];

check('全部达成 → met = true', GoalJudge::met($goals, ['red_gem' => 20, 'ice' => 10], 3000));
check('collect 不足 → met = false', !GoalJudge::met($goals, ['red_gem' => 19, 'ice' => 10], 3000));
check('clear 不足 → met = false', !GoalJudge::met($goals, ['red_gem' => 25, 'ice' => 9], 3000));
check('score 不足 → met = false', !GoalJudge::met($goals, ['red_gem' => 25, 'ice' => 12], 2999));
check('unmetReason 指名未达标项', str_contains((string) GoalJudge::unmetReason($goals, ['red_gem' => 1, 'ice' => 9], 1), 'red_gem'));
check('scoreTarget 提取分数目标', GoalJudge::scoreTarget($goals) === 3000);
check('progressKeys 提取进度键', GoalJudge::progressKeys($goals) === ['red_gem', 'ice']);
check('未知目标类型视为已满足（boss/spread 等）', GoalJudge::met([['type' => 'boss', 'actor' => 'b1', 'count' => 1]], [], 0));
check('collect 缺 target 时跳过该目标', GoalJudge::met([['type' => 'collect', 'count' => 5]], [], 0));

// ======================================================================
section('5. RelicService —— 遗物强度');
// ======================================================================

check('power：common=1 rare=2 epic=3 cursed=1',
    RelicService::power([
        ['rarity' => 'common'], ['rarity' => 'rare'], ['rarity' => 'epic'], ['rarity' => 'cursed'],
    ]) === 7);
check('power：空列表 = 0', RelicService::power([]) === 0);
check('稀有度分布表齐全', isset(RelicService::RARITY_DIST['battle'], RelicService::RARITY_DIST['elite'], RelicService::RARITY_DIST['boss'], RelicService::RARITY_DIST['shop']));
check('普通战斗不出诅咒遗物', (RelicService::RARITY_DIST['battle']['cursed'] ?? 0) === 0);
check('Boss 后 epic 概率最高（18%）', RelicService::RARITY_DIST['boss']['epic'] === 18);
check('present 不返回内部字段', (function () {
    $p = RelicService::present(['id' => 1, 'relic_id' => 'r_x', 'name' => 'X', 'rarity' => 'rare', 'patch' => []]);
    return array_keys($p) === ['id', 'name', 'rarity', 'category', 'desc', 'icon', 'patch'];
})());

// ======================================================================
section('6. 遗物 patch 与关卡兼容性（30 条全跑一遍 draft）');
// ======================================================================

/** 从 seeder 里抽出 patch 列表（离线复刻关键条目，验证不会抛异常） */
$drafts = [
    ['op' => 'replace', 'path' => '/rules/specials/match4', 'value' => 'bomb'],
    ['op' => 'replace', 'path' => '/rules/specials/match5Line', 'value' => 'bomb'],
    ['op' => 'replace', 'path' => '/rules/minMatch', 'value' => 2],
    ['op' => 'add', 'path' => '/rules/comboMul', 'value' => 0.5, 'mode' => 'accumulate'],
    ['op' => 'add', 'path' => '/rules/specialChain', 'value' => true],
    ['op' => 'add', 'path' => '/rules/scoreBonus/cascade', 'value' => 100],
    ['op' => 'add', 'path' => '/rules/moves', 'value' => 5, 'mode' => 'accumulate'],
    ['op' => 'mul', 'path' => '/rules/scoreMul', 'value' => 1.2],
    ['op' => 'mul', 'path' => '/rules/coinMul', 'value' => 1.3],
    ['op' => 'add', 'path' => '/rules/scoreBonus/ice', 'value' => 50],
    ['op' => 'add', 'path' => '/runtime/freePropPerLevel', 'value' => 1, 'mode' => 'accumulate'],
    ['op' => 'add', 'path' => '/runtime/initialSpecials', 'value' => ['stripe_h' => 1]],
    ['op' => 'add', 'path' => '/runtime/extraSpawnerCols', 'value' => 1],
    ['op' => 'add', 'path' => '/runtime/cursedNarrowCols', 'value' => 1],
    ['op' => 'add', 'path' => '/runtime/clearBlockers', 'value' => true],
    ['op' => 'replace', 'path' => '/behaviours/[id=choc]/interval', 'value' => 0],
    ['op' => 'add', 'path' => '/behaviours/[id=vine]/interval', 'value' => 2, 'mode' => 'accumulate'],
    ['op' => 'add', 'path' => '/run/lives', 'value' => -1],
    ['op' => 'mul', 'path' => '/run/metaMul', 'value' => 1.5],
    ['op' => 'add', 'path' => '/run/protectOnce', 'value' => true],
];

$schema = \plugin\match3game\service\level\LevelSchemaFactory::normalize(level());
$ok = true;
$bad = '';
foreach ($drafts as $i => $op) {
    try {
        $res = PatchEngine::applyOps($schema, [$op]);
        $schema = $res->doc;
    } catch (\Throwable $e) {
        $ok = false;
        $bad = "[{$i}] {$op['op']} {$op['path']}: " . $e->getMessage();
        break;
    }
}
check('20 条代表性遗物 patch 依次应用均不抛异常', $ok, $bad);

check('规范化后的 schema 含 rules.specials（遗物 replace 的前提）',
    isset($schema['rules']['specials']['match4']), json_encode($schema['rules']['specials'] ?? null));
check('连续应用后步数仍 > 0（可被 LevelValidator 接受）', ((int) $schema['rules']['moves']) > 0, (string) $schema['rules']['moves']);
check('连续应用后通过 LevelValidator', \plugin\match3game\service\level\LevelValidator::validate($schema) === [],
    implode('; ', \plugin\match3game\service\level\LevelValidator::validate($schema)));

// ======================================================================
echo "\n----------------------------------------\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
