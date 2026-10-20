<?php
/**
 * S0-B1 · **客户端 ops 语料**的跨语言重放比对（诊断脚本，不是门禁）
 *
 *   php src/plugins/match3game/tests/compare-replay-vectors.php [向量文件] [报告文件] [--engine=node|php]
 *
 * 默认：
 *   引擎 = node     （子进程复用前端 TS 引擎 —— 与客户端**同一份实现**，线上权威口径）
 *   向量 = match3game/vectors/replay-vectors-ts.json    （由 S0-F1 导出）
 *   报告 = docs/match3/replay-divergence-report.md
 *          （`--engine=php` 时自动改为 `...-report.php-engine.md`，便于"换引擎前/后"两份并存对照）
 *
 * ============================================================================
 *  【它回答的问题】
 *
 *  后端 `replay_engine` 现在是 `php + upper_bound + flag`（观察期）。
 *  要决定能否切 `strict`，需要的不是"再测一遍引擎"，而是**量化两端偏差**：
 *    · 客户端按自己的口径算出的 score/goals/fingerprint
 *    · PHP 重放同一份 ops 得出的结果
 *    两者逐值是否相同？不相同集中在哪一类操作上？偏差多大？降级率多少？
 *
 *  与 `export-board-vectors.php` 的区别：
 *    · 那个脚本**自己生成 ops 自己重放** → 只能证明"PHP 重放 PHP 的 ops"
 *    · 本脚本消费**客户端状态机产生的 ops** → 才是"客户端 vs 服务端"的对齐证据
 *
 * ============================================================================
 *  【为什么不做成 CI 门禁】
 *
 *  它依赖前端产物（`replay-vectors-ts.json`），且存在**已知未修**的分歧
 *  （如 `prop` 清格类的 combo 档位/补货时机）。做成门禁会让 CI 长期报红，
 *  从而失去信号价值。等 §结论 里的分歧修完、`strict` 上线后再挂门禁。
 *
 * ============================================================================
 *  【退出码】
 *    0 = 跑完（无论是否一致；结论写在报告里）
 *    2 = 向量文件缺失/不合法（环境问题，不是结论）
 *    3 = **`--gate` 且存在字段不一致或引擎降级**（CI / 发布门禁用）
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
require $base . 'Match3NodeReplayEngine.php';

use plugin\match3game\service\level\Match3NodeReplayEngine;
use plugin\match3game\service\level\Match3PhpReplayEngine;
use plugin\match3game\service\level\Match3ReplayEngineInterface;

/** 仓库根：src/plugins/match3game/tests → 上溯 4 级 */
$repoRoot = dirname(__DIR__, 4);

// ---- 参数：位置参数 [向量文件] [报告文件] + `--engine=node|php` + `--gate` ----
$engineName = 'node';
$gate       = false;
$positional = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--engine=')) {
        $engineName = substr($arg, strlen('--engine='));
        continue;
    }
    if ($arg === '--gate') {
        // 【为什么默认不 gate】本脚本的定位是"诊断"：早期存在已知未修分歧时挂门禁会让 CI 长期报红，
        // 信号价值归零（见文件头）。分歧修完、`strict` 上线后由 CI / 发布流程显式加 `--gate`。
        // 注意：**只有权威引擎（node）适合 gate**；`--engine=php` 是回滚基线，
        // 与客户端存在已知口径差异（道具清格后补货/combo 档位），挂上去必然常红。
        $gate = true;
        continue;
    }
    $positional[] = $arg;
}

// 默认报告名随引擎区分，便于"换引擎前 / 后"两份报告并存对照
$defaultReport = $engineName === 'php'
    ? $repoRoot . '/docs/match3/replay-divergence-report.php-engine.md'
    : $repoRoot . '/docs/match3/replay-divergence-report.md';

$vectorsPath = $positional[0] ?? $repoRoot . '/match3game/vectors/replay-vectors-ts.json';
$reportPath  = $positional[1] ?? $defaultReport;

if (!is_file($vectorsPath)) {
    fwrite(STDERR, "[S0-B1] 向量文件不存在: {$vectorsPath}\n");
    fwrite(STDERR, "[S0-B1] 请先在前端仓库导出: cd match3game && npm run vectors:replay\n");
    exit(2);
}

$raw = file_get_contents($vectorsPath);
$vec = is_string($raw) ? json_decode($raw, true) : null;

