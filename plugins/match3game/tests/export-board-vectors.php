<?php
/**
 * 导出棋盘确定性算法「黄金测试向量」（跨语言一致性用）
 *
 *   php src/plugins/match3game/tests/export-board-vectors.php match3game/vectors/test-vectors-board.json
 *
 * 【为什么需要】
 *   服务端（本脚本）产出权威结果，客户端 TS 实现必须逐组完全命中。
 *   算法一有变更，重跑本脚本即可刷新基线；CI 比对失败即说明两端漂移。
 *
 * ⚠️ 必须用文件参数模式：Windows PowerShell 的 `>` 重定向默认写 UTF-16（带 BOM），
 *    会破坏 JSON 导致 TS 侧解析失败。
 *
 * 【覆盖】
 *   match3random —— deriveSeed / next / intBetween / weightedPick / shuffle
 *   board       —— Match3Board::buildState 完整棋盘 + 颜色指纹 + 开局有解
 *   replay      —— Match3PhpReplayEngine 跑 ops → score / goals / maxCombo / 指纹
 *
 * 【算法口径】唯一权威实现 = Match3Board + Match3Random
 *   详见 docs/match3/10-seed-algorithm-confirm-list.md
 */

declare(strict_types=1);

$base = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require $base . 'StableStringify.php';
require $base . 'Match3Random.php';
require $base . 'LevelSchemaFactory.php';
require $base . 'Match3Board.php';
require $base . 'LevelValidator.php';
require $base . 'LevelSimulator.php';
require $base . 'Match3OpsValidator.php';
require $base . 'Match3ValidationResult.php';
require $base . 'Match3ReplayEngineInterface.php';
require $base . 'Match3NullReplayEngine.php';
require $base . 'Match3FillVersion.php';
require $base . 'Match3PhpReplayEngine.php';

use plugin\match3game\service\level\Match3Board;
use plugin\match3game\service\level\Match3PhpReplayEngine;
use plugin\match3game\service\level\Match3Random;

$GEMS6 = ['red_gem', 'blue_gem', 'yellow_gem', 'green_gem', 'purple_gem', 'orange_gem'];

function mkSchema(array $terrain, array $gems, array $weights, array $entity = [], array $overlay = [], array $rules = []): array
{
    $rows = count($terrain);
    $cols = strlen((string) $terrain[0]);

    return [
        'schema'  => 'match3/level@1',
        'code'    => 'VEC',
        'board'   => ['cols' => $cols, 'rows' => $rows, 'cellSize' => 76, 'origin' => ['c' => 0, 'r' => 0]],
        'palette' => ['gems' => $gems, 'theme' => 'orchard'],
        'regions' => [[
            'id'      => 'main',
            'origin'  => ['c' => 0, 'r' => 0],
            'gravity' => 'down',
            'layers'  => ['terrain' => $terrain, 'entity' => $entity, 'overlay' => $overlay],
            'spawn'   => ['mode' => 'top', 'weights' => $weights],
        ]],
        'rules'   => array_merge([
            'mode'       => 'moves',
            'minMatch'   => 3,
            'moves'      => 25,
            'goals'      => [['type' => 'collect', 'target' => $gems[0], 'count' => 9999]],
            'starScores' => [1000, 3000, 6000],
        ], $rules),
    ];
}

$plain9 = array_fill(0, 9, 'ooooooooo');
$genRow = array_merge(['SSSSSSSSS'], array_fill(0, 8, 'ooooooooo'));
$holed  = ['ooooooooo', 'oo.oooooo', 'ooooooooo', 'ooooo.ooo', 'ooooooooo',
           'ooooooooo', 'oo.oooooo', 'ooooooooo', 'ooo.ooooo'];
$small8 = array_fill(0, 8, 'oooooooo');
$rect   = array_fill(0, 7, 'oooooooooo');

$entity9  = ['ooooooooo', 'oXooooooo', 'ooooooooo', 'ooooooXoo', 'ooooooooo',
             'oXooooooo', 'ooooooooo', 'ooooooooo', 'oooooXooo'];
$overlay9 = ['ooooooooo', 'ooIoooooo', 'ooooooooo', 'ooooIoooo', 'ooooooooo',
             'oooIIIooo', 'ooooooooo', 'ooIoooooo', 'ooooooooo'];

