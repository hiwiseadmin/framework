<?php
/**
 * PHP 重放引擎 + upper_bound 比对模式自检（Phase 6 前置）
 *
 *   php src/plugins/match3game/tests/verify-php-replay.php
 *
 * 覆盖：
 *   1. 【基线锁】抽出 Match3Board 后 LevelSimulator 行为逐值不变
 *   2. Match3Board 内核（指纹确定性 / 交换语义 / 坐标方向）
 *   3. Match3PhpReplayEngine（确定性 / 忠实性降级 / 道具 / 上界语义）
 *   4. SubmissionValidator 三种比对模式（strict / upper_bound / off）
 *      —— 重点：upper_bound 绝不改通关判定（不误杀）
 */

declare(strict_types=1);

$base = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require $base . 'StableStringify.php';
require $base . 'Match3Random.php';
require $base . 'Match3Board.php';
require $base . 'LevelSchemaFactory.php';
require $base . 'LevelValidator.php';
require $base . 'LevelSimulator.php';
require $base . 'Match3OpsValidator.php';
require $base . 'Match3ValidationResult.php';
require $base . 'Match3ReplayEngineInterface.php';
require $base . 'Match3NullReplayEngine.php';
require $base . 'Match3FillVersion.php';
require $base . 'Match3PhpReplayEngine.php';
require $base . 'SubmissionValidator.php';
require __DIR__ . '/../service/ScoreGuardService.php';

use plugin\match3game\service\ScoreGuardService;
use plugin\match3game\service\level\LevelSimulator;
use plugin\match3game\service\level\Match3Board;
use plugin\match3game\service\level\Match3OpsValidator;
use plugin\match3game\service\level\Match3PhpReplayEngine;
use plugin\match3game\service\level\Match3Random;
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

/** 固定测试关卡（与基线一致，勿改） */
function schema(): array
{
    return [
        'schema'  => 'match3/level@1',
        'version' => 1,
        'code'    => 'BASE-9x9',
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
            'spawn'   => ['mode' => 'top', 'weights' => [20, 20, 20, 20, 20]],
        ]],
        'palette' => ['gems' => ['red_gem', 'blue_gem', 'yellow_gem', 'green_gem', 'purple_gem']],
        'rules'   => [
            'mode'       => 'moves',
            'moves'      => 30,
            'minMatch'   => 3,
            'starScores' => [2000, 4000, 6000],
            'goals'      => [['type' => 'collect', 'target' => 'red_gem', 'count' => 30]],
        ],
    ];
}

/** 一个合法的一步交换（a/b 为 [c, r] 协议坐标，相邻且可交换） */
function oneSwapOp(): array
{
    return [['t' => 'swap', 'a' => [0, 8], 'b' => [1, 8]]];
}

/** 可注入固定结果的假引擎（隔离比对逻辑） */
class FakeReplayEngine implements Match3ReplayEngineInterface
{
    public function __construct(private ?array $result)
    {
    }

    public function replay($schema, int $seed, array $ops, string $fillVersion = ''): ?array
    {
        return $this->result;
    }

    public function isAvailable(): bool
    {
        return $this->result !== null;
    }
}

// ======================================================================
section('1. 【基线锁】抽出 Match3Board 后 LevelSimulator 逐值不变');
// ======================================================================

$sim = new LevelSimulator();

// 基线锁：抽出 Match3Board 后 LevelSimulator 的行为必须逐值不变。
//
// ⚠️ 2026-09-22 **重新快照**：`fill_version` v1 → v2（生成器避开初始连线）是一次
//    **有意的生成算法变更**，这些值必然改变。锁的意义是"防止意外漂移"，
//    因此算法有意变更后应重新取一次基线，而不是把锁删掉。
$baseline = [
    // [seed, k, win, moves, score, turns]
    [1, 0, 1, 13, 1472, 13],
    [1, 1, 1, 20, 1758, 20],
    [1, 2, 1, 26, 1892, 26],
    [42, 0, 1, 14, 1565, 14],
    [42, 1, 1, 29, 1919, 29],
    [42, 2, 1, 22, 1625, 22],
    [20260911, 0, 1, 19, 1839, 19],
    [20260911, 1, 1, 19, 1019, 19],
    [20260911, 2, 1, 21, 1865, 21],
];