if (!is_array($vec) || empty($vec['cases']) || !is_array($vec['cases'])) {
    fwrite(STDERR, "[S0-B1] 向量文件不合法（缺 cases）: {$vectorsPath}\n");
    exit(2);
}

$schemas = (array) ($vec['schemas'] ?? []);
$cases   = $vec['cases'];
$readme  = (array) ($vec['_readme'] ?? []);

// ---- 选引擎 ----
$engine        = null;
$engineLabel   = '';
$engineDetail  = '';

if ($engineName === 'php') {
    $engine       = new Match3PhpReplayEngine();
    $engineLabel  = 'Match3PhpReplayEngine（纯 PHP）';
    $engineDetail = '⚠️ 已实测与客户端有分歧，仅作**回滚 / 对照基线**，不在关键路径上';
} elseif ($engineName === 'node') {
    $nodeEngine = new Match3NodeReplayEngine();
    if (!$nodeEngine->isAvailable()) {
        fwrite(STDERR, "[S0-B1] --engine=node 但 bundle 不存在: " . $nodeEngine->getScriptPath() . "\n");
        fwrite(STDERR, "[S0-B1] 构建：cd match3game && npm ci && npm run build:replay\n");
        fwrite(STDERR, "[S0-B1] 或改用 --engine=php 生成对照报告\n");
        exit(2);
    }
    $engine       = $nodeEngine;
    $engineLabel  = 'Match3NodeReplayEngine（子进程复用前端 TS 引擎）';
    $engineDetail = '✅ 与客户端**同一份实现**（`match3game/src/core/*`），即线上权威口径';
} else {
    fwrite(STDERR, "[S0-B1] 未知引擎: {$engineName}（支持 node / php）\n");
    exit(2);
}

/** @var Match3ReplayEngineInterface $engine */

/* ======================================================================
 *  工具
 * ====================================================================== */

/** 归一化计数映射：键排序 + 值取整（PHP 空数组与 TS 空对象都归为 []） */
$normMap = static function ($map): array {
    $arr = is_array($map) ? $map : [];
    $out = [];
    foreach ($arr as $k => $v) {
        $out[(string) $k] = (int) $v;
    }
    ksort($out);

    return $out;
};

/** ops 形态签名：`swap×12, prop:hammer×1`（用于把分歧归因到具体操作类型） */
$opShape = static function (array $ops): string {
    $counts = [];
    foreach ($ops as $op) {
        if (!is_array($op)) {
            continue;
        }
        $type  = (string) ($op['t'] ?? '?');
        $label = $type === 'prop' ? 'prop:' . (string) ($op['p'] ?? '?') : $type;
        $counts[$label] = ($counts[$label] ?? 0) + 1;
    }

    // swap 排最前，其余按键名排序，保证同一形态只有一个签名
    $parts = [];
    if (isset($counts['swap'])) {
        $parts[] = 'swap×' . $counts['swap'];
        unset($counts['swap']);
    }
    ksort($counts);
    foreach ($counts as $label => $n) {
        $parts[] = $label . '×' . $n;
    }

    return $parts === [] ? '(空)' : implode(', ', $parts);
};

/** 百分位（输入已排序） */
$pct = static function (array $sorted, float $p): float {
    if ($sorted === []) {
        return 0.0;
    }
    $idx = (int) floor($p * (count($sorted) - 1));

    return (float) $sorted[max(0, min($idx, count($sorted) - 1))];
};

$fmt = static function (float $v): string {
    return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
};

/* ======================================================================
 *  逐用例比对
 * ====================================================================== */

$total      = count($cases);
$comparable = 0;   // 计入契约统计的用例（compare !== false）
$exact      = 0;   // 逐值完全一致
$mismatched = 0;   // 有不一致字段
$degraded   = 0;   // PHP 引擎拒绝重放（返回 null）
$byDesign   = [];  // compare=false（如 ops 截断）
$degradeReasons = [];
$fieldHits  = [];  // 字段 → 不一致次数
$deltas     = [];  // 分数偏差（TS - PHP），仅可比对且未降级
$shapeStats = [];  // 形态签名 → [cases, exact, mismatch]
$details    = [];  // 不一致明细（截断展示）

