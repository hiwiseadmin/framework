<?php

declare(strict_types=1);

/**
 * 广告加步（复活 / 中途加步）的步数上限放行自检（S3-9）
 *
 *   php src/plugins/match3game/tests/verify-ad-added-moves.php
 *
 * 【背景】服务端 `Match3AdRewardHandler` 对 `revive` / `add_moves` **只埋点**，
 *   加步由客户端权威执行（`config/match3.php` 的注释就是这么写的）。于是：
 *     · 看广告加步后多出来的交换，客户端只能靠 `claim.addedMoves` 上报
 *     · 道具 `add_moves` 虽然会记 op，但 L1 的交换计数原先只比 `rules.moves`
 *   两者都会让**正常玩家**被判"交换次数超过关卡步数上限"（11001）而拒单。
 *
 * 【本脚本锁什么】
 *   1. 无加步来源时，行为与从前**完全一致**（回归锁，不能悄悄放宽）
 *   2. 有加步来源时按量放宽（claim.addedMoves / add_moves op 都认）
 *   3. 放宽有**上限**，且 `claim.addedMoves` 会被截断（防止把允许量撑到没边）
 */

$base = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require $base . 'StableStringify.php';
require $base . 'Match3Random.php';
require $base . 'Match3Board.php';
require $base . 'LevelValidator.php';
require $base . 'Match3OpsValidator.php';
require $base . 'Match3ValidationResult.php';
require $base . 'Match3ReplayEngineInterface.php';
require $base . 'Match3NullReplayEngine.php';
require $base . 'Match3FillVersion.php';
require $base . 'SubmissionValidator.php';

use plugin\match3game\service\level\Match3NullReplayEngine;
use plugin\match3game\service\level\Match3OpsValidator;
use plugin\match3game\service\level\SubmissionValidator;

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

/** 固定测试关卡（`rules.moves` 可调） */
function schemaWithMoves(int $moves): array
{
    return [
        'schema'  => 'match3/level@1',
        'version' => 1,
        'code'    => 'AD-MOVES-9x9',
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
            'moves'      => $moves,
            'minMatch'   => 3,
            'starScores' => [2000, 4000, 6000],
            'goals'      => [['type' => 'collect', 'target' => 'red_gem', 'count' => 30]],
        ],
    ];
}

/** 生成 n 个合法交换 op（第 8 行全是非 S 地形，相邻可交换） */
function swapOps(int $n): array
{
    $ops = [];
    for ($i = 0; $i < $n; $i++) {
        // 交替方向，避免"同一对反复交换"被其它规则挑刺
        $ops[] = $i % 2 === 0
            ? ['t' => 'swap', 'a' => [0, 8], 'b' => [1, 8]]
            : ['t' => 'swap', 'a' => [2, 8], 'b' => [3, 8]];
    }

    return $ops;
}

/** 是否含"交换次数超过上限"类错误 */
function hasMoveLimitError(array $errors): bool
{
    foreach ($errors as $e) {
        if (str_contains((string) $e, '交换次数') || str_contains((string) $e, '超过关卡步数上限')) {
            return true;
        }
    }

    return false;
}

echo "==============================================================\n";
echo " 广告加步的步数上限放行自检（S3-9）\n";
echo "==============================================================\n";

/* ---------- 1. 回归锁：没有加步来源时行为不变 ---------- */
echo "\n[1] 无加步来源：与从前一致\n";
$schema = schemaWithMoves(3);
$ops    = swapOps(6); // 6 步 > 3 步上限

check('默认调用（extraMoves=0）仍拒绝超限交换', hasMoveLimitError(Match3OpsValidator::validate($schema, $ops)));
check('恰好等于上限时通过', Match3OpsValidator::validate($schema, swapOps(3)) === []);

/* ---------- 2. 按量放宽 ---------- */
echo "\n[2] 有加步来源：按量放宽\n";
check('extraMoves=3 时 6 步交换不再报步数错误', !hasMoveLimitError(Match3OpsValidator::validate($schema, $ops, 3)));
check('extraMoves=2 时仍不够（6 > 3+2）', hasMoveLimitError(Match3OpsValidator::validate($schema, $ops, 2)));

/* ---------- 3. allowance 计算（反射调 protected） ---------- */
echo "\n[3] 允许量的计算口径\n";

$validator = new SubmissionValidator(new Match3NullReplayEngine());
$method    = new ReflectionMethod($validator, 'extraMovesAllowance');
$method->setAccessible(true);

$addMovesOp = ['t' => 'prop', 'p' => 'add_moves'];

check(
    'add_moves op（每条约 3 步）计入',
    $method->invoke($validator, [$addMovesOp, $addMovesOp], []) === 6,
    'got ' . var_export($method->invoke($validator, [$addMovesOp, $addMovesOp], []), true)
);

check(
    'claim.addedMoves 计入（5 步）',
    $method->invoke($validator, [], ['addedMoves' => 5]) === 5
);

check(
    '两者叠加（2 op + 5 步 = 11）',
    $method->invoke($validator, [$addMovesOp, $addMovesOp], ['addedMoves' => 5]) === 11
);

check('无加步来源时为 0', $method->invoke($validator, [], []) === 0);

$capped = $method->invoke($validator, [], ['addedMoves' => 999]);
check(
    'claim.addedMoves 超出上限时被截断到 ' . SubmissionValidator::MAX_AD_ADDED_MOVES,
    $capped === SubmissionValidator::MAX_AD_ADDED_MOVES,
    'got ' . var_export($capped, true)
);

check(
    '负值不放大允许量',
    $method->invoke($validator, [], ['addedMoves' => -50]) === 0
);

echo "\n通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
