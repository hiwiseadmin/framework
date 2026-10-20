<?php

declare(strict_types=1);

/**
 * 并发安全核对（S5-5 / B4）
 *
 *   php src/plugins/match3game/tests/verify-concurrency.php
 *
 * 【为什么是"静态核对 + 离线不变量"，而不是真的并发压测】
 *   真并发需要**真实数据库**（`match3_run_node` 的唯一索引、`match3_battle` 的行锁 CAS），
 *   而本套自检的设计前提是"零依赖、CI 秒级"（见 `run-all.php` 头注释）。
 *   所以这里锁的是**并发安全赖以成立的不变量**，把"真跑一遍"留给预发环境
 *   （脚本末尾打印可直接执行的冒烟步骤，与 `verify-cron.php` 打印 crontab 同一手法）。
 *
 * 【锁哪四类不变量】
 *   ① 关卡树     同 run 的 node id / 层内序号**唯一且确定性** → 两个节点并发 enter 不会撞同一个 id
 *   ② 数据库兜底  `uk_run_node`（run_id,node_id）、`uk_token`（battle_token）唯一索引存在
 *   ③ 结算幂等    `BattleService` 走 `lock(true)` + STATUS 条件 CAS + `settle_result` 快照
 *   ④ 引擎并发    node 引擎每次用 `tempnam` 独占临时文件（两个请求不会互相覆盖 stdin/stdout）
 *
 * 【退出码】0 = 全过；1 = 有失败项
 */

$levelBase = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require $levelBase . 'StableStringify.php';
require $levelBase . 'Match3Random.php';
require __DIR__ . '/../service/rogue/Match3MapGenerator.php';

use plugin\match3game\service\rogue\Match3MapGenerator;

$pluginRoot = dirname(__DIR__);

$passed  = 0;
$failed  = 0;
$skipped = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ✓ {$name}\n";
        return;
    }

    $failed++;
    echo "  ✗ {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function section(string $title): void
{
    echo "\n── {$title} ──\n";
}

/** 读源码文本（不存在返回空串） */
function srcOf(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

/* ======================================================================
 * 1. 关卡树：并发 enter 不串号的前提
 * ====================================================================== */
section('1. 关卡树：node id 唯一 + 确定性（同 run 并发取节点不会撞号）');

$map  = Match3MapGenerator::generate(88231, 3);
$ids  = array_map(static fn (array $n): string => (string) $n['id'], (array) ($map['nodes'] ?? []));
$keys = array_map(static fn (array $n): string => $n['layer'] . '#' . $n['index'], (array) ($map['nodes'] ?? []));

check('节点数 = 层数 × (4 + 1)', count($ids) === 15, (string) count($ids));
check('node id 全 run 唯一', count(array_unique($ids)) === count($ids));
check('层内 (layer,index) 唯一', count(array_unique($keys)) === count($keys));
check(
    '同 mapSeed 生成完全一致（并发两端拿到同一张图）',
    Match3MapGenerator::generate(88231, 3) === $map
);
check(
    '起点节点可并发进入且互不相同',
    count(array_unique((array) (Match3MapGenerator::availableNodeIds($map, []) ?? []))) >= 2
);

/* ======================================================================
 * 2. 数据库兜底：唯一索引（并发重复写入会被 DB 拦住，而不是静默串号）
 * ====================================================================== */
section('2. 数据库兜底：唯一索引护栏');

$runNodeMigration = $pluginRoot . '/database/migrations/20251028000008_create_match3_run_node_table.php';
$battleMigration  = $pluginRoot . '/database/migrations/20251028000005_create_match3_battle_table.php';

$runNodeSrc = srcOf($runNodeMigration);
$battleSrc  = srcOf($battleMigration);

check('run_node 迁移文件存在', $runNodeSrc !== '');
check(
    'run_node 有 uk_run_node(run_id,node_id) 唯一索引',
    str_contains($runNodeSrc, "'run_id'") && str_contains($runNodeSrc, 'unique') && str_contains($runNodeSrc, 'uk_run_node')
);
check('battle 迁移文件存在', $battleSrc !== '');
check(
    'battle 有 uk_token(battle_token) 唯一索引（结算幂等键）',
    str_contains($battleSrc, 'battle_token') && str_contains($battleSrc, 'unique') && str_contains($battleSrc, 'uk_token')
);

/* ======================================================================
 * 3. 结算幂等：行锁 + 状态 CAS + 快照
 * ====================================================================== */
section('3. 结算幂等：lock(true) + STATUS CAS + settle_result');

$battleServiceSrc = srcOf($pluginRoot . '/service/BattleService.php');

check('BattleService.php 可读', $battleServiceSrc !== '');
check('结算走 Db::transaction', str_contains($battleServiceSrc, 'Db::transaction'));
check('行锁 lock(true)（FOR UPDATE 仅在事务内生效）', str_contains($battleServiceSrc, 'lock(true)'));
check(
    'STATUS_DONE 走幂等分支（不再发奖 / 不再改进度）',
    str_contains($battleServiceSrc, 'STATUS_DONE') && str_contains($battleServiceSrc, 'settle_result')
);
check(
    '非 playing 态直接拒单（不进入结算）',
    str_contains($battleServiceSrc, 'STATUS_PLAYING')
);

/* ======================================================================
 * 4. 重放引擎：并发调用的隔离性
 * ====================================================================== */
section('4. 重放引擎：并发调用互不干扰');

$nodeEngineSrc = srcOf($pluginRoot . '/service/level/Match3NodeReplayEngine.php');

check('node 引擎用 tempnam 独占临时文件（并发不互相覆盖 stdin/stdout）', str_contains($nodeEngineSrc, 'tempnam'));
check('node 引擎超时强杀（防挂死进程占满 worker）', str_contains($nodeEngineSrc, 'taskkill') || str_contains($nodeEngineSrc, 'proc_terminate'));
check(
    'node 引擎不经 shell（数组形式 proc_open，无注入面）',
    str_contains($nodeEngineSrc, 'proc_open([')
);

/* ======================================================================
 * 5. 真并发冒烟步骤（B4：预发/压测环境人工执行）
 * ====================================================================== */
section('5. 真并发冒烟（B4）—— 需在真实环境执行，本脚本不代替');

echo <<<TXT
  ① 同一 run 两个节点并行结算（预期：不串号、不互相覆盖）
     · 开两个连接 A/B，各执行 `rogue/enter` 取到**不同** node_id
     · 同时提交两个节点的 result
     · 断言：SELECT run_id,node_id FROM match3_run_node WHERE run_id=? GROUP BY node_id HAVING COUNT(*)>1 → **0 行**

  ② 同一 battle 并发重提（预期：幂等，只结算一次）
     · 两个连接用同一 play_token 同时 `battle/submit`
     · 断言：返回同一份 settle_result；inventory_log 只多**一条**发奖记录

  ③ 压测口径（S5-5）：php src/plugins/match3game/tests/bench-replay.php --rounds=20

TXT;

echo "\n通过 {$passed} 项，失败 {$failed} 项" . ($skipped > 0 ? "，跳过 {$skipped} 项" : '') . "\n";
exit($failed === 0 ? 0 : 1);