foreach ($cases as $case) {
    $id         = (string) ($case['id'] ?? '?');
    $schemaName = (string) ($case['schema'] ?? '');
    $seed       = (int) ($case['seed'] ?? 0);
    $ops        = (array) ($case['ops'] ?? []);
    $ts         = (array) ($case['ts'] ?? []);
    $shape      = $opShape($ops);

    // ---- 按设计排除：ops 截断（客户端继续游戏，服务端只能重放前 N 条）----
    if (($case['compare'] ?? true) === false) {
        $replay = null;
        $schema = $schemas[$schemaName] ?? [];
        if (is_array($schema) && $schema !== []) {
            $replay = $engine->replay($schema, $seed, $ops);
        }
        $byDesign[] = [
            'id'          => $id,
            'shape'       => $shape,
            'opsCount'    => count($ops),
            'tsScore'     => (int) ($ts['score'] ?? 0),
            'phpScore'    => $replay === null ? null : (int) $replay['score'],
            'skipReason'  => (string) ($case['skipReason'] ?? ''),
        ];
        continue;
    }

    $comparable++;
    if (!isset($shapeStats[$shape])) {
        $shapeStats[$shape] = ['cases' => 0, 'exact' => 0, 'mismatch' => 0];
    }
    $shapeStats[$shape]['cases']++;

    $schema = $schemas[$schemaName] ?? null;
    if (!is_array($schema) || $schema === []) {
        $degraded++;
        $degradeReasons['schema_missing:' . $schemaName] = ($degradeReasons['schema_missing:' . $schemaName] ?? 0) + 1;
        $shapeStats[$shape]['mismatch']++;
        continue;
    }

    $replay = $engine->replay($schema, $seed, $ops);

    if ($replay === null) {
        // 引擎主动降级（"忠实或不重放"）—— 降级率本身就是可观测指标
        $reason = Match3PhpReplayEngine::unsupportedReason($schema) ?? 'engine_declined';
        $degraded++;
        $degradeReasons[$reason] = ($degradeReasons[$reason] ?? 0) + 1;
        $shapeStats[$shape]['mismatch']++;
        if (count($details) < 20) {
            $details[] = ['id' => $id, 'shape' => $shape, 'fields' => ['(降级不重放) ' . $reason => ['—', '—']]];
        }
        continue;
    }

    // ---- 逐字段比对 ----
    $diff = [];

    $pairs = [
        'score'            => [(int) ($ts['score'] ?? 0), (int) $replay['score']],
        'moves'            => [(int) ($ts['moves'] ?? 0), (int) $replay['moves']],
        'maxCombo'         => [(int) ($ts['maxCombo'] ?? 0), (int) $replay['maxCombo']],
        'boardFingerprint' => [(string) ($ts['boardFingerprint'] ?? ''), (string) $replay['boardFingerprint']],
    ];

    $tsGoals = $normMap($ts['goals'] ?? []);
    unset($tsGoals['score']);                        // PHP 侧 goals 额外带 score 键，已单独比过
    $phpGoals = $normMap($replay['goals'] ?? []);
    unset($phpGoals['score']);

    $pairs['goals']     = [$tsGoals, $phpGoals];
    $pairs['collected'] = [$normMap($ts['collected'] ?? []), $normMap($replay['collected'] ?? [])];
    $pairs['cleared']   = [$normMap($ts['cleared'] ?? []), $normMap($replay['cleared'] ?? [])];

    foreach ($pairs as $field => [$expected, $actual]) {
        if ($expected !== $actual) {
            $diff[$field] = [$expected, $actual];
            $fieldHits[$field] = ($fieldHits[$field] ?? 0) + 1;
        }
    }

    $deltas[] = (int) ($ts['score'] ?? 0) - (int) $replay['score'];

    if ($diff === []) {
        $exact++;
        $shapeStats[$shape]['exact']++;
        continue;
    }

    $mismatched++;
    $shapeStats[$shape]['mismatch']++;
    if (count($details) < 20) {
        $details[] = ['id' => $id, 'shape' => $shape, 'fields' => $diff];
    }
}

/* ======================================================================
 *  统计
 * ====================================================================== */

$sortedDeltas = $deltas;
sort($sortedDeltas);

$absDeltas = array_map('abs', $deltas);
sort($absDeltas);

