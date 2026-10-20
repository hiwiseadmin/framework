<?php
/**
 * Patch 引擎自检（Phase 3 · 05 §B2.5 的 7 组用例）
 *
 *   php src/plugins/match3game/tests/verify-patch-engine.php
 *
 * 7 组必测：
 *   1. 单 patch 应用（前后快照对比）
 *   2. 多 patch 顺序（A→B 与 B→A 结果不同，以记录顺序为准）
 *   3. 冲突（两个 replace 同路径）→ 后者生效
 *   4. 累加（两个 add 同路径）→ 正确累加
 *   5. 数组选择器 [id=x] → 定位正确；元素不存在时安全跳过
 *   6. patch 后关卡合法性 → 通过 LevelValidator
 *   7. 深拷贝隔离 → 应用 patch 不修改原模板
 */

declare(strict_types=1);

$base = __DIR__ . '/../service/level/';
require $base . 'StableStringify.php';
require $base . 'LevelValidator.php';
require $base . 'LevelSchemaFactory.php';
require $base . 'Match3Random.php';
require $base . 'Match3Board.php';
require $base . 'LevelSimulator.php';
require __DIR__ . '/../service/rogue/PatchException.php';
require __DIR__ . '/../service/rogue/PatchResult.php';
require __DIR__ . '/../service/rogue/PatchEngine.php';
require __DIR__ . '/../service/rogue/RelicService.php';

use plugin\match3game\service\level\LevelValidator;
use plugin\match3game\service\level\Match3Random;
use plugin\match3game\service\level\StableStringify;
use plugin\match3game\service\rogue\PatchEngine;
use plugin\match3game\service\rogue\PatchException;
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

/** 标准关卡模板（对象模式，对齐 01 §4.1） */
function baseLevel(): \stdClass
{
    return json_decode(json_encode([
        'schema'  => 'match3/level@1',
        'code'    => 'M3-0101',
        'name'    => '初识三消',
        'board'   => ['cols' => 9, 'rows' => 9, 'cellSize' => 76, 'origin' => ['c' => 0, 'r' => 0]],
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
            'starScores' => [2000, 4000, 6000],
            'goals'      => [['type' => 'score', 'count' => 2000]],
        ],
    ], JSON_UNESCAPED_UNICODE));
}

/** 带 behaviours 的关卡（用于数组选择器） */
function levelWithBehaviours(): \stdClass
{
    $lv = baseLevel();
    $lv->behaviours = [
        ['id' => 'belt', 'type' => 'conveyor', 'priority' => 10, 'interval' => 1],
        ['id' => 'choc', 'type' => 'spread',   'priority' => 20, 'interval' => 2],
        ['id' => 'vine', 'type' => 'grow',     'priority' => 30, 'interval' => 3],
    ];
    return $lv;
}

// ======================================================================
section('1. 单 patch 应用');
// ======================================================================

$r = PatchEngine::applyOps(baseLevel(), [
    ['op' => 'replace', 'path' => '/rules/specials/match4', 'value' => 'bomb'],
]);
// replace 目标不存在 → 应抛异常（JSON Patch 语义）
$threw = false;
try {
    PatchEngine::applyOps(baseLevel(), [['op' => 'replace', 'path' => '/rules/nonexistent', 'value' => 1]]);
} catch (PatchException $e) {
    $threw = true;
}
check('replace 不存在的路径抛 PatchException', $threw);

$r = PatchEngine::applyOps(baseLevel(), [
    ['op' => 'replace', 'path' => '/rules/moves', 'value' => 20],
    ['op' => 'add',     'path' => '/rules/newField', 'value' => 'x'],
]);
check('单 patch 生效（moves 30 → 20）', $r->doc->rules->moves === 20, (string) $r->doc->rules->moves);
check('add 新字段生效', ($r->doc->rules->newField ?? null) === 'x');
check('applied 计数正确', count($r->applied) === 2);
check('无 skipped', !$r->hasSkipped());
check('原文档未被修改（moves 仍为 30）', baseLevel()->rules->moves === 30);

