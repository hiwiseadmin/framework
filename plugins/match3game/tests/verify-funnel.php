<?php
/**
 * 埋点漏斗自检（P6-B05）
 *
 *   php src/plugins/match3game/tests/verify-funnel.php
 *
 * 覆盖 `Match3Funnel::build()` / `sanitize()` —— 这两块是**纯函数**（无副作用），
 * 决定了埋点数据的形状与体积，必须锁死：
 *   • 埋点要长期存储与聚合，绝不能把 schema / ops 这种大对象写进去
 *   • 字段类型必须稳定（bool→1/0），否则下游聚合会分桶错乱
 *   • 事件名/键名必须截断，防止超长字段打爆存储
 *
 * `emit()` 只做日志 + 下游转发（有 class_exists 守卫 + try/catch），这里只做不抛异常的冒烟。
 */

declare(strict_types=1);

require __DIR__ . '/stub/ContextualLogger.php';
require __DIR__ . '/../contract/Match3Constants.php';
require __DIR__ . '/../service/analytics/Match3Funnel.php';

use plugin\match3game\contract\Match3Constants;
use plugin\match3game\service\analytics\Match3Funnel;

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

// ======================================================================
section('1. build() 事件结构');
// ======================================================================

$ev = Match3Funnel::build(Match3Funnel::NODE_PASS, 42, ['score' => 1234], 1700000000000);

check('返回 event / user_id / game_id / ts / props 五个键',
    array_keys($ev) === ['event', 'user_id', 'game_id', 'ts', 'props'],
    implode(',', array_keys($ev)));
check('event 原样保留', $ev['event'] === 'match3.node.pass');
check('user_id 保留', $ev['user_id'] === 42);
check('game_id = match3_game', $ev['game_id'] === Match3Constants::GAME_ID);
check('ts 可注入（便于回放/测试）', $ev['ts'] === 1700000000000);
check('props 原样保留', $ev['props'] === ['score' => 1234]);

$auto = Match3Funnel::build('x', 1, []);
check('ts 缺省取当前毫秒（>0）', $auto['ts'] > 0);

$trimmed = Match3Funnel::build('  ' . str_repeat('a', 80) . '  ', 1, []);
check('超长事件名截断到 48', strlen($trimmed['event']) === 48, (string) strlen($trimmed['event']));

// ======================================================================
section('2. sanitize() 类型归约（下游聚合稳定性）');
// ======================================================================

$sanitize = new ReflectionMethod(Match3Funnel::class, 'sanitize');
$sanitize->setAccessible(true);

$out = $sanitize->invoke(null, ['t' => true, 'f' => false, 'n' => null]);
check('bool true → 1', $out['t'] === 1);
check('bool false → 0', $out['f'] === 0);
check('null 保留为 null（可区分"未上报"与 0）', $out['n'] === null);

$out = $sanitize->invoke(null, ['i' => 7, 'f' => 1.23456789, 'neg' => -3]);
check('int 原样', $out['i'] === 7);
check('float 保留 4 位小数（避免浮点噪声）', $out['f'] === 1.2346, var_export($out['f'], true));
check('负数保留', $out['neg'] === -3);

$long = str_repeat('中', 200);
$out  = $sanitize->invoke(null, ['s' => $long]);
check('字符串按字符截断到 64（中文也按字符算）', mb_strlen($out['s']) === 64, (string) mb_strlen($out['s']));

$out = $sanitize->invoke(null, ['list' => ['a', 'b', 'c']]);
check('标量数组压成逗号分隔', $out['list'] === 'a,b,c', (string) $out['list']);

$out = $sanitize->invoke(null, ['list' => []]);
check('空数组 → 空串（不产生 null 歧义）', $out['list'] === '');

$out = $sanitize->invoke(null, ['big' => ['a' => ['b' => 1]]]);
check('嵌套数组不展开（只取标量元素）', $out['big'] === '', var_export($out['big'], true));

$out = $sanitize->invoke(null, ['obj' => new \stdClass()]);
check('对象只记类型，不记内容', $out['obj'] === '[stdClass]', (string) $out['obj']);

$out = $sanitize->invoke(null, ['longList' => range(1, 50)]);
check('数组元素上限 16（防止超大列表）', count(explode(',', (string) $out['longList'])) === 16);

// 键数量上限
$many = [];
for ($i = 0; $i < 50; $i++) {
    $many['k' . $i] = $i;
}
$out = $sanitize->invoke(null, $many);
check('属性键上限 24', count($out) === Match3Funnel::MAX_KEYS, (string) count($out));