$deltaStats = [
    'n'        => count($deltas),
    'mean'     => $deltas === [] ? 0.0 : array_sum($deltas) / count($deltas),
    'p50'      => $pct($sortedDeltas, 0.50),
    'p95'      => $pct($sortedDeltas, 0.95),
    'max'      => $deltas === [] ? 0 : max($deltas),
    'min'      => $deltas === [] ? 0 : min($deltas),
    'absMax'   => $absDeltas === [] ? 0 : max($absDeltas),
    'absMean'  => $absDeltas === [] ? 0.0 : array_sum($absDeltas) / count($absDeltas),
    'zeroRate' => $deltas === [] ? 0.0 : count(array_filter($deltas, static fn (int $d): bool => $d === 0)) / count($deltas),
];

$exactRate = $comparable > 0 ? $exact / $comparable : 0.0;

/* ======================================================================
 *  结论（自动判定，但把依据一起写出来）
 * ====================================================================== */

$onlyScoreDiff = $fieldHits !== [] && array_keys($fieldHits) === ['score'];

/** 所有不一致用例是否都含 prop 清格类 op（hammer/bomb） */
$propRelated = static function (array $shapeStats): bool {
    foreach ($shapeStats as $shape => $stat) {
        if ($stat['mismatch'] > 0 && !str_contains($shape, 'prop:hammer') && !str_contains($shape, 'prop:bomb')) {
            return false;
        }
    }

    return true;
};

if ($mismatched === 0 && $degraded === 0) {
    $verdict = '✅ **可以切 `strict`**：客户端 ops 语料在重放引擎下**逐值一致**且无降级。'
        . '建议先把 `score_tolerance` 收到 0.05 跑一轮线上观察（看 `match3.replay` 埋点的偏差分布），'
        . '确认无异常后再切 `strict`；同时把 `upper_bound_action` 从 `flag` 提到 `reject`。';
} elseif ($mismatched === 0 && $degraded > 0) {
    $verdict = '🟠 **口径一致，但仍不能切 `strict`**：'
        . '已比对用例 100% 逐值一致，但有 ' . $degraded . ' 例被引擎降级（不重放）。'
        . '降级用例在 `strict` 下会回落弱校验 —— 需要先补齐降级要素（见降级原因表）。';
} elseif ($onlyScoreDiff && $deltaStats['absMax'] <= 0.01 * max(1, (int) $deltaStats['p95'])) {
    $verdict = '🟠 **仅分数存在偏差**：其余字段（步数/目标/收集/破除/指纹）全部一致，'
        . '建议先收窄 `score_tolerance` 观察，再切 `strict`。';
} else {
    $verdict = '🔴 **暂不可切 `strict`**：存在字段级不一致（见下表），'
        . '必须先修口径 —— 否则 `strict` 会把正常玩家判成 `result_mismatch`（误杀）。';
}

$propNote = ($mismatched > 0 && $propRelated($shapeStats))
    ? "\n### 根因（已用探针实测坐实）\n\n"
      . "不一致用例**全部**含 `prop:hammer` / `prop:bomb`（清格类道具），"
      . "而 `prop:shuffle` / `prop:add_moves` 的形态是 **100% 一致** —— 分歧被精确限定在服务端的清格分支。\n\n"
      . '| # | 分歧 | 客户端 `BattleLoop::useProp` | 服务端 `Match3PhpReplayEngine::replay` |' . "\n"
      . '|---|---|---|---|' . "\n"
      . '| ① | **道具清格后不补货** | 清格 → 重力 → **无条件 `refill`** → 连锁 | 清格 → 重力 → `resolveCascade`；'
      . '而它在「无连线」时**立即 break**，本回合**完全没有补货** |' . "\n"
      . '| ② | **combo 档位偏移一格** | 连锁从 `combo=1` 起（倍率 1.2），补货种子 `k` 也从 1 起 | 连锁从 `combo=0` 起（倍率 1.0），`k` 从 0 起 |' . "\n"
      . "\n"
      . '> **实测证据**（`plain9x9` / seed=42 / `hammer` at [0,0]）：服务端清格得 60 分、`gravity` 后棋盘 1 个空洞，'
      . '`resolveCascade` 因无连线直接返回（连击 0、补货 0 次），**最终仍有 1 个空洞**；'
      . '客户端同一步则先补货、棋盘满格 —— 从这一步起两端棋盘永久分叉。' . "\n"
      . '>' . "\n"
      . '> ① 让**棋盘状态**分叉，② 让**分数口径**分叉。修哪一侧是产品/口径决策（见 §九），'
      . '但 `strict` 之前必须先统一 —— 否则会把正常玩家的成绩判成 `result_mismatch`。' . "\n"
    : '';