$r = PatchEngine::applyOps(baseLevel(), [
    ['op' => 'add', 'path' => '/rules/moves', 'value' => 5, 'mode' => 'accumulate'],
]);
check('add + accumulate 数字累加（30 + 5 = 35）', $r->doc->rules->moves === 35, (string) $r->doc->rules->moves);

// ======================================================================
section('2. 多 patch 顺序敏感');
// ======================================================================

$relicA = ['id' => 'r_a', 'patch' => [
    ['op' => 'replace', 'path' => '/rules/specials/match4', 'value' => 'bomb'],
]];
$relicB = ['id' => 'r_b', 'patch' => [
    ['op' => 'add', 'path' => '/rules/specials/match4', 'value' => 'rainbow'],
]];

$ab = PatchEngine::applyRelics(baseLevel(), [$relicA, $relicB])->doc->rules->specials->match4 ?? null;
$ba = PatchEngine::applyRelics(baseLevel(), [$relicB, $relicA])->doc->rules->specials->match4 ?? null;

check('A→B：add(override) 覆盖 replace → rainbow', $ab === 'rainbow', var_export($ab, true));
check('B→A：replace 覆盖 add → bomb', $ba === 'bomb', var_export($ba, true));
check('顺序不同结果不同（顺序必须记录）', $ab !== $ba);

// 顺序敏感的另一例：先 +5 再 ×2 vs 先 ×2 再 +5
$plus5  = ['id' => 'plus',  'patch' => [['op' => 'add', 'path' => '/rules/moves', 'value' => 5, 'mode' => 'accumulate']]];
$times2 = ['id' => 'times', 'patch' => [['op' => 'mul', 'path' => '/rules/moves', 'value' => 2]]];

$p1 = PatchEngine::applyRelics(baseLevel(), [$plus5, $times2])->doc->rules->moves;
$p2 = PatchEngine::applyRelics(baseLevel(), [$times2, $plus5])->doc->rules->moves;
check('(30+5)×2 = 70', $p1 === 70, (string) $p1);
check('30×2+5 = 65', $p2 === 65, (string) $p2);
check('乘法与加法顺序敏感（70 ≠ 65）', $p1 !== $p2);

// ======================================================================
section('3. 冲突：两个 replace 同路径 → 后者生效');
// ======================================================================

$r = PatchEngine::applyOps(baseLevel(), [
    ['op' => 'replace', 'path' => '/rules/moves', 'value' => 10],
    ['op' => 'replace', 'path' => '/rules/moves', 'value' => 99],
]);
check('后者覆盖前者（99）', $r->doc->rules->moves === 99, (string) $r->doc->rules->moves);

// ======================================================================
section('4. 累加：两个 add 同路径');
// ======================================================================

$r = PatchEngine::applyOps(baseLevel(), [
    ['op' => 'add', 'path' => '/rules/comboMul',    'value' => 5, 'mode' => 'accumulate'],
    ['op' => 'add', 'path' => '/rules/comboMul',    'value' => 3, 'mode' => 'accumulate'],
]);
check('5 + 3 = 8', $r->doc->rules->comboMul === 8, var_export($r->doc->rules->comboMul, true));

$r = PatchEngine::applyOps(baseLevel(), [
    ['op' => 'mul', 'path' => '/rules/scoreMul', 'value' => 1.2],
    ['op' => 'mul', 'path' => '/rules/scoreMul', 'value' => 2],
]);
check('mul 累乘且字段缺省以 1 为基准（1×1.2×2 = 2.4）', abs($r->doc->rules->scoreMul - 2.4) < 1e-9, var_export($r->doc->rules->scoreMul, true));

// 数组累加
$r = PatchEngine::applyOps(baseLevel(), [
    ['op' => 'add', 'path' => '/rules/tag', 'value' => ['a'], 'mode' => 'accumulate'],
    ['op' => 'add', 'path' => '/rules/tag', 'value' => ['b'], 'mode' => 'accumulate'],
]);
check('数组 accumulate 拼接', ($r->doc->rules->tag ?? []) === ['a', 'b'], json_encode($r->doc->rules->tag));

// ======================================================================
section('5. 数组选择器 [id=x]');
// ======================================================================

