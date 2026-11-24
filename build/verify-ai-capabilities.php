<?php
/**
 * ============================================================
 * AI 能力目录 × 权限树 一致性校验（B-1 DoD）
 * ============================================================
 *
 * 三方比对：
 *   A. 能力目录       CapabilityCatalog::build()（AI 实际可调用的能力）
 *   B. 应收录权限点   ToolPermissionRegistrar::collect()（同步命令会写入的集合）
 *   C. 权限表实际     ws_auth_permissions 中 ident LIKE 'ai.%' 且未软删
 *
 * 判定＼�：
 *   - A ⊄ B  → FAIL：能力声明了权限标识，但同步器不收录 → 该能力将被 fail-closed 拒绝
 *   - B − C  → FAIL：该同步却没同步 → 后台无法授权
 *   - C − B  → WARN：孤儿权限点，**只报告不清理**（可能是未加载插件的工具，也可能是历史残留）
 *
 * 结论口径（重要）：
 *   本脚本在 CLI 下运行，`ai:capabilities` 只能看到 builtin 与 ProviderCache 恢复的工具，
 *   不等于 Web 环境的完整注册表。因此结论标注为「CLI 口径通过，插件口径待验」。
 *
 * 用法：`php build/verify-ai-capabilities.php`（或 `npm run verify:ai-capabilities`）
 *
 * 退出码：0 = 无 FAIL（允许 WARN / DB 不可用时 SKIP）；1 = 存在 FAIL
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

echo "[verify-ai-capabilities] 能力目录 × 权限树\n";

$fail = 0;
$warn = 0;

/**
 * @param string $name
 * @param bool   $ok
 * @param string $detail
 */
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '[PASS] ' : '[FAIL] '), $name, $detail === '' ? '' : (' :: ' . $detail), "\n";
}

/**
 * @param string $message
 */
function warn(string $message): void
{
    global $warn;
    $warn++;
    echo '[WARN] ', $message, "\n";
}

/**
 * @param string $message
 */
function info(string $message): void
{
    echo '[INFO] ', $message, "\n";
}

/**
 * 把 ident 列表格式化为可读字符串（最多 8 条）
 *
 * @param string[] $idents
 */
function brief(array $idents): string
{
    $idents = array_values($idents);

    return implode(', ', array_slice($idents, 0, 8)) . (count($idents) > 8 ? ' …' : '');
}

$items         = \wise\agent\capability\CapabilityCatalog::build();
$byPermission  = [];
$noPermission  = [];

foreach ($items as $item) {
    $permission = trim((string) ($item['permission'] ?? ''));

    if ($permission === '') {
        $noPermission[] = (string) ($item['name'] ?? '');
        continue;
    }

    $byPermission[$permission][] = (string) ($item['name'] ?? '');
}

$sources = [];
foreach ($items as $item) {
    $source          = (string) ($item['source'] ?? 'unknown');
    $sources[$source] = ($sources[$source] ?? 0) + 1;
}

ksort($sources);
$sourceText = implode(' / ', array_map(
    static fn(string $k, int $v): string => "{$k}={$v}",
    array_keys($sources),
    array_values($sources)
));

info(sprintf(
    '能力目录：%d 项（%d 项声明权限标识，%d 项无权限标识）',
    count($items),
    count($byPermission),
    count($noPermission)
));

if ($noPermission !== []) {
    info('无权限标识（不计入比对）：' . brief($noPermission));
}

info('能力来源分布：' . ($sourceText === '' ? '无' : $sourceText));

$collected = [];

try {
    $collected = (new \wise\agent\security\ToolPermissionRegistrar())->collect();
} catch (\Throwable $e) {
    check('收集应收录权限点', false, $e->getMessage());
}

$collectedIdents = array_keys($collected);

info('应收录权限点：' . count($collectedIdents));

$missingInCollect = array_values(array_diff(array_keys($byPermission), $collectedIdents));

check(
    '能力目录 permission ⊆ 应收录权限点',
    $missingInCollect === [],
    $missingInCollect === [] ? '' : ('未收录：' . brief($missingInCollect))
);

$dbIdents    = [];
$dbAvailable = false;
$dbError     = '';

try {
    $dbIdents = (array) \think\facade\Db::name('auth_permissions')
        ->whereLike('ident', 'ai.%')
        ->whereNull('delete_time')
        ->column('ident');

    $dbAvailable = true;
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

if (!$dbAvailable) {
    echo '[SKIP] 权限表比对（数据库不可用：', $dbError, '）', "\n";
} else {
    info('权限表 ai.* 记录：' . count($dbIdents));

    $missingInDb = array_values(array_diff($collectedIdents, $dbIdents));

    check(
        '应收录权限点 ⊆ 权限表',
        $missingInDb === [],
        $missingInDb === [] ? '' : ('未落库：' . brief($missingInDb))
    );

    $orphans = array_values(array_diff($dbIdents, $collectedIdents));

    if ($orphans !== []) {
        warn(sprintf(
            '权限表存在 %d 个孤儿权限点（只报告，不清理）：%s',
            count($orphans),
            brief($orphans)
        ));
        echo '       处置：确认确为历史残留后再执行 `php think wise:agent:permissions --prune`', "\n";
    } else {
        check('权限表无孤儿 ai.* 权限点', true);
    }
}

$onlyBuiltin = array_keys($sources) === ['builtin'];

echo "\n";
if ($onlyBuiltin) {
    info('结论口径：CLI 口径通过，插件口径待验');
    info('  原因：CLI 未加载插件，能力目录仅含 builtin；插件工具需在完整 Web 环境复验。');
} else {
    info('结论口径：CLI 口径通过（含 ProviderCache 恢复的工具）；仍建议在完整 Web 环境复验一次。');
}

echo $fail === 0
    ? ("\n[verify-ai-capabilities] 无阻断项" . ($warn > 0 ? "（{$warn} 个提示）" : '') . "\n")
    : "\n[verify-ai-capabilities] 失败 {$fail} 项\n";

exit($fail === 0 ? 0 : 1);
