<?php
/**
 * 消消乐插件自检总入口（CI / 本地一键）
 *
 *   php src/plugins/match3game/tests/run-all.php
 *   php src/plugins/match3game/tests/run-all.php --verbose   # 打印每个脚本的完整输出
 *
 * 【设计要点】
 *   · **零依赖**：所有 verify-*.php 都用手写 stub（`tests/stub/`）脱离框架与 composer 运行，
 *     因此本入口**不需要 `composer install`**，CI 里秒级完成。
 *   · **退出码驱动**：任一脚本非 0 退出即整体返回 1，可直接作为 CI 门禁。
 *   · 汇总"通过/失败"项数，并只回显失败脚本的尾部输出（避免日志噪音）。
 *
 * CI 里也可以直接逐个跑（本文件只是便利入口）：
 *   for f in src/plugins/match3game/tests/verify-*.php; do php "$f" || exit 1; done
 */

declare(strict_types=1);

/**
 * 自检脚本清单（顺序 = 依赖顺序，便于排查）
 *
 * 覆盖：Level Schema v1 / 三层校验 / 关卡生成器 / Patch 引擎 / 肉鸽 / 肉鸽扩展 / 社交 / PHP 重放
 */
$scripts = [
    'verify-level-schema'  => 'Level Schema v1 + LevelValidator + StableStringify',
    'verify-submission'    => '提交三件套（ops/checkpoints/claim）三层校验',
    'verify-generator'     => '随机关卡生成器 + AI 模拟器',
    'verify-patch-engine'  => 'Patch 引擎 + 30 条遗物全量体检 + 组合 Fuzz + 跨语言向量',
    'verify-rogue'         => '肉鸽局状态机 + 关卡树 + 难度曲线',
    'verify-rogue-ext'     => '效果字典 + 商店/休息/事件 + 每日挑战',
    'verify-social'        => '对战判定 + 积分段位 + 榜单 + 助力 + 群擂台',
    'verify-php-replay'    => 'PHP 重放引擎 + upper_bound 比对模式（含 LevelSimulator 基线锁）',
    'verify-node-replay'   => 'Node 重放引擎（后端复用前端 TS 算法；bundle 缺失时自动 SKIP）',
    'verify-http-replay'   => 'HTTP 重放引擎（mode=http）：陈旧服务不得拒单（fill_version 防线）',
    'verify-checkpoint-replay' => '逐回合 checkpoint 比对（S5-3）：引擎产出指纹 + 跨语言同源 + 篡改拒单',
    'verify-strict-rollout' => 'P0 上线：非法 op 拒单 + strict 口径 + 错误码映射',
    'verify-ad-added-moves' => '广告加步（复活/加步）的步数上限放行（S3-9）',
    'verify-funnel'        => '埋点漏斗事件构造与清洗（P6-B05）',
    'verify-reward-audit'  => '发奖 reason 受控词表 + 灵石审计（P6-B04）',
    'verify-cron'          => '定时任务：命令注册 + 类文件 + setName 一致性（S5-8）',
    'verify-concurrency'   => '并发安全不变量（S5-5 / B4）：节点唯一 + 唯一索引 + 结算幂等 + 引擎隔离',
    // ⚠️ 只跑**快档**（40 组合，约 6s）。全量档 200 组合要 ~110s，用
    //    `php src/plugins/match3game/tests/verify-balance.php --full` 手动跑。
    'verify-balance'       => '平衡回归（快档 40 组）+ seed 一致性 100 次（S5-6 / B6）',
];

$verbose = in_array('--verbose', $argv ?? [], true) || in_array('-v', $argv ?? [], true);

$php = PHP_BINARY !== '' ? PHP_BINARY : 'php';

echo "==============================================================\n";
echo " 消消乐插件自检（match3game）\n";
echo ' PHP ' . PHP_VERSION . "   entry: " . basename(__FILE__) . "\n";
echo "==============================================================\n\n";

$rows     = [];
$failures = [];
$totalPass = 0;
$totalFail = 0;

foreach ($scripts as $name => $desc) {
    $file = __DIR__ . '/' . $name . '.php';

    if (!is_file($file)) {
        $rows[] = [$name, '缺失', 0, 0, 1];
        $failures[$name] = "脚本不存在: {$file}";
        continue;
    }

    $out  = [];
    $code = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    $text = implode("\n", $out);

    $pass = 0;
    $fail = 0;
    if (preg_match('/通过\s*(\d+)\s*项，失败\s*(\d+)\s*项/u', $text, $m) === 1) {
        $pass = (int) $m[1];
        $fail = (int) $m[2];
    }

    $totalPass += $pass;
    $totalFail += $fail;
    $rows[] = [$name, $desc, $pass, $fail, $code];

    if ($code !== 0) {
        $tail = implode("\n", array_slice($out, -25));
        $failures[$name] = $tail;
    }

    if ($verbose) {
        echo "────────── {$name} ──────────\n{$text}\n\n";
    }
}

// ---------------- 汇总 ----------------
/**
 * 按**显示宽度**补齐（中文等全角字符占 2 列）
 *
 * 不能用 `str_pad()` / `printf %-Ns`：它们按**字节**计数，UTF-8 中文 3 字节会算成 3 列，
 * 混排时必然错位。也不能依赖 `mb_strwidth`（mbstring 未必启用），这里自己按字符数宽度算。
 */
$pad = static function (string $s, int $width): string {
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($s);

    $cur = 0;
    foreach ($chars as $ch) {
        $cur += strlen($ch) > 1 ? 2 : 1; // 非 ASCII（UTF-8 多字节）按全角计
    }

    return $s . str_repeat(' ', max(0, $width - $cur));
};

$DESC_W = 62;
$line   = str_repeat('-', 22 + $DESC_W + 24);

echo $pad('脚本', 22) . $pad('覆盖', $DESC_W) . $pad('通过', 8) . $pad('失败', 8) . $pad('退出码', 8) . "\n";
echo $line . "\n";
foreach ($rows as [$name, $desc, $pass, $fail, $code]) {
    echo $pad($name, 22)
        . $pad($desc, $DESC_W)
        . $pad((string) $pass, 8)
        . $pad((string) $fail, 8)
        . $pad((string) $code, 8)
        . ($code === 0 ? '' : ' ← 失败')
        . "\n";
}
echo $line . "\n";
echo $pad('合计', 22) . $pad(count($scripts) . ' 个脚本', $DESC_W) . $pad((string) $totalPass, 8) . $pad((string) $totalFail, 8) . "\n";
echo "\n";

if ($failures !== []) {
    echo "==============================================================\n";
    echo " 失败明细\n";
    echo "==============================================================\n";
    foreach ($failures as $name => $tail) {
        echo "\n───── {$name} ─────\n{$tail}\n";
    }
    echo "\n自检未通过。\n";
    exit(1);
}

if ($totalPass === 0) {
    echo "异常：一个用例都没跑到（可能脚本被跳过）。\n";
    exit(1);
}

echo "✅ 全部自检通过（{$totalPass} 项）\n";
exit(0);