$mismatch = [];
foreach ($baseline as $i => [$seed, $k, $win, $moves, $score, $turns]) {
    $r = $sim->playOnce(schema(), $seed, $k);
    if ($r['win'] !== (bool) $win || $r['moves'] !== $moves || $r['score'] !== $score || $r['turns'] !== $turns) {
        $mismatch[] = sprintf(
            "CASE%d 期望 win=%d/moves=%d/score=%d/turns=%d，实得 win=%d/%d/%d/%d",
            $i, $win, $moves, $score, $turns,
            $r['win'] ? 1 : 0, $r['moves'], $r['score'], $r['turns']
        );
    }
}

check('9 个 CASE 的 win/moves/score/turns 全部与重构前一致', $mismatch === [], implode(' | ', $mismatch));

$state = $sim->buildState(schema(), 12345);
check('棋盘指纹与当前基线一致（7d3f3f41，fill_version v2 起）',
    Match3Board::colorFingerprint($state) === '7d3f3f41',
    Match3Board::colorFingerprint($state));

$agg = $sim->simulate(schema(), 999, 10);
check('simulate 聚合值与当前基线一致（passRate=0.8/avgMoves=21.2/avgScore=1781）',
    $agg['passRate'] === 0.8 && $agg['avgMoves'] === 21.2 && $agg['avgScore'] === 1781,
    json_encode(['passRate' => $agg['passRate'], 'avgMoves' => $agg['avgMoves'], 'avgScore' => $agg['avgScore']]));

$collectedProbe = $sim->playOnce(schema(), 1, 0)['collected'];
check(
    'LevelSimulator 的 collected 明细与当前基线一致（seed=1,k=0）',
    // ⚠️ PHP 数组 `===` 连**键顺序**也比，故顺序必须与断言一致（2026-09-22 重新快照）
    $collectedProbe === [
        'red_gem' => 31, 'blue_gem' => 24, 'green_gem' => 33, 'purple_gem' => 19, 'yellow_gem' => 17,
    ],
    json_encode($collectedProbe)
);

// ======================================================================
section('2. Match3Board 内核');
// ======================================================================

$s1 = Match3Board::buildState(schema(), 777);
$s2 = Match3Board::buildState(schema(), 777);
check('同 seed 棋盘完全一致', $s1 === $s2 && Match3Board::colorFingerprint($s1) === Match3Board::colorFingerprint($s2));
check('不同 seed 棋盘不同', Match3Board::colorFingerprint(Match3Board::buildState(schema(), 778)) !== Match3Board::colorFingerprint($s1));
check('第 0 行为生成器（不可站立）', $s1['playable'][0] === array_fill(0, 9, false));
check('第 1~8 行可站立', $s1['playable'][8] === array_fill(0, 9, true));
check('深拷贝安全（改 s1 不影响 s2）', (function () use ($s1, $s2) {
    $a = $s1;
    $a['color'][8][0] = 999;
    return $s2['color'][8][0] !== 999;
})());

$swapState = Match3Board::buildState(schema(), 555);
$cA = $swapState['color'][8][0];
$cB = $swapState['color'][8][1];
Match3Board::swap($swapState, 8, 0, 8, 1);
check('swap 只交换目标两格', $swapState['color'][8][0] === $cB && $swapState['color'][8][1] === $cA);

check('scoreRule 默认 perCell=60', Match3Board::scoreRule(schema())['perCell'] === 60);
check('scoreRule 读 rules.scorePerCell（协议字段优先）',
    Match3Board::scoreRule(['rules' => ['scorePerCell' => 25]])['perCell'] === 25);
check('scoreRule 读 rules.comboMul',
    Match3Board::scoreRule(['rules' => ['comboMul' => [1, 2, 3]]])['comboMul'] === [1.0, 2.0, 3.0]);
check('scoreRule 对 perCell=0 兜底为 1（防除零思路）',
    Match3Board::scoreRule(['rules' => ['scorePerCell' => 0]])['perCell'] === 1);

check('hasAnyMove 对常规关卡为真', Match3Board::hasAnyMove(Match3Board::buildState(schema(), 888), 3));

// ======================================================================
section('3. Match3PhpReplayEngine');
// ======================================================================