$out = $sanitize->invoke(null, [str_repeat('k', 100) => 1]);
check('超长键截断到 40', strlen(array_key_first($out)) === 40);

$out = $sanitize->invoke(null, ['' => 1]);
check('空键被丢弃', $out === []);

// ======================================================================
section('3. replay() —— L3 可观测性');
// ======================================================================

$build = static function (array $props): array {
    return Match3Funnel::build(Match3Funnel::REPLAY, 1, $props);
};

$ev = $build(['mode' => 'upper_bound', 'replayed' => 0, 'suspicious' => 1, 'replayScore' => 100, 'claimScore' => 300]);
check('replay 事件 props 结构正确',
    $ev['props'] === ['mode' => 'upper_bound', 'replayed' => 0, 'suspicious' => 1, 'replayScore' => 100, 'claimScore' => 300],
    json_encode($ev['props'], JSON_UNESCAPED_UNICODE));

check('emit() 不抛异常（冒烟）', (function () {
    Match3Funnel::emit(Match3Funnel::LEVEL_START, 1, ['levelCode' => 'M3-0101']);
    Match3Funnel::emit(Match3Funnel::RUN_ABANDON, 1, ['layer' => 2, 'nodeIndex' => 3]);
    Match3Funnel::emit(Match3Funnel::RELIC_PICK, 1, ['relicId' => 'r_more_moves', 'reroll' => 0]);

    return true;
})());

check('replay() 不抛异常（冒烟，含 null 分数）', (function () {
    Match3Funnel::replay(1, 'degraded', false, false, null, null, ['levelCode' => 'M3-0101']);
    Match3Funnel::replay(1, 'upper_bound', false, true, 1000, 3000);
    Match3Funnel::replay(1, 'strict', true, false, 2000, 2000);

    return true;
})());

check('degraded 模式也能上报（降级率可观测）', (function () {
    $ev = Match3Funnel::build(Match3Funnel::REPLAY, 1, ['mode' => 'degraded', 'replayed' => 0, 'suspicious' => 0, 'replayScore' => null, 'claimScore' => 500]);

    return $ev['props']['mode'] === 'degraded' && $ev['props']['replayScore'] === null;
})());

// ======================================================================
section('4. 事件名常量完整性');
// ======================================================================

$events = [
    'LEVEL_START' => Match3Funnel::LEVEL_START,
    'LEVEL_PASS'  => Match3Funnel::LEVEL_PASS,
    'LEVEL_FAIL'  => Match3Funnel::LEVEL_FAIL,
    'RUN_START'   => Match3Funnel::RUN_START,
    'RUN_CLEAR'   => Match3Funnel::RUN_CLEAR,
    'RUN_LOSE'    => Match3Funnel::RUN_LOSE,
    'RUN_ABANDON' => Match3Funnel::RUN_ABANDON,
    'NODE_ENTER'  => Match3Funnel::NODE_ENTER,
    'NODE_PASS'   => Match3Funnel::NODE_PASS,
    'NODE_FAIL'   => Match3Funnel::NODE_FAIL,
    'RELIC_PICK'  => Match3Funnel::RELIC_PICK,
    'REPLAY'      => Match3Funnel::REPLAY,
    'INVITE'      => Match3Funnel::INVITE,
    'ATTRIBUTION' => Match3Funnel::ATTRIBUTION,
];

$allPrefixed = true;
$allUnique   = count(array_unique($events)) === count($events);
foreach ($events as $v) {
    if (strpos($v, 'match3.') !== 0) {
        $allPrefixed = false;
    }
}
check('14 个事件名全部 match3. 前缀（日志系统按前缀建索引）', $allPrefixed);
check('14 个事件名互不重复', $allUnique);

/* ======================================================================
 *  分歧率监控（S5-4）
 * ====================================================================== */

section('5. 分歧率统计 —— 按 clientVersion 聚合（S5-4）');

/**
 * 造一条"重放记录"（`Match3Funnel::replay()` 落日志时的 props 形状）
 */
function replayRecord(string $version, string $mode, bool $suspicious): array
{
    return [
        'event' => Match3Funnel::REPLAY,
        'props' => [
            'clientVersion' => $version,
            'mode'          => $mode,
            'replayed'      => $mode !== 'degraded' ? 1 : 0,
            'suspicious'    => $suspicious ? 1 : 0,
            'replayScore'   => 5000,
            'claimScore'    => 5000,
            'delta'         => 0,
        ],
    ];
}

