<?php
/**
 * 提交三件套（ops + checkpoints + claim）校验自检（Phase 1）
 *
 * 纯 PHP 运行，不依赖框架与数据库：
 *   php src/plugins/match3game/tests/verify-submission.php
 *
 * 覆盖：
 *   1. Match3Random    确定性派生随机（02 §8.5）
 *   2. Match3OpsValidator  L1 操作合法性
 *   3. SubmissionValidator L1+L2 弱校验 / L3 重放比对 / checkpoints 结构
 */

declare(strict_types=1);

$base = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require $base . 'StableStringify.php';
require $base . 'Match3Random.php';
require $base . 'Match3ValidationResult.php';
require $base . 'Match3OpsValidator.php';
require $base . 'Match3ReplayEngineInterface.php';
require $base . 'Match3NullReplayEngine.php';
require $base . 'Match3HttpReplayEngine.php';
require $base . 'SubmissionValidator.php';

use plugin\match3game\service\level\Match3OpsValidator;
use plugin\match3game\service\level\Match3Random;
use plugin\match3game\service\level\Match3ReplayEngineInterface;
use plugin\match3game\service\level\Match3ValidationResult;
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

/**
 * 9×9 标准关卡：第 0 行生成器 S，其余可玩格 o
 */
function schema(array $opts = []): array
{
    $terrain = array_merge(['SSSSSSSSS'], array_fill(0, 8, 'ooooooooo'));
    $entity  = $opts['entity'] ?? null;
    $overlay = $opts['overlay'] ?? null;

    $s = [
        'schema'  => 'match3/level@1',
        'code'    => 'M3-0101',
        'board'   => ['cols' => 9, 'rows' => 9, 'cellSize' => 76, 'origin' => ['c' => 0, 'r' => 0]],
        'regions' => [[
            'id'      => 'main',
            'origin'  => ['c' => 0, 'r' => 0],
            'gravity' => 'down',
            'layers'  => ['terrain' => $terrain],
            'spawn'   => ['mode' => 'top', 'weights' => [1, 1, 1, 1, 1, 1]],
        ]],
        'rules'   => [
            'mode'       => 'moves',
            'moves'      => $opts['moves'] ?? 30,
            'starScores' => [2000, 4000, 6000],
            'goals'      => [['type' => 'score', 'count' => 2000]],
        ],
    ];

    $s['regions'][0]['layers']['entity']  = $entity ?? [];
    $s['regions'][0]['layers']['overlay'] = $overlay ?? [];

    if (isset($opts['props'])) {
        $s['props'] = $opts['props'];
    }

    return $s;
}

/** 合法的一次相邻交换（(1,1) ↔ (2,1)） */
function op(int $ac, int $ar, int $bc, int $br): array
{
    return ['t' => 'swap', 'a' => [$ac, $ar], 'b' => [$bc, $br]];
}

/** 可注入返回值的假重放引擎 */
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
section('1. Match3Random —— 确定性派生随机');
// ======================================================================

check('deriveSeed 同参数同结果', Match3Random::deriveSeed(42, 1, 2) === Match3Random::deriveSeed(42, 1, 2));
check('deriveSeed 不同 salt 不同结果', Match3Random::deriveSeed(42, 1) !== Match3Random::deriveSeed(42, 2));
check('next() 落在 [0,1)', (function () {
    for ($i = 0; $i < 200; $i++) {
        $v = Match3Random::next(7, $i);
        if ($v < 0 || $v >= 1) {
            return false;
        }
    }
    return true;
})());
check('同 seed 随机序列可复现', (function () {
    $rng1 = Match3Random::mulberry32(12345);
    $rng2 = Match3Random::mulberry32(12345);
    for ($i = 0; $i < 50; $i++) {
        if ($rng1() !== $rng2()) {
            return false;
        }
    }
    return true;
})());
check('intBetween 落在闭区间', (function () {
    for ($i = 0; $i < 200; $i++) {
        $v = Match3Random::intBetween(9, 3, 7, $i);
        if ($v < 3 || $v > 7) {
            return false;
        }
    }
    return true;
})());
check('weightedPick 权重为 0 的项永不被选中', (function () {
    for ($i = 0; $i < 100; $i++) {
        if (Match3Random::weightedPick(3, ['a' => 100, 'b' => 0], $i) !== 'a') {
            return false;
        }
    }
    return true;
})());
check('shuffle 是排列且确定性', (function () {
    $src = range(1, 10);
    $a = Match3Random::shuffle(5, $src);
    $b = Match3Random::shuffle(5, $src);
    sort($a);
    return $a === $src && $a === (function () use ($b) { sort($b); return $b; })();
})());