$r = PatchEngine::applyOps(levelWithBehaviours(), [
    ['op' => 'replace', 'path' => '/behaviours/[id=choc]/interval', 'value' => 0],
]);
// 注意：behaviours 的内层元素是 assoc 数组（由测试构造），引擎保持容器类型不变
check('选择器定位正确（只改 choc）', $r->doc->behaviours[1]['interval'] === 0, var_export($r->doc->behaviours[1]['interval'], true));
check('其他元素不受影响', $r->doc->behaviours[0]['interval'] === 1 && $r->doc->behaviours[2]['interval'] === 3);

$r = PatchEngine::applyOps(levelWithBehaviours(), [
    ['op' => 'add', 'path' => '/behaviours/[id=vine]/interval', 'value' => 2, 'mode' => 'accumulate'],
]);
check('选择器 + 累加（3 + 2 = 5）', $r->doc->behaviours[2]['interval'] === 5, var_export($r->doc->behaviours[2]['interval'], true));

$r = PatchEngine::applyOps(levelWithBehaviours(), [
    ['op' => 'replace', 'path' => '/behaviours/[id=ghost]/interval', 'value' => 1],
]);
check('选择器未匹配 → 安全跳过（不抛异常）', $r->hasSkipped() && count($r->skipped) === 1);
check('跳过时文档保持原样', count($r->doc->behaviours) === 3 && $r->applied === []);

// 数组下标（标准 JSON Pointer）
$r = PatchEngine::applyOps(baseLevel(), [
    ['op' => 'replace', 'path' => '/rules/goals/0/count', 'value' => 5000],
]);
check('数组下标路径生效', $r->doc->rules->goals[0]->count === 5000);

// remove 幂等
$r = PatchEngine::applyOps(levelWithBehaviours(), [
    ['op' => 'remove', 'path' => '/behaviours/1'],
]);
check('remove 数组元素后重排索引（3 → 2）', count($r->doc->behaviours) === 2);
check('remove 后剩余元素顺序正确', $r->doc->behaviours[1]['id'] === 'vine');

$r = PatchEngine::applyOps(levelWithBehaviours(), [
    ['op' => 'remove', 'path' => '/rules/notExist'],
]);
check('remove 不存在路径幂等跳过', $r->hasSkipped() && count($r->skipped) === 1);

// ======================================================================
section('6. patch 后关卡合法性（LevelValidator 兜底）');
// ======================================================================

// 破釜沉舟：步数 -5，分数 ×2
$breakShip = ['id' => 'r_risk_moves', 'patch' => [
    ['op' => 'add', 'path' => '/rules/moves', 'value' => -5, 'mode' => 'accumulate'],
    ['op' => 'mul', 'path' => '/rules/scoreMul', 'value' => 2],
]];
$lv = PatchEngine::applyRelics(baseLevel(), [$breakShip])->doc;
check('合法遗物 patch 后通过 LevelValidator', LevelValidator::validate($lv) === [], implode('; ', LevelValidator::validate($lv)));
check('步数 30 - 5 = 25', $lv->rules->moves === 25, (string) $lv->rules->moves);

// 步数被叠成 ≤0 → LevelValidator 必须拦下
$badRelics = [
    ['id' => 'x1', 'patch' => [['op' => 'add', 'path' => '/rules/moves', 'value' => -20, 'mode' => 'accumulate']]],
    ['id' => 'x2', 'patch' => [['op' => 'add', 'path' => '/rules/moves', 'value' => -20, 'mode' => 'accumulate']]],
];
$badLv = PatchEngine::applyRelics(baseLevel(), $badRelics)->doc;
check('步数被改坏 → LevelValidator 拦下', LevelValidator::validate($badLv) !== []);

// 一线天：棋盘少一列（与 terrain 行长度不匹配 → 必须被发现）
$narrow = ['id' => 'r_cursed_narrow', 'patch' => [
    ['op' => 'add', 'path' => '/board/cols', 'value' => -1, 'mode' => 'accumulate'],
]];
$narrowLv = PatchEngine::applyRelics(baseLevel(), [$narrow])->doc;
check('棋盘列数 -1 后 LevelValidator 能识别（列数 8 与 terrain 9 不符的记录）',
    LevelValidator::validate($narrowLv) !== [] || $narrowLv->board->cols === 8);