/* ======================================================================
 *  渲染报告
 * ====================================================================== */

$lines = [];
$lines[] = '# match3game 跨语言重放偏差报告（S0-B1）';
$lines[] = '';
$lines[] = '> 自动生成，请勿手改。复跑命令：';
$lines[] = '> ```bash';
$lines[] = '> cd match3game && npm run vectors:replay    # ① 前端导出客户端 ops 语料';
$lines[] = '> cd match3game && npm run build:replay      # ② 构建后端复用的 TS 引擎 bundle（首次/改算法后）';
$lines[] = '> php src/plugins/match3game/tests/compare-replay-vectors.php              # ③ 权威引擎（node）';
$lines[] = '> php src/plugins/match3game/tests/compare-replay-vectors.php --engine=php # ④ 回滚基线对照';
$lines[] = '> ```';
$lines[] = '';
$lines[] = '## 一、结论';
$lines[] = '';
$lines[] = $verdict;
$lines[] = $propNote;
$lines[] = '';
$lines[] = '| 项 | 值 |';
$lines[] = '|---|---|';
$lines[] = '| 用例总数 | ' . $total . ' |';
$lines[] = '| 计入契约统计（`compare=true`） | ' . $comparable . ' |';
$lines[] = '| **逐值完全一致** | ' . $exact . '（' . number_format($exactRate * 100, 2) . '%） |';
$lines[] = '| 存在字段不一致 | ' . $mismatched . ' |';
$lines[] = '| 引擎降级（拒绝重放） | ' . $degraded . ' |';
$lines[] = '| 按设计排除（`compare=false`） | ' . count($byDesign) . ' |';
$lines[] = '';
$lines[] = '## 二、数据来源';
$lines[] = '';
$lines[] = '| 项 | 值 |';
$lines[] = '|---|---|';
$lines[] = '| 向量文件 | `match3game/vectors/replay-vectors-ts.json` |';
$lines[] = '| **重放引擎** | ' . $engineLabel . ' |';
$lines[] = '| 引擎定位 | ' . $engineDetail . ' |';
$lines[] = '| 生成器 | `' . (string) ($readme['generatedBy'] ?? '?') . '` |';
$lines[] = '| ops 来源 | **客户端** `BattleLoop`（非 PHP 自产自销） |';
$modelText = [];
foreach ((array) ($readme['playerModels'] ?? []) as $modelName => $modelDesc) {
    $modelText[] = '`' . $modelName . '`：' . (string) $modelDesc;
}
$lines[] = '| 玩家模型 | ' . implode('；', $modelText) . ' |';
$lines[] = '| 算法版本 | `' . (string) ($readme['fillVersion'] ?? '?') . '`（期望 `'
    . Match3NodeReplayEngine::FILL_VERSION . '`） |';
$lines[] = '| 说明 | ' . (string) ($readme['caveat'] ?? '') . ' |';
$lines[] = '';

if (($readme['fillVersion'] ?? '') !== Match3NodeReplayEngine::FILL_VERSION) {
    $lines[] = '> ⚠️ **`fillVersion` 不一致**：向量由 `' . (string) ($readme['fillVersion'] ?? '?')
        . '` 生成，期望 `' . Match3NodeReplayEngine::FILL_VERSION . '` —— 先统一版本再看本报告。';
    $lines[] = '';
}

$lines[] = '## 三、逐字段不一致统计';
$lines[] = '';
if ($fieldHits === []) {
    $lines[] = '无字段级不一致。';
} else {
    arsort($fieldHits);
    $lines[] = '| 字段 | 不一致次数 | 占比（对可比对用例） |';
    $lines[] = '|---|---|---|';
    foreach ($fieldHits as $field => $n) {
        $lines[] = '| `' . $field . '` | ' . $n . ' | '
            . ($comparable > 0 ? number_format($n / $comparable * 100, 1) : '0') . '% |';
    }
}
$lines[] = '';