// ======================================================================
section('2. Match3OpsValidator —— L1 操作合法性');
// ======================================================================

check('合法相邻交换通过', Match3OpsValidator::validate(schema(), [op(1, 1, 2, 1)]) === []);
check('空 ops 被拦截', str_contains(implode('', Match3OpsValidator::validate(schema(), [])), '操作序列为空'));
check('不相邻交换被拦截', str_contains(implode('', Match3OpsValidator::validate(schema(), [op(1, 1, 3, 1)])), '不相邻'));
check('越界坐标被拦截', str_contains(implode('', Match3OpsValidator::validate(schema(), [op(1, 1, 99, 1)])), '不在任何 region'));
check('生成器行(S)不可站立被拦截', str_contains(implode('', Match3OpsValidator::validate(schema(), [op(1, 0, 2, 0)])), '不可站立'));
check('未知操作类型被拦截', str_contains(implode('', Match3OpsValidator::validate(schema(), [['t' => 'fly']])), '未知操作类型'));
check('交换次数超步数上限被拦截', str_contains(
    implode('', Match3OpsValidator::validate(schema(['moves' => 2]), [op(1, 1, 2, 1), op(2, 1, 3, 1), op(3, 1, 4, 1)])),
    '超过关卡步数上限'
));

$withStone = schema(['entity' => array_merge(
    array_fill(0, 2, '000000000'),
    ['0X0000000'],
    array_fill(0, 6, '000000000')
)]);
check('石块(X)不可交换被拦截', str_contains(implode('', Match3OpsValidator::validate($withStone, [op(1, 2, 2, 2)])), '不可交换'));

$locked = schema(['overlay' => array_merge(
    array_fill(0, 2, '.........'),
    ['0N0000000'],
    array_fill(0, 6, '.........')
)]);
check('锁定(N)格不可交换被拦截', str_contains(implode('', Match3OpsValidator::validate($locked, [op(1, 2, 2, 2)])), '不可交换'));

// 冰封（冰）：2026-09-27 真机 bug ①——冰封着的元素还能被拖走。
//
// ⚠️ 冰**不能**在 L1 拦：L1 是**静态**校验（只按 `LevelSchemaFactory::fromRow()` 的**初始**关卡定义
//    查格、**不重放**），而冰是**中途可变**的（`OVERLAY_LAYER` 里 `I`/`J` → ice，被消除即置 `.`）。
//    2026-09-27 线上就因此**误杀**了合法提交：`ops[3] a=(3,5) b=(3,4) 元素不可交换: 0`
//    ——那两格**开局**确实有冰，但提交到第 4 步时冰早被消除。
//    权威判定在 **L3 重放引擎**（`Match3Board::swappable()`，看的是**当前棋盘**）。
require_once __DIR__ . '/../service/level/Match3Board.php';

$frozen = schema(['overlay' => array_merge(
    array_fill(0, 2, '000000000'),
    ['0I0000000'],
    array_fill(0, 6, '000000000')
)]);

/** 造一个只改 (2,2) 覆盖层的最小盘面（`swappable` 只读 playable/blocked/overlay/color） */
$cellsWith = static function (string $overlay): array {
    $state = [
        'rows'     => 9,
        'cols'     => 9,
        'playable' => array_fill(0, 9, array_fill(0, 9, true)),
        'blocked'  => array_fill(0, 9, array_fill(0, 9, false)),
        'color'    => array_fill(0, 9, array_fill(0, 9, 0)),
        'overlay'  => array_fill(0, 9, array_fill(0, 9, '.')),
    ];
    if ($overlay !== '.') {
        $state['overlay'][2][2] = $overlay;
    }

    return $state;
};

// ⚠️ 全限定名：本脚本是**全局命名空间**，`Match3Board` 会解析不到（同名类在 plugin\... 下）
check('L3：冰封(I)格不可交换', !\plugin\match3game\service\level\Match3Board::swappable($cellsWith('I'), 2, 2));
check('L3：双层冰(J)同样不可交换', !\plugin\match3game\service\level\Match3Board::swappable($cellsWith('J'), 2, 2));
check(
    'L3：冰被消除后该格可交换（`(3,5)` 那类误杀的回归锁）',
    \plugin\match3game\service\level\Match3Board::swappable($cellsWith('.'), 2, 2)
);
check(
    'L1：不因**初始**有冰而误杀（权威判定留给 L3）',
    Match3OpsValidator::validate($frozen, [op(1, 2, 2, 2)]) === [],
    implode('; ', Match3OpsValidator::validate($frozen, [op(1, 2, 2, 2)]))
);