$engine = new Match3PhpReplayEngine();
check('isAvailable = true（无外部依赖）', $engine->isAvailable());

$r1 = $engine->replay(schema(), 12345, oneSwapOp());
$r2 = $engine->replay(schema(), 12345, oneSwapOp());
check('重放非 null', is_array($r1));
check('确定性：同输入同输出', $r1 === $r2);
check('确定性：50 次重放结果一致', (function () use ($engine) {
    $first = $engine->replay(schema(), 12345, oneSwapOp());
    for ($i = 0; $i < 50; $i++) {
        if ($engine->replay(schema(), 12345, oneSwapOp()) !== $first) {
            return false;
        }
    }
    return true;
})());
check('返回 moves = 交换条数', ($r1['moves'] ?? -1) === 1, var_export($r1['moves'] ?? null, true));
check('返回 goals 含 score 汇总键', isset($r1['goals']['score']));

// ⚠️ 这个汇总键**不是**收集个数，不能参与"收集量物理上限"——
// 2026-09-27 真机：`score=8676` 撞上个数上限 `4536`（9x9 × 28 步）被误判成作弊（10008）。
// 用**引擎真实输出**锁这条缝：只留元素键与带 score 的完整形状都必须通过。
check('重放 goals 的 score 汇总键不参与收集量上限（2026-09-27 真机回归）', (function () use ($r1) {
    $guard       = new ScoreGuardService([], 100);
    $withScore   = (array) ($r1['goals'] ?? []);
    $elementOnly = $withScore;
    unset($elementOnly['score']);

    return $withScore !== []
        && $guard->checkCollect($elementOnly, 9, 9, (int) ($r1['moves'] ?? 1)) === null
        && $guard->checkCollect($withScore, 9, 9, (int) ($r1['moves'] ?? 1)) === null;
})());
check('返回 fillVersion / scorePerCell / boardFingerprint 诊断字段',
    // 与常量比对而非写死版本号：升 `fill_version` 时这里不该红（v1→v2 曾因此假红）
    ($r1['fillVersion'] ?? '') === Match3PhpReplayEngine::FILL_VERSION
    && ($r1['scorePerCell'] ?? 0) === 60
    && preg_match('/^[0-9a-f]{8}$/', (string) ($r1['boardFingerprint'] ?? '')) === 1);
check('开启 fingerprints 时返回逐回合指纹', (function () {
    $e = new Match3PhpReplayEngine(['fingerprints' => true]);
    $r = $e->replay(schema(), 12345, oneSwapOp());

    return isset($r['turnFingerprints'][1]) && preg_match('/^[0-9a-f]{8}$/', $r['turnFingerprints'][1]) === 1;
})());

// —— 坐标方向：[c, r] 协议 ——
check('坐标方向正确（a=[0,8]/b=[1,8] 等价于交换 (r=8,c=0)↔(r=8,c=1)）', (function () use ($engine) {
    $manual = Match3Board::buildState(schema(), 12345);
    Match3Board::swap($manual, 8, 0, 8, 1);
    $collected = [];
    $cleared = [];
    Match3Board::resolveCascade(
        $manual, 3,
        fn (int $k): int => Match3Random::deriveSeed(12345, 1, $k, crc32('spawn')),
        Match3Board::scoreRule(schema()),
        $collected, $cleared
    );

    $r = $engine->replay(schema(), 12345, oneSwapOp());

    return $r['boardFingerprint'] === Match3Board::colorFingerprint($manual);
})());

check('空 ops → 0 分 0 步', (function () use ($engine) {
    $r = $engine->replay(schema(), 12345, []);

    return $r !== null && $r['score'] === 0 && $r['moves'] === 0;
})());

// —— 忠实性降级：宁可拒绝，不给错分 ——
$up = schema();
$up['regions'][0]['gravity'] = 'up';
check('重力非 down → 降级为 null', $engine->replay($up, 1, oneSwapOp()) === null);

$beh = schema();
$beh['behaviours'] = [['id' => 'choc', 'kind' => 'spread']];
check('含 behaviours → 降级为 null', $engine->replay($beh, 1, oneSwapOp()) === null);

$act = schema();
$act['actors'] = [['kind' => 'boss', 'hp' => 1500]];
check('含 actors（Boss 技能可能锁格）→ 降级为 null', $engine->replay($act, 1, oneSwapOp()) === null);

