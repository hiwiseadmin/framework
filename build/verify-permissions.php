<?php
/**
 * ============================================================
 * WiseEditor - 权限位映射回归（零依赖，CLI 直接跑）
 * ============================================================
 *
 * 校验 `TipTapDriver::restrictByPermissionIdents()` 的 AND 语义与保险策略，
 * 不需要登录态 / 数据库：用反射直接喂能力位与权限标识。
 *
 * 覆盖分支：
 *   1. 超管 `*` → 能力位不变
 *   2. 部分授权 → 未授权的映射位收窄，未映射位（format/table）不受影响
 *   3. AND 语义 → 配置关掉的位不会被权限"打开"
 *   4. 保险 → 5 个 ident 一个都没命中时保持配置值
 *   5. 严格模式 → `require_registered=false` 时映射位全关
 *   6. 多位命中 → 各自独立生效
 *
 * 用法：`php build/verify-permissions.php`（或 `npm run verify:permissions`）
 *
 * @package wiseeditor
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

require __DIR__ . '/../plugins/wiseeditor/src/EditorCapabilityGuard.php';
require __DIR__ . '/../plugins/wiseeditor/src/TipTapDriver.php';

use think\facade\Config;

$driver = new plugin\wiseeditor\src\TipTapDriver();
$method = new ReflectionMethod($driver, 'restrictByPermissionIdents');
$method->setAccessible(true);

/** 与 DEFAULT_CAPABILITIES 结构一致的全开样本 */
$all = [
    'format' => true, 'table' => true, 'code' => true, 'upload' => true,
    'source' => true, 'fullscreen' => true, 'embed' => true,
    'attach' => false, 'find' => false,
];

$fail = 0;

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

/** @var callable(array, array): array $run */
$run = static fn(array $caps, array $idents): array => $method->invoke($driver, $caps, $idents);

echo "[verify-permissions] 权限位映射\n";

check('超管 * → 能力位不变', $run($all, ['*']) === $all);

$r2 = $run($all, ['wiseeditor:upload']);
check('仅 upload：upload=true', $r2['upload'] === true);
check('仅 upload：source/code/fullscreen/embed=false',
    $r2['source'] === false && $r2['code'] === false && $r2['fullscreen'] === false && $r2['embed'] === false);
check('仅 upload：未映射的 format/table 不受影响', $r2['format'] === true && $r2['table'] === true);

$r3 = $run(['upload' => false, 'source' => true], ['wiseeditor:upload']);
check('配置 upload=false + 有权限 → 仍 false', $r3['upload'] === false);
check('配置 source=true 但无权限 → false', $r3['source'] === false);

check('ident 全不命中 → 保持配置值', $run($all, ['some:other']) === $all);

Config::set(['permissions' => ['require_registered' => false]], 'wise-editor');
$r5 = $run($all, ['some:other']);
check('严格模式：映射位全关', $r5['upload'] === false && $r5['source'] === false && $r5['code'] === false);
check('严格模式：format 不受影响', $r5['format'] === true);

Config::set(['permissions' => ['require_registered' => true]], 'wise-editor');
$r6 = $run($all, ['wiseeditor:upload', 'wiseeditor:source']);
check('upload+source 命中 → 两者 true', $r6['upload'] === true && $r6['source'] === true);
check('未授权的 fullscreen → false', $r6['fullscreen'] === false);

echo "\n[verify-permissions] ", $fail === 0 ? "全部通过 ✓\n" : "失败 {$fail} 项 ✗\n";

$guard = \plugin\wiseeditor\src\EditorCapabilityGuard::class;

check('allowsWith：非编辑器请求（空能力位）→ 放行', $guard::allowsWith('', null) === true);
check('allowsWith：未映射能力位（format）→ 放行', $guard::allowsWith('format', null) === true);
check('allowsWith：upload + 无法判定登录态 → 拒绝', $guard::allowsWith('upload', null) === false);
check('allowsWith：upload + 超管 → 放行', $guard::allowsWith('upload', ['*']) === true);
check('allowsWith：upload + 有 wiseeditor:upload → 放行', $guard::allowsWith('upload', ['wiseeditor:upload']) === true);
check('allowsWith：upload + 只有 source 权限 → 拒绝', $guard::allowsWith('upload', ['wiseeditor:source']) === false);

check('allowsWith：attach + 只有 upload 权限 → 拒绝',
    $guard::allowsWith('attach', ['wiseeditor:upload']) === false);
check('allowsWith：attach + 有 wiseeditor:attach → 放行',
    $guard::allowsWith('attach', ['wiseeditor:attach']) === true);
check('能力位 attach 已映射到 wiseeditor:attach',
    ($guard::PERMISSION_MAP['attach'] ?? '') === 'wiseeditor:attach');

$requestSrc = (string) file_get_contents(__DIR__ . '/../app/Request.php');
check('净化白名单：允许 font-family（字体）',
    strpos($requestSrc, 'font-family') !== false);
check('净化白名单：允许 font-size（字号）',
    strpos($requestSrc, 'font-size') !== false);
check('净化白名单：允许 mark 标签（高亮）',
    (bool) preg_match("/'mark\[/", $requestSrc));

if (class_exists('\HTMLPurifier')) {
    $cssCfg = \HTMLPurifier_Config::createDefault();
    $cssCfg->set('HTML.Allowed', 'p[style],span[style],mark[data-color|style]');
    $cssCfg->set('CSS.AllowedProperties', 'font,font-family,font-size,color,background-color,text-align');
    $purifier = new \HTMLPurifier($cssCfg);

    $out = $purifier->purify(
        '<p><span style="font-family:SimSun;font-size:18px;color:#d93025">字</span>'
        . '<mark data-color="#fff3a3" style="background-color:#fff3a3">亮</mark></p>'
    );

    check('真净化：font-family 未被剥掉', strpos($out, 'font-family') !== false, $out);
    check('真净化：font-size 未被剥掉', strpos($out, 'font-size') !== false, $out);
    check('真净化：color 未被剥掉', strpos($out, 'color') !== false, $out);
    check('真净化：mark 标签与其底色保留',
        strpos($out, '<mark') !== false && strpos($out, 'background-color') !== false, $out);
} else {
    check('兜底清洗器按标签白名单裁剪（mark 必须入白名单）',
        strpos($requestSrc, "strip_tags(\$html, '<' . implode") !== false);
}

$controllerSrc = (string) file_get_contents(__DIR__ . '/../app/common/controller/Attachment.php');
check('附件上传控制器：读取能力位请求头',
    strpos($controllerSrc, 'x-wise-editor-capability') !== false);
check('附件上传控制器：调用 EditorCapabilityGuard 判定',
    strpos($controllerSrc, 'EditorCapabilityGuard') !== false);
check('附件上传控制器：拒绝时返回 FORBIDDEN',
    strpos($controllerSrc, 'ErrorCode::FORBIDDEN') !== false);

exit($fail === 0 ? 0 : 1);