check('白名单外道具被拦截', str_contains(
    implode('', Match3OpsValidator::validate(schema(['props' => ['allowed' => ['shuffle']]]), [['t' => 'prop', 'p' => 'hammer', 'at' => [1, 1]]])),
    '不在白名单'
));
check('需坐标道具缺 at 被拦截', str_contains(
    implode('', Match3OpsValidator::validate(schema(), [['t' => 'prop', 'p' => 'hammer']])),
    '需要 at 坐标'
));
check('合法道具通过', Match3OpsValidator::validate(
    schema(['props' => ['allowed' => ['hammer']]]),
    [['t' => 'prop', 'p' => 'hammer', 'at' => [1, 1]]]
) === []);

// ======================================================================
section('3. SubmissionValidator —— L1+L2 弱校验（null_engine）');
// ======================================================================

$sv   = new SubmissionValidator();
$subs = [
    'seed'  => 1736438400,
    'ops'   => [op(1, 1, 2, 1)],
    'claim' => ['score' => 5000, 'movesUsed' => 10, 'passed' => true, 'goals' => ['red_gem' => 20]],
];

$res = $sv->validateSubmission(schema(), $subs);
check('合法提交通过', $res->passed, implode('; ', $res->errors));
check('null_engine 下 replayed=false', $res->replayed === false);
check('meta 回填 claim 的 score', (int) ($res->meta['score'] ?? -1) === 5000);

$bad = $subs;
$bad['claim']['score'] = 99999999;
$res = $sv->validateSubmission(schema(), $bad);
check('离谱分数被拦截（invalid_claim）', $res->errorCode === SubmissionValidator::ERR_INVALID_CLAIM);

$bad = $subs;
$bad['claim']['movesUsed'] = 999;
$res = $sv->validateSubmission(schema(), $bad);
check('步数超上限被拦截', $res->errorCode === SubmissionValidator::ERR_INVALID_CLAIM);

$bad = $subs;
$bad['ops'] = [op(1, 1, 5, 1)];
$res = $sv->validateSubmission(schema(), $bad);
check('非法 ops 被拦截（invalid_op）', $res->errorCode === SubmissionValidator::ERR_INVALID_OP);

// ---- checkpoints ----
$cp = $subs;
$cp['checkpoints'] = [['t' => 0, 'h' => 'a3f2c1'], ['t' => 1, 'h' => '7b19de']];
check('合法 checkpoints 通过', $sv->validateSubmission(schema(), $cp)->passed);

$cp = $subs;
$cp['checkpoints'] = [['t' => 1, 'h' => 'a3f2c1'], ['t' => 1, 'h' => '7b19de']];
$res = $sv->validateSubmission(schema(), $cp);
check('checkpoints t 未递增被拦截', $res->errorCode === SubmissionValidator::ERR_CHECKPOINT);

$cp = $subs;
$cp['checkpoints'] = [['t' => 0, 'h' => '']];
$res = $sv->validateSubmission(schema(), $cp);
check('checkpoints 缺 h 被拦截', $res->errorCode === SubmissionValidator::ERR_CHECKPOINT);

$cp = $subs;
$cp['checkpoints'] = array_map(fn ($i) => ['t' => $i, 'h' => 'abc'], range(0, 40));
$res = $sv->validateSubmission(schema(['moves' => 10]), $cp);
check('checkpoints 条数超限被拦截', $res->errorCode === SubmissionValidator::ERR_CHECKPOINT);

// ======================================================================
section('4. SubmissionValidator —— L3 重放比对');
// ======================================================================

$replay = ['score' => 5000, 'moves' => 10, 'goals' => ['red_gem' => 20], 'maxCombo' => 4];

$sv3 = new SubmissionValidator(new FakeReplayEngine($replay));
$res = $sv3->validateSubmission(schema(), $subs);
check('重放一致 → 通过且 replayed=true', $res->passed && $res->replayed === true);
check('meta 采用重放值', (int) ($res->meta['score'] ?? 0) === 5000 && (int) ($res->meta['maxCombo'] ?? 0) === 4);

$mismatch = $subs;
$mismatch['claim']['score'] = 9000;
$res = $sv3->validateSubmission(schema(), $mismatch);
check('分数不一致 → result_mismatch', $res->errorCode === SubmissionValidator::ERR_RESULT_MISMATCH);