$exo = schema();
$exo['regions'][0]['layers']['terrain'] = array_merge(['SSSSSSSSS'], array_fill(0, 7, 'ooooooooo'), ['oo>oooooo']);
check('含传送带地形 → 降级为 null', $engine->replay($exo, 1, oneSwapOp()) === null);

check('不支持的道具（rainbow）→ 降级为 null（引擎能力，**不是**拒单）',
    $engine->replay(schema(), 1, [['t' => 'prop', 'p' => 'rainbow', 'at' => [0, 8]]]) === null);

// —— 提交非法：必须返回 INVALID_KEY（上层拒单），**不能**降级 ——
//    P0：若这里降级，攻击者塞一条坏 op 就能把 L3 整体关掉。
$isInvalid = static fn (?array $r): bool => is_array($r) && isset($r[Match3ReplayEngineInterface::INVALID_KEY]);

check('未知 op 类型 → invalid（拒单）', $isInvalid($engine->replay(schema(), 1, [['t' => 'teleport']])));
check('坐标越界 → invalid（拒单）', $isInvalid($engine->replay(schema(), 1, [['t' => 'swap', 'a' => [99, 99], 'b' => [1, 8]]])));
check('不相邻交换 → invalid（拒单）', $isInvalid($engine->replay(schema(), 1, [['t' => 'swap', 'a' => [0, 8], 'b' => [3, 8]]])));
check('prop 缺 at → invalid（拒单）', $isInvalid($engine->replay(schema(), 1, [['t' => 'prop', 'p' => 'hammer']])));
check('ops 超上限 → 降级为 null', (function () use ($engine) {
    $ops = array_fill(0, Match3PhpReplayEngine::MAX_OPS + 1, ['t' => 'swap', 'a' => [0, 8], 'b' => [1, 8]]);

    return $engine->replay(schema(), 1, $ops) === null;
})());
check('schema 为空 → 降级为 null', $engine->replay([], 1, oneSwapOp()) === null);

check('unsupportedReason 对合法关卡返回 null', Match3PhpReplayEngine::unsupportedReason(schema()) === null);

// —— 道具 ——
$hammer = $engine->replay(schema(), 12345, [['t' => 'prop', 'p' => 'hammer', 'at' => [0, 8]]]);
check('hammer 可用且清掉 1 格（collected 总数 ≥ 1）', is_array($hammer) && array_sum($hammer['collected']) >= 1,
    $hammer ? json_encode($hammer['collected']) : 'null');

$bomb = $engine->replay(schema(), 12345, [['t' => 'prop', 'p' => 'bomb', 'at' => [4, 4]]]);
check('bomb 可用且清掉 ≥ 5 格（5×5 邻域内非空）', is_array($bomb) && array_sum($bomb['collected']) >= 5,
    $bomb ? (string) array_sum($bomb['collected']) : 'null');

$shuffle = $engine->replay(schema(), 12345, [['t' => 'prop', 'p' => 'shuffle']]);
check('shuffle 可用且确定性', is_array($shuffle)
    && $shuffle === $engine->replay(schema(), 12345, [['t' => 'prop', 'p' => 'shuffle']]));

check('add_moves 不改棋盘（指纹与空 ops 一致）', (function () use ($engine) {
    $a = $engine->replay(schema(), 12345, []);
    $b = $engine->replay(schema(), 12345, [['t' => 'prop', 'p' => 'add_moves']]);

    return $b !== null && $a['boardFingerprint'] === $b['boardFingerprint'];
})());

// ======================================================================
section('4. SubmissionValidator 比对模式');
// ======================================================================

// ⚠️ 不要硬编码交换坐标：棋盘由 `seed` + 生成算法决定。`fill_version` v2（避开初始连线）后，
//    原先固定的坐标不再成线 → 重放分变 0 → 下面"虚报"系列会失去意义（3×0 仍是 0，不超上界）。
//    这里**现场搜一步真正得分的交换**（与 TS 侧 `tests/visual-sync.test.ts` 同样的做法）。
$probeEngine = new Match3PhpReplayEngine();
$searchSeed  = 12345;
$probeSchema = schema();
$boardRows   = (int) ($probeSchema['board']['rows'] ?? 9);
$boardCols   = (int) ($probeSchema['board']['cols'] ?? 9);