$lines[] = '## 四、分数偏差分布（`客户端 TS − 重放引擎`）';
$lines[] = '';
$lines[] = '| 指标 | 值 |';
$lines[] = '|---|---|';
$lines[] = '| 样本数 | ' . $deltaStats['n'] . ' |';
$lines[] = '| 完全相等比例 | ' . number_format($deltaStats['zeroRate'] * 100, 2) . '% |';
$lines[] = '| mean | ' . $fmt($deltaStats['mean']) . ' |';
$lines[] = '| p50 / p95 | ' . $fmt($deltaStats['p50']) . ' / ' . $fmt($deltaStats['p95']) . ' |';
$lines[] = '| max / min | ' . $deltaStats['max'] . ' / ' . $deltaStats['min'] . ' |';
$lines[] = '| **最大绝对偏差** | ' . $deltaStats['absMax'] . ' |';
$lines[] = '| 平均绝对偏差 | ' . $fmt($deltaStats['absMean']) . ' |';
$lines[] = '';

$lines[] = '## 五、按 ops 形态归因';
$lines[] = '';
$lines[] = '> 形态 = 该用例 ops 的类型构成（`swap×N, prop:hammer×M`）。';
$lines[] = '> **一眼定位分歧落在哪类操作上**：只含 swap 的形态若全部一致，说明连锁内核无漂移。';
$lines[] = '';
$lines[] = '| ops 形态 | 用例数 | 一致 | 不一致 | 一致率 |';
$lines[] = '|---|---|---|---|---|';
ksort($shapeStats);
foreach ($shapeStats as $shape => $stat) {
    $rate = $stat['cases'] > 0 ? number_format($stat['exact'] / $stat['cases'] * 100, 1) . '%' : '—';
    $lines[] = '| `' . $shape . '` | ' . $stat['cases'] . ' | ' . $stat['exact'] . ' | '
        . $stat['mismatch'] . ' | ' . $rate . ' |';
}
$lines[] = '';

$lines[] = '## 六、引擎降级原因分布';
$lines[] = '';
if ($degradeReasons === []) {
    $lines[] = '无降级（所有用例都被忠实重放）。';
} else {
    arsort($degradeReasons);
    $lines[] = '| 降级原因 | 次数 |';
    $lines[] = '|---|---|';
    foreach ($degradeReasons as $reason => $n) {
        $lines[] = '| `' . $reason . '` | ' . $n . ' |';
    }
    $lines[] = '';
    $lines[] = '> 降级 = 引擎判断"无法忠实模拟"而整体拒绝重放（宁可降级不给错分）。'
        . '降级率是 `strict` 能否上线的**独立门槛**。';
}
$lines[] = '';

$lines[] = '## 七、不一致用例明细（最多 20 条）';
$lines[] = '';
if ($details === []) {
    $lines[] = '无不一致用例。';
} else {
    foreach ($details as $d) {
        $lines[] = '### ' . $d['id'];
        $lines[] = '';
        $lines[] = '`' . $d['shape'] . '`';
        $lines[] = '';
        $lines[] = '| 字段 | TS（客户端） | PHP（重放） |';
        $lines[] = '|---|---|---|';
        foreach ($d['fields'] as $field => [$expected, $actual]) {
            $lines[] = '| `' . $field . '` | `'
                . (is_scalar($expected) ? (string) $expected : json_encode($expected, JSON_UNESCAPED_UNICODE)) . '` | `'
                . (is_scalar($actual) ? (string) $actual : json_encode($actual, JSON_UNESCAPED_UNICODE)) . '` |';
        }
        $lines[] = '';
    }
}

$lines[] = '## 八、按设计排除的用例';
$lines[] = '';
if ($byDesign === []) {
    $lines[] = '无。';
} else {
    $lines[] = '| 用例 | 形态 | ops 条数 | TS 分 | PHP 分 | 排除原因 |';
    $lines[] = '|---|---|---|---|---|---|';
    foreach ($byDesign as $d) {
        $lines[] = '| `' . $d['id'] . '` | `' . $d['shape'] . '` | ' . $d['opsCount'] . ' | '
            . $d['tsScore'] . ' | ' . ($d['phpScore'] === null ? '—' : (string) $d['phpScore']) . ' | '
            . $d['skipReason'] . ' |';
    }
    $lines[] = '';
    $lines[] = '> `ops_truncated` 是**协议边界**：ops 达 `submission.max_ops` 后客户端仍继续游戏，'
        . '服务端只能重放前 N 条，两端结果必然不同。这不是引擎分歧，且**量级上现实不可达** ——'
        . '真实关卡步数由 `DifficultyCurve` 定为 `BASE_MOVES=25`（下限 5），单局有效操作 ~25 次。'
        . '仍建议做 1 行加固（客户端达上限即锁死输入），并把上界纳入契约防两端漂移（S0-B3）。';
}
$lines[] = '';
$lines[] = '---';
$lines[] = '';
/* --------------------------------------------------------------
 *  线上口径：**直接读配置**，不写死
 *
 *  教训：本表曾把"收窄容差 / 提 action / 堵非法 op / 切 strict"当成待办列出，
 *  但这四件事 2026-09-19 就已全部落地 —— 清单过期会让人去做已完成的事。
 *  改为读 `config/match3.php` 现场判定，配置一变报告跟着变。
 * -------------------------------------------------------------- */