$mismatch = $subs;
$mismatch['claim']['goals'] = ['red_gem' => 999];
$res = $sv3->validateSubmission(schema(), $mismatch);
check('目标进度虚报 → result_mismatch', $res->errorCode === SubmissionValidator::ERR_RESULT_MISMATCH);

$mismatch = $subs;
$mismatch['claim']['movesUsed'] = 25;
$res = $sv3->validateSubmission(schema(), $mismatch);
check('步数不一致 → result_mismatch', $res->errorCode === SubmissionValidator::ERR_RESULT_MISMATCH);

// 引擎超时降级（返回 null）→ 放行但 replayed=false
$sv4 = new SubmissionValidator(new FakeReplayEngine(null));
$res = $sv4->validateSubmission(schema(), $mismatch);
check('重放不可用时降级放行', $res->passed && $res->replayed === false);

// ======================================================================
section('5. Match3ValidationResult');
// ======================================================================

$ok = Match3ValidationResult::ok(['score' => 1], true);
check('ok() 语义', $ok->passed && $ok->replayed && $ok->errorCode === null);
$ng = Match3ValidationResult::fail('invalid_op', ['坏操作']);
check('fail() 语义', !$ng->passed && $ng->errorCode === 'invalid_op' && $ng->errors === ['坏操作']);
check('toArray 字段齐全', array_keys($ok->toArray()) === ['passed', 'errors', 'error_code', 'meta', 'replayed']);

/* ======================================================================
 *  L3b：checkpoint 哈希**逐回合**比对（S5-3）
 *
 *  背景：L1b 只做结构校验（t 递增 / h 非空），**值对不对只有重放引擎能验**。
 *  口径：只比对**双方都有**的回合 —— 任一侧缺失就跳过，不计违规，
 *        否则老客户端会因口径差异被**批量误杀**（P6-B01 的教训）。
 * ====================================================================== */

section('5. SubmissionValidator —— checkpoint 逐回合哈希比对（S5-3）');

/** 造一个带 `turnFingerprints` 的假引擎 + 指定了 checkpoints 的提交体 */
function checkpointCase(array $fingerprints, array $checkpoints, bool $strict): array
{
    $engine = new FakeReplayEngine([
        'score'             => 5000,
        'moves'             => 10,
        'goals'             => ['red_gem' => 20],
        'turnFingerprints'  => $fingerprints,
    ]);

    $sv = new SubmissionValidator($engine, [
        'compare_mode'       => 'strict',
        'max_ops'            => 500,
        'checkpoint_strict' => $strict,
    ]);

    $res = $sv->validateSubmission(schema(), [
        'seed'  => 1736438400,
        'ops'   => [op(1, 1, 2, 1)],
        'claim' => [
            'score'        => 5000,
            'movesUsed'    => 10,
            'passed'       => true,
            'goals'        => ['red_gem' => 20],
            'checkpoints'  => $checkpoints,
        ],
    ]);

    return [
        'passed'     => $res->passed,
        'errorCode'  => $res->errorCode,
        'violations' => $sv->getCheckpointViolations(),
    ];
}

$fps = [1 => 'a1b2c3d4', 2 => 'eeff0011'];

// ① 完全一致 → 通过
$r = checkpointCase($fps, [['t' => 1, 'h' => 'a1b2c3d4'], ['t' => 2, 'h' => 'eeff0011']], false);
check('checkpoints 与重放逐回合一致 → 通过', $r['passed']);
check('一致时无违规明细', $r['violations'] === []);

// ② 篡改第 1 回合（观察期：放行 + 标记，不误杀）
$r = checkpointCase($fps, [['t' => 1, 'h' => 'deadbeef'], ['t' => 2, 'h' => 'eeff0011']], false);
check('篡改 checkpoints → 观察期放行（不误杀）', $r['passed']);
check('篡改时产出违规明细', $r['violations'] !== []);

// ③ 篡改 + checkpoint_strict=true → 拒单
$r = checkpointCase($fps, [['t' => 1, 'h' => 'deadbeef'], ['t' => 2, 'h' => 'eeff0011']], true);
check(
    '篡改 + checkpoint_strict=true → board_mismatch 拒单',
    $r['passed'] === false && $r['errorCode'] === SubmissionValidator::ERR_BOARD_MISMATCH
);

// ④ 缺任一侧 → 放行（引擎无指纹 / 客户端没上报）
$r = checkpointCase($fps, [], true);
check('未上报 checkpoints → 放行', $r['passed']);

$r = checkpointCase([], [['t' => 1, 'h' => 'deadbeef']], true);
check('引擎未产出指纹 → 放行', $r['passed']);

echo "\n通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
