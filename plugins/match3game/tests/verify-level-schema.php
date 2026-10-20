<?php
/**
 * Level Schema v1 地基自检（Phase 0）
 *
 * 项目未引入 PHPUnit，本脚本用纯 PHP 运行，不依赖框架容器与数据库：
 *   php src/plugins/match3game/tests/verify-level-schema.php
 *
 * 覆盖：
 *   1. LevelValidator  —— 01 §10.3 发布前校验的全部规则
 *   2. LevelSchemaFactory —— fromRow / toRow 往返、元素池
 *      （可站立掩码改由 Match3Board::buildState() 产出，避免双实现）
 *   3. StableStringify —— 稳定序列化与指纹（与 TS 侧对齐）
 */

declare(strict_types=1);

$base = __DIR__ . '/../service/level/';
require $base . 'StableStringify.php';
require $base . 'LevelValidator.php';
require $base . 'LevelSchemaFactory.php';
require $base . 'Match3Random.php';
require $base . 'Match3Board.php';

use plugin\match3game\service\level\LevelSchemaFactory;
use plugin\match3game\service\level\LevelValidator;
use plugin\match3game\service\level\Match3Board;
use plugin\match3game\service\level\StableStringify;

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
 * 标准 9×9 关卡（terrain 第 0 行生成器，其余可玩格）
 *
 * 注意：override 对顶层键是**整体替换**（rules 内部才是逐键合并），
 * 这样传 ['goals' => []] 才能真的把目标清空（array_replace_recursive 做不到）。
 */
function standardLevel(array $override = []): array
{
    $level = [
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
    ];

    foreach ($override as $key => $value) {
        if ($key === 'rules' && is_array($value)) {
            $level['rules'] = array_merge($level['rules'], $value);
        } else {
            $level[$key] = $value;
        }
    }

    return $level;
}

// ======================================================================
section('1. LevelValidator —— 合法关卡');
// ======================================================================

check('标准 9×9 关卡校验通过', LevelValidator::validate(standardLevel()) === []);

$ice = standardLevel();
$ice['regions'][0]['layers']['overlay'] = array_merge(
    array_fill(0, 4, '.........'),
    array_fill(0, 2, '..IIII...'),
    array_fill(0, 3, '.........')
);
$ice['rules']['goals'] = [['type' => 'clear', 'layer' => 'ice', 'count' => 6]];
check('带冰层 + clear 目标校验通过', LevelValidator::validate($ice) === []);

// ======================================================================
section('2. LevelValidator —— 元信息与棋盘');
// ======================================================================

check('缺 schema 被拦截', str_contains(implode('', LevelValidator::validate(array_diff_key(standardLevel(), ['schema' => 0]))), 'schema'));
check('缺 code 被拦截', str_contains(implode('', LevelValidator::validate(array_diff_key(standardLevel(), ['code' => 0]))), 'code'));

$noBoard = standardLevel();
unset($noBoard['board']);
check('缺 board 被拦截', str_contains(implode('', LevelValidator::validate($noBoard)), 'board'));

$huge = standardLevel(['board' => ['cols' => 30, 'rows' => 9, 'cellSize' => 76]]);
check('棋盘尺寸超上限被拦截', str_contains(implode('', LevelValidator::validate($huge)), '超出合理区间'));

// ======================================================================
section('3. LevelValidator —— regions');
// ======================================================================

$noRegions = standardLevel();
$noRegions['regions'] = [];
check('regions 为空被拦截', str_contains(implode('', LevelValidator::validate($noRegions)), 'regions 为空'));

$ragged = standardLevel();
$ragged['regions'][0]['layers']['terrain'][3] = 'oooooo';
check('terrain 行长度不一致被拦截', str_contains(implode('', LevelValidator::validate($ragged)), '行长度'));

$badChar = standardLevel();
$badChar['regions'][0]['layers']['terrain'][3] = 'ooooXoooo';
check('terrain 未知字符被拦截', str_contains(implode('', LevelValidator::validate($badChar)), '未知字符'));

$noSpawn = standardLevel();
$noSpawn['regions'][0]['layers']['terrain'] = array_fill(0, 9, 'ooooooooo');
check('无生成器 S 被拦截', str_contains(implode('', LevelValidator::validate($noSpawn)), '生成器'));

$overlap = standardLevel();
$overlap['regions'][1] = $overlap['regions'][0];
check('region 重叠被拦截', str_contains(implode('', LevelValidator::validate($overlap)), '重叠'));