$records = [
    replayRecord('1.0.0', 'strict',      false),
    replayRecord('1.0.0', 'strict',      false),
    replayRecord('1.0.0', 'degraded',    false), // ← 分歧 1
    replayRecord('1.0.1', 'strict',      true), // ← 分歧 2（上报分超上界）
    replayRecord('1.0.1', 'strict',      false),
];

$stats = Match3Funnel::divergence($records);

check('按 clientVersion 分桶', array_keys($stats) === ['1.0.0', '1.0.1']);
check('total 计数正确', $stats['1.0.0']['total'] === 3 && $stats['1.0.1']['total'] === 2);
check('mismatch 计数正确', $stats['1.0.0']['mismatch'] === 1 && $stats['1.0.1']['mismatch'] === 1);
check('rate 计算正确', abs(($stats['1.0.0']['rate'] ?? 0) - 1 / 3) < 1e-9);
check('33% 分歧率 → 超过 5% 阈值告警', ($stats['1.0.0']['alert'] ?? false) === true);

$lenient = Match3Funnel::divergence($records, 0.5);
check('33% 分歧率 → 未超过 50% 阈值不告警', ($lenient['1.0.0']['alert'] ?? true) === false);
check('超 50% 阈值触发告警', ($stats['1.0.1']['alert'] ?? false) === true);

// 空输入 → 空结果（监控侧不必特判）
check('空记录返回空数组', Match3Funnel::divergence([]) === []);

/* ======================================================================
 *  6. 兜底与细分（S5-4 补强：分桶维度 + checkpoint 分歧 + 强更名单）
 * ====================================================================== */

section('6. 分桶兜底 / checkpoint 分歧 / 强更名单（S5-4）');

// ① 客户端未上报包版本 → 回落算法版本（否则全部落进 unknown，等于没分桶）
check(
    'clientVersion 缺失 → 回落 fillVersion',
    Match3Funnel::bucketOf(['fillVersion' => 'v7']) === 'fill:v7'
);
check(
    'clientVersion 优先于 fillVersion',
    Match3Funnel::bucketOf(['clientVersion' => '1.2.0', 'fillVersion' => 'v7']) === '1.2.0'
);
check('两者都缺 → unknown', Match3Funnel::bucketOf([]) === 'unknown');

// ② 分数对得上、但逐回合棋盘不一致 —— 也算分歧（这是 S5-3 存在的意义）
$cpOnly = [[
    'props' => [
        'clientVersion'      => '1.3.0',
        'mode'               => 'strict',
        'replayed'           => 1,
        'suspicious'         => 0,
        'checkpointMismatch' => 2,
    ],
]];
$cpStats = Match3Funnel::divergence($cpOnly);
check('仅 checkpoint 不一致 → 判为分歧', ($cpStats['1.3.0']['mismatch'] ?? 0) === 1);
check('成因细分记到 checkpoint', ($cpStats['1.3.0']['mismatchCheckpoint'] ?? 0) === 1);
check('成因细分：score / degraded 均为 0', ($cpStats['1.3.0']['mismatchScore'] ?? -1) === 0
    && ($cpStats['1.3.0']['mismatchDegraded'] ?? -1) === 0);

// 分数可疑优先归到 score（便于先查作弊/篡改）
$scoreOnly = [[
    'props' => ['clientVersion' => '1.3.0', 'mode' => 'strict', 'suspicious' => 1, 'checkpointMismatch' => 0],
]];
check(
    '分数超上界 → 归到 score 桶',
    (Match3Funnel::divergence($scoreOnly)['1.3.0']['mismatchScore'] ?? 0) === 1
);

$degradedOnly = [[
    'props' => ['clientVersion' => '1.3.0', 'mode' => 'degraded', 'suspicious' => 0],
]];
check(
    '引擎降级 → 归到 degraded 桶',
    (Match3Funnel::divergence($degradedOnly)['1.3.0']['mismatchDegraded'] ?? 0) === 1
);

// ③ 强更名单：**样本量门槛**（1/1 = 100% 不能据此强更）
$lots = [];
for ($i = 0; $i < 20; $i++) {
    $lots[] = ['props' => ['clientVersion' => '1.4.0', 'mode' => 'strict', 'suspicious' => $i < 5 ? 1 : 0]];
}
check('样本 20 条、25% 分歧 → 进强更名单', Match3Funnel::versionsToForceUpdate($lots) === ['1.4.0']);
check('样本不足（<20）→ 不进强更名单', Match3Funnel::versionsToForceUpdate(array_slice($lots, 0, 10)) === []);
check('分歧率低于阈值 → 不进强更名单', Match3Funnel::versionsToForceUpdate($lots, 0.5) === []);

echo "\n----------------------------------------\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
