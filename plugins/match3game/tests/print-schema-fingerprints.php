<?php

declare(strict_types=1);

/**
 * 打印一批 Level Schema 的**服务端指纹**（`StableStringify::fingerprint`）
 *
 * 【用途】S3-6 的客户端断言（`match3game/src/rogue/apply-relics.ts`）建立在
 *   "TS 与 PHP 的稳定字符串化口径一致"这个前提上。这个前提**不能靠读代码相信**：
 *   键排序、数字格式（`1.0` vs `1`）、空值、Unicode 转义任何一处不同，
 *   指纹就会不同 —— 而症状是"客户端报 schema 指纹不一致"，很容易误判成"关卡真的不同源"。
 *
 * 所以：用同一批真实 schema 在两端各算一遍，把结果固化成测试
 *   · 本脚本：PHP 侧期望值（生成用）
 *   · `match3game/tests/schema-fingerprint.test.ts`：TS 侧断言（比对上面那份期望值）
 * 任一侧改了字符串化实现，测试立刻红。
 *
 * 【用法】
 *   php src/plugins/match3game/tests/print-schema-fingerprints.php [向量文件]
 *   默认读 match3game/vectors/replay-vectors-ts.json 的 `schemas`
 *
 * 【退出码】0 = 正常打印；2 = 输入文件缺失/不合法
 */

$repoRoot = dirname(__DIR__, 4);
$vectors  = $argv[1] ?? $repoRoot . '/match3game/vectors/replay-vectors-ts.json';

if (!is_file($vectors)) {
    fwrite(STDERR, "向量文件不存在: {$vectors}\n");
    fwrite(STDERR, "请先在前端仓库导出: cd match3game && npm run vectors:replay\n");
    exit(2);
}

$raw = file_get_contents($vectors);
$vec = is_string($raw) ? json_decode($raw, true) : null;

if (!is_array($vec) || !is_array($vec['schemas'] ?? null)) {
    fwrite(STDERR, "向量文件不合法（缺 schemas）: {$vectors}\n");
    exit(2);
}

// 只需要字符串化 + 指纹这两个纯函数：直接 require，不引入框架与 autoload
require_once dirname(__DIR__) . '/service/level/StableStringify.php';

foreach ($vec['schemas'] as $key => $schema) {
    $fp = \plugin\match3game\service\level\StableStringify::fingerprint($schema);
    echo $key . "\t" . $fp . "\n";
}