$schemas = [
    'plain9x9'   => mkSchema($plain9,  $GEMS6, [1, 1, 1, 1, 1, 1]),
    'genRow9x9'  => mkSchema($genRow,  $GEMS6, [1, 1, 1, 1, 1, 1]),
    'holed9x9'   => mkSchema($holed,   $GEMS6, [1, 1, 1, 1, 1, 1]),
    'uneven8x8'  => mkSchema($small8,  $GEMS6, [3, 1, 1, 1, 1, 0]),
    'entity9x9'  => mkSchema($entity9, $GEMS6, [1, 1, 1, 1, 1, 1]),
    'overlay9x9' => mkSchema($plain9,  $GEMS6, [1, 1, 1, 1, 1, 1], [], $overlay9),
    'rect7x10'   => mkSchema($rect,    $GEMS6, [1, 1, 1, 1, 1, 1]),
    'threeColor' => mkSchema($small8,  ['a', 'b', 'c'], [1, 1, 1]),
    'comboHeavy' => mkSchema($plain9,  $GEMS6, [1, 1, 1, 1, 1, 1], [], [], ['comboMul' => [1.0, 1.5, 2.0, 3.0]]),
];

$out = [
    '_readme' => [
        'purpose'     => '棋盘确定性算法黄金测试向量：服务端权威结果，客户端 TS 实现必须逐组命中',
        'source'      => 'src/plugins/match3game/tests/export-board-vectors.php',
        'contract'    => 'docs/match3/10-seed-algorithm-confirm-list.md',
        'algo'        => 'Match3Board + Match3Random (mulberry32 + deriveSeed)',
        'fillVersion' => Match3PhpReplayEngine::FILL_VERSION,
        'php'         => PHP_VERSION,
        'generatedAt' => date('c'),
    ],
    'schemas' => $schemas,
    'random'  => [],
    'board'   => [],
    'replay'  => [],
];

/* ---------- 1. Match3Random ---------- */

$salts = [0, 1, 7, 42, 255, 65535, 4114845364, 2416889831, 872307057, 2911262305, 57824699, 2147483647];

foreach ([1, 7, 42, 12345, 999983, 1736438400, 2147483647] as $base) {
    foreach ($salts as $s) {
        $out['random'][] = ['fn' => 'deriveSeed',  'args' => [$base, $s], 'value' => Match3Random::deriveSeed($base, $s)];
        $out['random'][] = ['fn' => 'deriveSeed3', 'args' => [$base, $s, 3, 4114845364], 'value' => Match3Random::deriveSeed($base, $s, 3, 4114845364)];
        $out['random'][] = ['fn' => 'next',        'args' => [$base, $s], 'value' => Match3Random::next($base, $s)];
        $out['random'][] = ['fn' => 'intBetween',  'args' => [$base, 3, 77, $s], 'value' => Match3Random::intBetween($base, 3, 77, $s)];
    }

    $out['random'][] = ['fn' => 'weightedPick', 'args' => [$base, ['a' => 60, 'b' => 30, 'c' => 10], 0],
                        'value' => Match3Random::weightedPick($base, ['a' => 60, 'b' => 30, 'c' => 10], 0)];
    $out['random'][] = ['fn' => 'weightedPick', 'args' => [$base, ['a' => 100, 'b' => 0], 1],
                        'value' => Match3Random::weightedPick($base, ['a' => 100, 'b' => 0], 1)];
    $out['random'][] = ['fn' => 'weightedPick', 'args' => [$base, ['x' => 1, 'y' => 1, 'z' => 1, 'w' => 1], 2],
                        'value' => Match3Random::weightedPick($base, ['x' => 1, 'y' => 1, 'z' => 1, 'w' => 1], 2)];

    foreach ([[1, 2, 3, 4, 5, 6, 7, 8], ['a', 'b', 'c'], [10, 20, 30, 40, 50]] as $i => $arr) {
        $out['random'][] = ['fn' => 'shuffle', 'args' => [$base, $arr, $i], 'value' => Match3Random::shuffle($base, $arr, $i)];
    }
}

/* ---------- 2. Match3Board::buildState ---------- */

// playable / blocked / overlay 只由 schema 决定、与 seed 无关，
// 因此每 schema 只存一份（否则 225 个用例会重复三张大矩阵，文件暴涨到 2MB+）。
$derived = [];

$boardSeeds = [1, 2, 7, 42, 101, 202, 303, 404, 505, 555, 606, 707, 777, 808, 888, 909,
               1111, 2222, 3333, 12345, 20260908, 20260911, 1736438400, 2147483646, 2147483647];

foreach (array_keys($schemas) as $sName) {
    $probe          = Match3Board::buildState($schemas[$sName], 1);
    $derived[$sName] = [
        'rows'     => $probe['rows'],
        'cols'     => $probe['cols'],
        'colors'   => $probe['colors'],
        'playable' => $probe['playable'],
        'blocked'  => $probe['blocked'],
        'overlay'  => $probe['overlay'],
    ];

    foreach ($boardSeeds as $seed) {
        $state    = Match3Board::buildState($schemas[$sName], $seed);
        $minMatch = (int) ($schemas[$sName]['rules']['minMatch'] ?? 3);

        $out['board'][] = [
            'schema'      => $sName,
            'seed'        => $seed,
            'color'       => $state['color'],
            'hasAnyMove'  => Match3Board::hasAnyMove($state, $minMatch),
            'fingerprint' => Match3Board::colorFingerprint($state),
        ];
    }
}
$out['derived'] = $derived;