$realOps = null;
$replay  = null;

for ($sr = 0; $sr < $boardRows && $realOps === null; $sr++) {
    for ($sc = 0; $sc + 1 < $boardCols; $sc++) {
        $candidate = [['t' => 'swap', 'a' => [$sc, $sr], 'b' => [$sc + 1, $sr]]];
        $probe     = $probeEngine->replay($probeSchema, $searchSeed, $candidate);

        if (is_array($probe) && (int) ($probe['score'] ?? 0) > 0) {
            $realOps = $candidate;
            $replay  = $probe;
            break;
        }
    }
}

if ($realOps === null) {
    // 兜底：棋盘退化到"没有任何可得分的交换"时仍让脚本跑完（下面的断言会红，便于定位）
    $realOps = oneSwapOp();
    $replay  = $engine->replay($probeSchema, $searchSeed, $realOps);
}

$replayScore = (int) $replay['score'];

$sub = ['seed' => $searchSeed, 'ops' => $realOps, 'claim' => ['score' => 0, 'movesUsed' => 1]];

// —— L1 前置：确保测试用的 ops 是合法的 ——
check('测试用 ops 通过 L1（保证后续比对测试有效）',
    Match3OpsValidator::validate(schema(), $realOps) === [],
    implode('; ', Match3OpsValidator::validate(schema(), $realOps)));

// (a) strict（双向精确比对）—— 2026-09-19 起为**线上默认口径**（mode=node 后两端同源）
$strict = new SubmissionValidator($engine, ['compare_mode' => 'strict']);

$under = $sub;
$under['claim']['score'] = 0;
$res = $strict->validateSubmission(schema(), $under);
// strict 要求两端**完全一致**，因此"少报"同样失败 ——
// 这正是它只适用于"两端口径已对齐"的原因（现在是同一份 TS 实现，见 12-next-phase-plan §4.4）。
check('strict：上报 0 分（低于重放）→ **也失败**（双向比对）',
    !$res->passed && $res->errorCode === SubmissionValidator::ERR_RESULT_MISMATCH);

$inflated = $sub;
// 虚报分必须**明确高于重放上界**：重放分为 0 时 3 倍仍是 0，故取 max(3×, 重放+5000)
$inflated['claim']['score'] = max($replayScore * 3, $replayScore + 5000);
$res = $strict->validateSubmission(schema(), $inflated);
check('strict：上报 3 倍 → result_mismatch', !$res->passed && $res->errorCode === SubmissionValidator::ERR_RESULT_MISMATCH);

$honest = $sub;
$honest['claim']['score'] = $replayScore;
$res = $strict->validateSubmission(schema(), $honest);
check('strict：上报与重放一致 → passed 且 replayed=true', $res->passed && $res->replayed === true);

// (b) upper_bound + flag（观察期）
$ub = new SubmissionValidator($engine, ['compare_mode' => 'upper_bound', 'upper_bound_action' => 'flag']);
$res = $ub->validateSubmission(schema(), $honest);
check('upper_bound(lenient)：正常上报 → passed / suspicious=false',
    $res->passed && ($res->meta['replaySuspicious'] ?? true) === false);

$res = $ub->validateSubmission(schema(), $inflated);
check('upper_bound(flag)：3 倍虚报 → **仍然 passed**（观察期不拦）', $res->passed);
check('upper_bound(flag)：3 倍虚报 → replaySuspicious=true', ($res->meta['replaySuspicious'] ?? false) === true);
check('upper_bound：命中也不改分数（取 claim 原值）', ($res->meta['score'] ?? -1) === ($inflated['claim']['score'] ?? -2));
check('upper_bound：**replayed 必须为 false**（保通关判定不误杀）', $res->replayed === false);
check('upper_bound：meta 带重放分用于监控', ($res->meta['replayScore'] ?? -1) === $replayScore);

// (c) upper_bound + reject
$ubr = new SubmissionValidator($engine, ['compare_mode' => 'upper_bound', 'upper_bound_action' => 'reject']);
check('upper_bound(reject)：3 倍虚报 → result_mismatch',
    !$ubr->validateSubmission(schema(), $inflated)->passed);