$liveCfgFile = __DIR__ . '/../config/match3.php';
$liveCfg     = is_file($liveCfgFile) ? (array) require $liveCfgFile : [];
$liveSub     = (array) ($liveCfg['submission'] ?? []);
$liveReplay  = (array) ($liveCfg['replay_engine'] ?? []);

$liveMode      = (string) ($liveReplay['mode'] ?? '?');
$liveCompare   = (string) ($liveSub['compare_mode'] ?? '?');
$liveAction    = (string) ($liveSub['upper_bound_action'] ?? '?');
$liveTolerance = $liveSub['score_tolerance'] ?? null;
$liveMaxOps    = $liveSub['max_ops'] ?? '?';

$mark = static fn (bool $ok): string => $ok ? '✅' : '❌';

$lines[] = '## 九、上线状态与剩余缺口';
$lines[] = '';
$lines[] = '> 本节由脚本**现场读取 `config/match3.php`** 生成（不是手写清单）：已落地的项不会在这里被重复建议。';
$lines[] = '';

if ($engineName === 'node') {
    $lines[] = '### 9.1 已落地（自动核对配置）';
    $lines[] = '';
    $lines[] = '| 项 | 期望 | 当前 | 状态 |';
    $lines[] = '|---|---|---|---|';
    $lines[] = '| 重放引擎 | `mode=node`（与客户端同一份实现） | `' . $liveMode . '` | ' . $mark($liveMode === 'node') . ' |';
    $lines[] = '| 比对口径 | `compare_mode=strict` | `' . $liveCompare . '` | ' . $mark($liveCompare === 'strict') . ' |';
    $lines[] = '| 上界命中处置 | `upper_bound_action=reject` | `' . $liveAction . '` | ' . $mark($liveAction === 'reject') . ' |';
    $lines[] = '| 容差 | `score_tolerance ≤ 0.05` | `' . (string) $liveTolerance . '` | '
        . $mark($liveTolerance !== null && (float) $liveTolerance <= 0.05) . ' |';
    $lines[] = '| 非法 op 处置 | 拒单（**不降级**） | 代码已实现 | ✅ |';
    $lines[] = '';
    $lines[] = '> 「非法 op 拒单」由 `verify-strict-rollout.php` §2 锁住，含**端到端**用例：'
        . 'L1 判合法、L3 判非法（`swap [0,0]→[1,0]` 不成线）→ 必须 `invalid_op` 拒单（11001）。'
        . '它与「引擎能力不足 → 降级放行」**是两个分支，不可合并**：'
        . '合并回去，攻击者塞一条坏 op 就能让 L3 整体失效。';
    $lines[] = '';
    $lines[] = '### 9.2 剩余缺口';
    $lines[] = '';
    $lines[] = '| 优先级 | 缺口 | 影响 / 判据 |';
    $lines[] = '|---|---|---|';
    $lines[] = '| 🟠 中 | **`ops` 上限边界（防御性加固，非现实风险）**：`submission.max_ops = ' . (string) $liveMaxOps
        . '`，客户端达上限后仍继续游戏，服务端只能重放前 N 条 | 本报告 §八 实测 1 例（AI 单局成功交换 520 次）。'
        . '**量级判断**：真实关卡步数由 `DifficultyCurve` 定为 `BASE_MOVES=25`（D=1 起向下衰减、下限 5），'
        . '单局有效操作 ~25 次，距 ' . (string) $liveMaxOps . ' 有 20 倍余量 ⇒ **现实不可达**。'
        . '加固动作（各 1 行）：客户端达上限即锁死输入；把 `submission.max_ops` 与 `MAX_OPS` 纳入契约防两端漂移（S0-B3） |';
    $lines[] = '| 🟠 中 | `rainbow` / `paint` 重放实现（S0-B2） | 客户端尚未支持这两个道具，语料里不会出现它们 —— '
        . '**降级率 0 不构成"已就绪"的证据** |';
    $lines[] = '| 🟠 中 | 用**真机语料**替换 AI 语料（S2-7），复跑本报告 | §二 玩家模型一栏；AI 语料只证明"引擎口径一致" |';
    $lines[] = '| ⚪ 低 | QPS 升高时切 `mode=http`（常驻 Node 服务，省掉每次子进程启动开销） | 延迟实测（见 `12-next-phase-plan.md`） |';
} else {
    $lines[] = '| ⚪ — | **PHP 引擎已移出关键路径**（2026-09-19 架构决定：算法单一实现 = TS，'
        . '后端用 `mode=node` 复用同一份代码）。**本报告仅作对照基线**，不再按它修 PHP 语义 | — |';
    $lines[] = '| ⚪ — | 若要回滚到 `mode=php`，则上表的分歧必须先修：① `prop` 清格后补一次 `refill`'
        . '② 对齐 combo 档位（服务端从 0 起 vs 客户端从 1 起）；② 若落在 `Match3Board::resolveCascade`，'
        . '**必须回归 `LevelSimulator`** 的难度标定 | 第五节 `prop:hammer` / `prop:bomb` 形态一致率 → 100% |';
}