/* ---------- 3. Match3PhpReplayEngine ---------- */

function findSwap(array $state, int $minMatch): ?array
{
    for ($r = 0; $r < $state['rows']; $r++) {
        for ($c = 0; $c < $state['cols']; $c++) {
            if (!Match3Board::swappable($state, $r, $c)) {
                continue;
            }
            foreach ([[0, 1], [1, 0]] as $d) {
                $r2 = $r + $d[0];
                $c2 = $c + $d[1];
                if ($r2 >= $state['rows'] || $c2 >= $state['cols'] || !Match3Board::swappable($state, $r2, $c2)) {
                    continue;
                }
                if ($state['color'][$r][$c] === $state['color'][$r2][$c2]) {
                    continue;
                }
                Match3Board::swap($state, $r, $c, $r2, $c2);
                $hits = Match3Board::lineCountAt($state, $r, $c, $minMatch)
                      + Match3Board::lineCountAt($state, $r2, $c2, $minMatch);
                Match3Board::swap($state, $r, $c, $r2, $c2);
                if ($hits > 0) {
                    return [$r, $c, $r2, $c2];
                }
            }
        }
    }

    return null;
}

/**
 * PHP 的空数组 `[]` 可能是「空列表」也可能是「空映射」，json_encode 一律输出 `[]`；
 * 而 TS 侧对应的是 `{}`（Record）。为了让向量对"空映射"无歧义，统一转成 stdClass → `{}`。
 */
$asMap = static function ($v) {
    if (is_array($v)) {
        return $v === [] ? new stdClass() : $v;
    }
    return $v;
};

$engine = new Match3PhpReplayEngine([]);
$cases  = [
    ['plain9x9', 42, 8], ['plain9x9', 777, 12], ['plain9x9', 20260911, 6],
    ['genRow9x9', 12345, 8], ['holed9x9', 555, 10], ['uneven8x8', 888, 10],
    ['entity9x9', 20260908, 10], ['overlay9x9', 777, 10], ['rect7x10', 42, 8],
    ['threeColor', 12345, 10], ['comboHeavy', 888, 8], ['plain9x9', 2147483647, 6],
];

foreach ($cases as $case) {
    $sName   = $case[0];
    $seed    = $case[1];
    $maxOps  = $case[2];
    $schema  = $schemas[$sName];
    $minMatch = (int) ($schema['rules']['minMatch'] ?? 3);
    $work    = Match3Board::buildState($schema, $seed);
    $ops     = [];

    for ($i = 0; $i < $maxOps; $i++) {
        $sw = findSwap($work, $minMatch);
        if ($sw === null) {
            break;
        }
        $r1 = $sw[0]; $c1 = $sw[1]; $r2 = $sw[2]; $c2 = $sw[3];
        // 协议坐标是 [c, r]
        $ops[] = ['t' => 'swap', 'a' => [$c1, $r1], 'b' => [$c2, $r2]];

        Match3Board::swap($work, $r1, $c1, $r2, $c2);

        // 推进本地状态，让下一步贪心建立在真实的棋盘上。
        // turn 用 $i + 1 —— 与引擎「先 ++ 再用于补货」的口径一致。
        $turn     = $i + 1;
        $combo    = 0;
        $collected = [];
        $cleared   = [];
        Match3Board::resolveCascade(
            $work,
            $minMatch,
            function (int $k) use ($seed, $turn): int {
                return Match3Random::deriveSeed($seed, $turn, $k, crc32('spawn'));
            },
            Match3Board::scoreRule($schema),
            $collected,
            $cleared,
            $combo
        );
    }

    $res = $engine->replay($schema, $seed, $ops);

    if ($res === null) {
        continue; // 该组合引擎无法忠实重放，跳过
    }

    $out['replay'][] = [
        'schema'      => $sName,
        'seed'        => $seed,
        'ops'         => $ops,
        'score'       => (int) $res['score'],
        'moves'       => (int) $res['moves'],
        'goals'       => $asMap($res['goals']),
        'maxCombo'    => (int) $res['maxCombo'],
        'collected'   => $asMap($res['collected']),
        'cleared'     => $asMap($res['cleared']),
        'fingerprint' => $res['boardFingerprint'],
    ];
}

$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

// 支持 `php export-board-vectors.php <outFile>`；不传则走 stdout。
// ⚠️ 建议用文件模式：Windows PowerShell 的 `>` 重定向默认写 UTF-16，会带 BOM 破坏 JSON。
$outFile = $argv[1] ?? '';
if ($outFile !== '') {
    file_put_contents($outFile, $json . "\n");
    fwrite(STDERR, sprintf("written: %s (%d bytes)\n", $outFile, strlen($json)));
} else {
    echo $json, "\n";
}