$sizeMismatch = standardLevel();
$sizeMismatch['regions'][0]['layers']['overlay'] = array_fill(0, 8, '.........');
check('overlay 行数与 terrain 不一致被拦截', str_contains(implode('', LevelValidator::validate($sizeMismatch)), '行数'));

// ======================================================================
section('4. LevelValidator —— rules');
// ======================================================================

$noRules = standardLevel();
unset($noRules['rules']);
check('缺 rules 被拦截', str_contains(implode('', LevelValidator::validate($noRules)), 'rules'));

check('步数 <= 0 被拦截', str_contains(implode('', LevelValidator::validate(standardLevel(['rules' => ['moves' => -5]]))), 'moves 非法'));
check('目标为空被拦截', str_contains(implode('', LevelValidator::validate(standardLevel(['rules' => ['goals' => []]]))), 'goals 为空'));
check('目标缺 type 被拦截', str_contains(implode('', LevelValidator::validate(standardLevel(['rules' => ['goals' => [['count' => 1]]]]))), '缺少 type'));
check('目标数量 <= 0 被拦截', str_contains(implode('', LevelValidator::validate(standardLevel(['rules' => ['goals' => [['type' => 'score', 'count' => 0]]]]))), '数量非法'));
check('星阈非 3 个被拦截', str_contains(implode('', LevelValidator::validate(standardLevel(['rules' => ['starScores' => [1, 2]]]))), '3 个阈值'));
check('星阈非递增被拦截', str_contains(implode('', LevelValidator::validate(standardLevel(['rules' => ['starScores' => [5, 5, 5]]]))), '必须递增'));
check('minMatch 越界被拦截', str_contains(implode('', LevelValidator::validate(standardLevel(['rules' => ['minMatch' => 9]]))), 'minMatch'));
check(
    'clear 目标层不可清除被拦截',
    str_contains(implode('', LevelValidator::validate(standardLevel(['rules' => ['goals' => [['type' => 'clear', 'layer' => 'lava', 'count' => 3]]]]))), '不可清除')
);

// ======================================================================
section('5. LevelValidator —— portals');
// ======================================================================

$badPortal = standardLevel();
$badPortal['portals'] = [['id' => 'p1', 'from' => ['region' => 'ghost', 'c' => 0, 'r' => 0], 'to' => ['region' => 'main', 'c' => 1, 'r' => 1]]];
check('portal 引用不存在 region 被拦截', str_contains(implode('', LevelValidator::validate($badPortal)), '不存在的 region'));

$okPortal = standardLevel();
$okPortal['portals'] = [['id' => 'p1', 'from' => ['region' => 'main', 'c' => 0, 'r' => 1], 'to' => ['region' => 'main', 'c' => 8, 'r' => 1]]];
check('合法 portal 通过', LevelValidator::validate($okPortal) === []);

// ======================================================================
section('6. LevelSchemaFactory —— fromRow / toRow 往返');
// ======================================================================

$row = [
    'code'        => 'M3-0203',
    'name'        => '果冻迷阵',
    'chapter'     => '第二章·进阶',
    'difficulty'  => 3,
    'schema_ver'  => 'match3/level@1',
    'version'     => 4,
    'board_rows'  => 9,
    'board_cols'  => 9,
    'cell_size'   => 76,
    'regions'     => [[
        'id'      => 'main',
        'origin'  => ['c' => 0, 'r' => 0],
        'gravity' => 'down',
        'layers'  => ['terrain' => array_merge(['SSSSSSSSS'], array_fill(0, 8, 'ooooooooo')), 'entity' => [], 'overlay' => []],
        'spawn'   => ['mode' => 'top', 'weights' => [20, 20, 20, 20, 20, 15]],
    ]],
    'rules'       => ['mode' => 'moves', 'moves' => 24, 'starScores' => [1, 2, 3], 'goals' => [['type' => 'clear', 'layer' => 'jelly', 'count' => 15]]],
    'palette'     => ['gems' => ['red_gem', 'blue_gem'], 'theme' => 'orchard'],
    'seed_policy' => 'server',
    'fixed_seed'  => null,
];

$schema = LevelSchemaFactory::fromRow($row);
check('fromRow 输出 schema 标识', ($schema['schema'] ?? '') === 'match3/level@1');
check('fromRow 输出 board', ($schema['board']['cols'] ?? 0) === 9 && ($schema['board']['rows'] ?? 0) === 9);
check('fromRow 保留 palette', ($schema['palette']['theme'] ?? '') === 'orchard');
check('fromRow 缺省 minMatch=3', ($schema['rules']['minMatch'] ?? 0) === 3);
check('fromRow 缺省 autoShuffle=true', ($schema['rules']['autoShuffle'] ?? false) === true);
check('fromRow random.seedPolicy', ($schema['random']['seedPolicy'] ?? '') === 'server');