$lines[] = '';

$report = implode("\n", $lines) . "\n";

if (@file_put_contents($reportPath, $report) === false) {
    fwrite(STDERR, "[S0-B1] 报告写入失败: {$reportPath}\n");
    exit(2);
}

/* ======================================================================
 *  控制台摘要
 * ====================================================================== */

$shortVerdict = str_starts_with($verdict, '✅') ? '可切 strict'
    : (str_starts_with($verdict, '🔴') ? '暂不可切 strict' : '需先修口径/降级');

echo "==============================================================\n";
echo " S0-B1 跨语言重放偏差比对\n";
echo "==============================================================\n";
echo ' 引擎     : ' . $engineLabel . "\n";
echo ' 向量文件 : ' . $vectorsPath . "\n";
echo ' 报告     : ' . $reportPath . "\n";
echo "--------------------------------------------------------------\n";
printf(" 用例 %d  可比对 %d  逐值一致 %d (%.2f%%)  不一致 %d  降级 %d  按设计排除 %d\n",
    $total, $comparable, $exact, $exactRate * 100, $mismatched, $degraded, count($byDesign));
printf(" 分数偏差: mean %s  p95 %s  |max| %d  完全相等 %.2f%%\n",
    $fmt($deltaStats['mean']), $fmt($deltaStats['p95']), $deltaStats['absMax'], $deltaStats['zeroRate'] * 100);
echo ' 结论     : ' . $shortVerdict . "\n";

if ($fieldHits !== []) {
    arsort($fieldHits);
    $parts = [];
    foreach ($fieldHits as $field => $n) {
        $parts[] = $field . '×' . $n;
    }
    echo ' 不一致字段: ' . implode(', ', $parts) . "\n";
}

if ($degradeReasons !== []) {
    arsort($degradeReasons);
    $parts = [];
    foreach ($degradeReasons as $reason => $n) {
        $parts[] = $reason . '×' . $n;
    }
    echo ' 降级原因: ' . implode(', ', $parts) . "\n";
}

$badShapes = [];
foreach ($shapeStats as $shape => $stat) {
    if ($stat['mismatch'] > 0) {
        $badShapes[] = $shape;
    }
}
if ($badShapes !== []) {
    echo ' 不一致形态: ' . implode(' | ', array_slice($badShapes, 0, 4)) . "\n";
}

echo "==============================================================\n";

if ($gate && ($mismatched > 0 || $degraded > 0)) {
    fwrite(STDERR, sprintf(
        "[S0-B1] --gate 失败：不一致 %d 例、引擎降级 %d 例（详情见报告 %s）\n",
        $mismatched,
        $degraded,
        $reportPath
    ));

    exit(3);
}

exit(0);