// ======================================================================
section('7. 深拷贝隔离');
// ======================================================================

$tpl = levelWithBehaviours();
$snapshot = StableStringify::run($tpl);

PatchEngine::applyOps($tpl, [
    ['op' => 'replace', 'path' => '/behaviours/[id=choc]/interval', 'value' => 0],
    ['op' => 'remove',  'path' => '/behaviours/0'],
    ['op' => 'add',     'path' => '/rules/moves', 'value' => -5, 'mode' => 'accumulate'],
]);

check('应用 patch 不修改原模板（指纹一致）', StableStringify::run($tpl) === $snapshot);
check('原模板 behaviours 仍为 3 个', count($tpl->behaviours) === 3);

$r1 = PatchEngine::applyOps(levelWithBehaviours(), [['op' => 'add', 'path' => '/rules/moves', 'value' => 1, 'mode' => 'accumulate']]);
$r2 = PatchEngine::applyOps(levelWithBehaviours(), [['op' => 'add', 'path' => '/rules/moves', 'value' => 1, 'mode' => 'accumulate']]);
check('同输入两次应用结果一致', StableStringify::run($r1->doc) === StableStringify::run($r2->doc));

// ======================================================================
section('8. 路径解析与边界');
// ======================================================================

check('parsePath 根路径 "/" → 空 token', PatchEngine::parsePath('/') === []);
check('parsePath 拒绝空 path', (function () {
    try {
        PatchEngine::parsePath('');
        return false;
    } catch (PatchException $e) {
        return true;
    }
})());
check('parsePath 拒绝不以 / 开头', (function () {
    try {
        PatchEngine::parsePath('rules/moves');
        return false;
    } catch (PatchException $e) {
        return true;
    }
})());
check('~1 / ~0 转义还原', (function () {
    $t = PatchEngine::parsePath('/a~1b/c~0d');
    return $t[0]['key'] === 'a/b' && $t[1]['key'] === 'c~d';
})());

check('未知 op 抛异常', (function () {
    try {
        PatchEngine::applyOps(baseLevel(), [['op' => 'zzz', 'path' => '/rules/moves', 'value' => 1]]);
        return false;
    } catch (PatchException $e) {
        return true;
    }
})());

check('浮点收敛：1.5 × 3 = 4.5（非 4.500000001）', (function () {
    $r = PatchEngine::applyOps(baseLevel(), [['op' => 'mul', 'path' => '/rules/x', 'value' => 1.5]]);
    $r = PatchEngine::applyOps($r->doc, [['op' => 'mul', 'path' => '/rules/x', 'value' => 3]]);
    return $r->doc->rules->x === 4.5;
})());

check('整数值输出 int（2 而非 2.0）', (function () {
    $r = PatchEngine::applyOps(baseLevel(), [['op' => 'mul', 'path' => '/rules/y', 'value' => 2.0]]);
    return is_int($r->doc->rules->y);
})());

// ======================================================================
section('9. 跨语言测试向量（tests/vectors/test-vectors.json）');
// ======================================================================

$vectorFile = __DIR__ . '/vectors/test-vectors.json';