$back = LevelSchemaFactory::toRow($schema, [
    'code'        => $row['code'],
    'name'        => $row['name'],
    'chapter'     => $row['chapter'],
    'difficulty'  => $row['difficulty'],
    'description' => null,
]);
check('toRow 回填 board_cols', ($back['board_cols'] ?? 0) === 9);
check('toRow 回填 board_rows', ($back['board_rows'] ?? 0) === 9);
check('toRow 回填 cell_size', ($back['cell_size'] ?? 0) === 76);
check('toRow 回填 palette', ($back['palette']['gems'] ?? []) === ['red_gem', 'blue_gem']);
check('toRow 空可选字段置 null', array_key_exists('portals', $back) && $back['portals'] === null);

$again = LevelSchemaFactory::fromRow($back);
check('往返幂等（指纹一致）', LevelSchemaFactory::fingerprint($schema) === LevelSchemaFactory::fingerprint($again));

// ======================================================================
section('7. LevelSchemaFactory —— 元素池与可站立掩码');
// ======================================================================

$schema = LevelSchemaFactory::fromRow($row);
$pool   = LevelSchemaFactory::elementPool($schema);
check('元素池条数 = 权重>0 的颜色数', count($pool) === 2, json_encode($pool, JSON_UNESCAPED_UNICODE));
check('元素池元素码来自 palette.gems', ($pool[0]['element'] ?? '') === 'red_gem');

// 可站立掩码由唯一权威实现 Match3Board::buildState() 产出（LevelSchemaFactory::playableMask 已删除，
// 避免与 Match3Board 内联的同名逻辑形成双实现）
$mask = Match3Board::buildState($schema, 1)['playable'];
check('生成器行不可站立', $mask[0] === array_fill(0, 9, false));
check('可玩格可站立', $mask[1][0] === true);

$holed = $schema;
$holed['regions'][0]['layers']['terrain'][5] = 'oo.oooooo';
$mask2 = Match3Board::buildState($holed, 1)['playable'];
check('空洞(.)不可站立', $mask2[5][2] === false && $mask2[5][3] === true);

// ======================================================================
section('8. StableStringify');
// ======================================================================

$a = ['b' => 1, 'a' => ['y' => 2, 'x' => 3]];
$b = ['a' => ['x' => 3, 'y' => 2], 'b' => 1];
check('key 顺序无关', StableStringify::run($a) === StableStringify::run($b));
check('指纹长度 8', strlen(StableStringify::fingerprint($a)) === 8);
check('整数浮点输出整数形式', StableStringify::run(2.0) === '2');
check('中文不转义', StableStringify::run('冰') === '"冰"');
check('同输入指纹稳定', StableStringify::fingerprint(standardLevel()) === StableStringify::fingerprint(standardLevel()));

// ======================================================================
section('9. 种子关卡（Match3StageSeeder）全部通过校验');
// ======================================================================

require __DIR__ . '/stub/Seeder.php';
require __DIR__ . '/../database/seeds/Match3StageSeeder.php';

$seeder = new Match3StageSeeder();
$build  = new ReflectionMethod(Match3StageSeeder::class, 'buildLevels');
$build->setAccessible(true);
$levels = $build->invoke($seeder);

check('种子关卡数量 = 12', count($levels) === 12, '实际 ' . count($levels));

$codes = array_column($levels, 'code');
check('关卡编码唯一', count($codes) === count(array_unique($codes)));

$firstFailure = '';
foreach ($levels as $l) {
    $row = $l;
    $row['regions']    = is_array($l['regions']) ? $l['regions'] : json_decode($l['regions'], true);
    $row['rules']      = is_array($l['rules']) ? $l['rules'] : json_decode($l['rules'], true);
    $row['palette']    = is_array($l['palette']) ? $l['palette'] : json_decode($l['palette'], true);

    $schema = LevelSchemaFactory::fromRow($row);
    $errors = LevelValidator::validate($schema);
    if ($errors !== [] && $firstFailure === '') {
        $firstFailure = $l['code'] . ': ' . implode('; ', $errors);
    }
}
check('12 关全部通过 LevelValidator', $firstFailure === '', $firstFailure);

// ======================================================================
echo "\n----------------------------------------\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