check('upper_bound(reject)：正常上报仍通过', $ubr->validateSubmission(schema(), $honest)->passed);

// (d) off
$off = new SubmissionValidator($engine, ['compare_mode' => 'off']);
$res = $off->validateSubmission(schema(), $inflated);
check('off：离谱上报也不拦（完全不做比对）', $res->passed && $res->replayed === false);

// (e) 引擎不可用（return null）→ 回落弱校验
$nullEngine = new SubmissionValidator(new FakeReplayEngine(null), ['compare_mode' => 'upper_bound', 'upper_bound_action' => 'reject']);
$res = $nullEngine->validateSubmission(schema(), $inflated);
check('引擎无法重放（null）→ 回落弱校验放行（不误杀）', $res->passed && $res->replayed === false);

// (f) 容差边界
$ub2 = new SubmissionValidator($engine, ['compare_mode' => 'upper_bound', 'upper_bound_action' => 'reject', 'score_tolerance' => 0.2]);
$edge = $sub;
$edge['claim']['score'] = (int) floor($replayScore * 1.2) + 10; // 恰好落在 ceiling
check('容差边界：恰好等于上界 → 通过', $ub2->validateSubmission(schema(), $edge)->passed);
$over = $sub;
$over['claim']['score'] = (int) floor($replayScore * 1.2) + 11;
check('容差边界：超过上界 1 分 → 拒绝', !$ub2->validateSubmission(schema(), $over)->passed);

// (g) 目标进度上界（走反射直接测 compareUpperBound，隔离 L2 干扰）
$method = new ReflectionMethod(SubmissionValidator::class, 'compareUpperBound');
$method->setAccessible(true);
$ubv = new SubmissionValidator($engine, ['compare_mode' => 'upper_bound', 'score_tolerance' => 0.2, 'goal_slack' => 1]);

check('目标进度：客户端多上报"服务端算不出的键" → **不违规**（server-driven 防误杀）',
    $method->invoke($ubv, ['score' => 100, 'goals' => ['red_gem' => 5]], ['score' => 100, 'goals' => ['red_gem' => 5, 'unknown_key' => 999]]) === []);
check('目标进度：虚报已知键 → 违规',
    $method->invoke($ubv, ['score' => 100, 'goals' => ['red_gem' => 5]], ['score' => 100, 'goals' => ['red_gem' => 999]]) !== []);
check('分数：低于重放 → 不违规',
    $method->invoke($ubv, ['score' => 1000, 'goals' => []], ['score' => 100, 'goals' => []]) === []);
check('分数：略高于容差内 → 不违规',
    $method->invoke($ubv, ['score' => 1000, 'goals' => []], ['score' => 1100, 'goals' => []]) === []);
check('分数：超出容差 → 违规',
    $method->invoke($ubv, ['score' => 1000, 'goals' => []], ['score' => 1400, 'goals' => []]) !== []);

// (h) setter / getter
$sv = new SubmissionValidator($engine, []);
check('默认 compare_mode（无配置时）= strict', $sv->getCompareMode() === SubmissionValidator::COMPARE_STRICT);
$sv->setCompareMode('upper_bound')->setUpperBoundAction('reject')->setScoreTolerance(0.5);
check('setter 生效', $sv->getCompareMode() === 'upper_bound');
check('非法 mode 被忽略（保持原值）', $sv->setCompareMode('bogus')->getCompareMode() === 'upper_bound');

// (i) 对比：同一虚报在三种模式下的结论差异（这是 upper_bound 的全部意义）
$honestRes   = $strict->validateSubmission(schema(), $honest)->passed;
$inflatedRes = $strict->validateSubmission(schema(), $inflated)->passed;
check('strict 能区分诚实与虚报（诚实通过、虚报拒绝）', $honestRes && !$inflatedRes);
check('upper_bound(flag) 两者都放行，仅用 suspicious 区分', (function () use ($ub, $honest, $inflated) {
    $a = $ub->validateSubmission(schema(), $honest);
    $b = $ub->validateSubmission(schema(), $inflated);

    return $a->passed && $b->passed
        && ($a->meta['replaySuspicious'] ?? true) === false
        && ($b->meta['replaySuspicious'] ?? false) === true;
})());

// ======================================================================
echo "\n----------------------------------------\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