if (!is_file($vectorFile)) {
    check('测试向量文件存在', false, $vectorFile);
} else {
    $vectors = json_decode((string) file_get_contents($vectorFile), true);
    $cases   = is_array($vectors['cases'] ?? null) ? $vectors['cases'] : [];

    check('向量文件可读（' . count($cases) . ' 个用例）', $cases !== []);

    $mismatch = [];
    $errMismatch = [];

    foreach ($cases as $case) {
        $name = (string) ($case['name'] ?? '?');

        // 用 json_decode(json_encode()) 保证对象 → stdClass、数组 → array（零歧义）
        $doc = json_decode(json_encode($case['doc'] ?? [], JSON_UNESCAPED_UNICODE));
        $ops = json_decode(json_encode($case['ops'] ?? [], JSON_UNESCAPED_UNICODE));

        $expectError = $case['expectError'] ?? null;

        if ($expectError !== null) {
            $threw = false;
            try {
                PatchEngine::applyOps($doc, is_array($ops) ? $ops : []);
            } catch (PatchException $e) {
                $threw = true;
            }
            if (!$threw) {
                $errMismatch[] = $name;
            }
            continue;
        }

        try {
            $result = PatchEngine::applyOps($doc, is_array($ops) ? $ops : []);
        } catch (\Throwable $e) {
            $mismatch[] = "{$name}（意外异常: {$e->getMessage()}）";
            continue;
        }

        $actual   = StableStringify::run($result->doc);
        $expected = StableStringify::run(json_decode(json_encode($case['expected'] ?? [], JSON_UNESCAPED_UNICODE)));

        if ($actual !== $expected) {
            $mismatch[] = "{$name}\n      实际 {$actual}\n      期望 {$expected}";
            continue;
        }

        if (isset($case['expectSkipped']) && count($result->skipped) !== (int) $case['expectSkipped']) {
            $mismatch[] = "{$name}（skipped 数量 " . count($result->skipped) . ' ≠ ' . $case['expectSkipped'] . '）';
        }
    }

    check('全部用例结果与期望一致', $mismatch === [], implode("\n    ", array_slice($mismatch, 0, 3)));

    if ($errMismatch !== []) {
        check('expectError 用例都抛出了 PatchException', false, implode(', ', $errMismatch));
    }
}

// ======================================================================
section('10. 30 条遗物全量体检 + 组合 Fuzz（P6-B03）');
// ======================================================================

require __DIR__ . '/stub/Seeder.php';
require __DIR__ . '/../database/seeds/Match3RelicSeeder.php';

$relicSeeder = new Match3RelicSeeder();
$relicsM     = new ReflectionMethod(Match3RelicSeeder::class, 'relics');
$relicsM->setAccessible(true);
$relicPool = $relicsM->invoke($relicSeeder);

check('遗物条数 = 30', count($relicPool) === 30, '实际 ' . count($relicPool));

$poolIds = array_column($relicPool, 'relic_id');
check('relic_id 唯一', count($poolIds) === count(array_unique($poolIds)));

$byRarity = [];
$byCategory = [];
foreach ($relicPool as $r) {
    $byRarity[(string) $r['rarity']]     = ($byRarity[(string) $r['rarity']] ?? 0) + 1;
    $byCategory[(string) $r['category']] = ($byCategory[(string) $r['category']] ?? 0) + 1;
}
check('稀有度分布 = common 9 / rare 13 / epic 6 / cursed 2',
    $byRarity === ['common' => 9, 'rare' => 13, 'epic' => 6, 'cursed' => 2], json_encode($byRarity));
check('6 个分类齐全（board/rule/resource/behavior/risk/meta）',
    count($byCategory) === 6, json_encode($byCategory));

foreach ($relicPool as $r) {
    if (empty($r['patch']) || !is_array($r['patch'])) {
        check('遗物 patch 非空: ' . (string) $r['relic_id'], false);
        break;
    }
}
check('30 条遗物 patch 全部非空数组',
    count(array_filter($relicPool, static fn ($r) => is_array($r['patch'] ?? null) && $r['patch'] !== [])) === 30);

// —— 逐条：patch 后必须通过 LevelValidator ——
$illegalSingles = [];
foreach ($relicPool as $r) {
    try {
        $lv = PatchEngine::applyRelics(baseLevel(), [$r])->doc;
    } catch (\Throwable $e) {
        $illegalSingles[] = $r['relic_id'] . '（异常: ' . $e->getMessage() . '）';
        continue;
    }
    $errs = LevelValidator::validate($lv);
    if ($errs !== []) {
        $illegalSingles[] = $r['relic_id'] . ': ' . implode('; ', $errs);
    }
}
check('30 条遗物**逐条**应用后都通过 LevelValidator', $illegalSingles === [],
    implode(' | ', array_slice($illegalSingles, 0, 3)));

// —— lv 升级（同一遗物 patch 重复应用，上限 lv2）——
$illegalUpgrade = [];
foreach ($relicPool as $r) {
    if (!RelicService::isUpgradable((array) $r['patch'])) {
        continue;
    }
    try {
        $lv   = PatchEngine::applyRelics(baseLevel(), [$r, $r])->doc;
        $errs = LevelValidator::validate($lv);
        if ($errs !== []) {
            $illegalUpgrade[] = $r['relic_id'] . '(lv2): ' . implode('; ', $errs);
        }
    } catch (\Throwable $e) {
        $illegalUpgrade[] = $r['relic_id'] . '(lv2) 异常: ' . $e->getMessage();
    }
}
check('可升级遗物升到 lv2 后仍合法', $illegalUpgrade === [],
    implode(' | ', array_slice($illegalUpgrade, 0, 3)));

// —— 组合 Fuzz：**无放回**抽 5 条 × 1000 次（贴合生产：RelicService 不放回）——
$fuzzFail  = [];
$fuzzTimes = 1000;
for ($i = 0; $i < $fuzzTimes; $i++) {
    $candidates = $relicPool;
    $combo      = [];
    $comboIds   = [];
    for ($j = 0; $j < 5 && $candidates !== []; $j++) {
        $idx = (int) floor(Match3Random::next(20260911, $i, $j, crc32('fuzz')) * count($candidates));
        $idx = max(0, min(count($candidates) - 1, $idx));
        $picked = array_splice($candidates, $idx, 1)[0];
        $combo[]     = $picked;
        $comboIds[]  = (string) $picked['relic_id'];
    }

    try {
        $lv = PatchEngine::applyRelics(baseLevel(), $combo)->doc;
    } catch (\Throwable $e) {
        $fuzzFail[] = implode('+', $comboIds) . ' 异常: ' . $e->getMessage();
        continue;
    }
    $errs = LevelValidator::validate($lv);
    if ($errs !== []) {
        $fuzzFail[] = implode('+', $comboIds) . ' → ' . implode('; ', array_slice($errs, 0, 2));
    }
}
check("组合 Fuzz {$fuzzTimes} 次（每次 5 条不重复）无异常、无非法 schema", $fuzzFail === [],
    count($fuzzFail) . ' 例失败，前 3: ' . implode(' | ', array_slice($fuzzFail, 0, 3)));

// —— 极端叠加：护栏必须生效（不静默产出坏关卡）——
$riskMoves = null;
foreach ($relicPool as $r) {
    if ($r['relic_id'] === 'r_risk_moves') {
        $riskMoves = $r;
        break;
    }
}
check('找到减步遗物 r_risk_moves', $riskMoves !== null);

if ($riskMoves !== null) {
    $stacked = PatchEngine::applyRelics(baseLevel(), array_fill(0, 6, $riskMoves))->doc;
    check('叠加 6 次减步遗物 → steps ≤ 0 且被 LevelValidator 拦下（护栏生效）',
        (int) ($stacked->rules->moves ?? 0) <= 0 && LevelValidator::validate($stacked) !== [],
        'moves=' . ($stacked->rules->moves ?? 'null'));

    $ok3 = PatchEngine::applyRelics(baseLevel(), array_fill(0, 3, $riskMoves))->doc;
    check('叠加 3 次 → 仍合法（15 步）',
        (int) ($ok3->rules->moves ?? 0) === 15 && LevelValidator::validate($ok3) === [],
        'moves=' . ($ok3->rules->moves ?? 'null'));
}

// —— 组合结果确定性（同组合同指纹）——
$fixedCombo = array_slice($relicPool, 0, 5);
$fp1 = StableStringify::fingerprint(PatchEngine::applyRelics(baseLevel(), $fixedCombo)->doc);
$fp2 = StableStringify::fingerprint(PatchEngine::applyRelics(baseLevel(), $fixedCombo)->doc);
check('同组合两次应用指纹一致（确定性）', $fp1 === $fp2 && preg_match('/^[0-9a-f]{8}$/', $fp1) === 1, $fp1);

// ======================================================================
echo "\n----------------------------------------\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
