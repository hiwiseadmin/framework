<?php
/**
 * ============================================================
 * WiseEditor - MarkdownDriver 渲染契约回归（零依赖，CLI 直接跑）
 * ============================================================
 *
 * 校验 `MarkdownDriver::renderHtml()` 输出的**容器属性**与 `data-options`
 * 负载。前端适配器完全依赖这份负载（format / view / toolbar / capabilities /
 * upload），任何一项改了前端都会静默走默认值 —— 表现是"配置改了没效果"，
 * 而不是报错，所以必须在这里钉死。
 *
 * 覆盖：
 *   1. 驱动元信息（类型 / 名称 / 版本 / isMarkdownNative）
 *   2. 容器结构：data-editor-type=tiptap、data-mode=markdown、textarea 字段
 *   3. data-options：format / view / toolbar / capabilities / upload
 *   4. 工具栏 key 必须都在前端 BUTTON_REGISTRY 注册过（历史坑：静默跳过）
 *   5. 能力位与默认视图可被配置覆盖，非法 view 回落到 wysiwyg
 *   6. EditorProvider 把 markdown 类型注册到了 MarkdownDriver
 *
 * 用法：`php build/verify-markdown-driver.php`（或 `npm run verify:markdown-driver`）
 *
 * @package wiseeditor
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

require __DIR__ . '/../plugins/wiseeditor/src/EditorCapabilityGuard.php';
require __DIR__ . '/../plugins/wiseeditor/src/TipTapDriver.php';
require __DIR__ . '/../plugins/wiseeditor/src/MarkdownDriver.php';
require __DIR__ . '/../plugins/wiseeditor/EditorProvider.php';

use plugin\wiseeditor\EditorProvider;
use plugin\wiseeditor\src\MarkdownDriver;
use think\facade\Config;

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

/**
 * 从渲染出来的 HTML 里取某个属性（已 html 反转义）
 *
 * @param string $html
 * @param string $attr
 * @return string
 */
function attrOf(string $html, string $attr): string
{
    if (preg_match('/\s' . preg_quote($attr, '/') . '="([^"]*)"/', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    return '';
}

/** 渲染一次并解析出 data-options（失败返回空数组） */
function renderOptions(MarkdownDriver $driver, string $name = 'body', array $options = []): array
{
    $html = $driver->renderHtml($name, '# 标题', $options);
    $json = attrOf($html, 'data-options');
    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

echo "[verify-markdown-driver] Markdown 驱动渲染契约\n";

$driver = new MarkdownDriver();

check('getTypes() = [markdown]', $driver->getTypes() === ['markdown'], json_encode($driver->getTypes()));
check('getName() = Markdown', $driver->getName() === 'Markdown', $driver->getName());
check('isMarkdownNative() = true', $driver->isMarkdownNative() === true);

$pkg = json_decode((string) file_get_contents(__DIR__ . '/../package.json'), true);
$engineVersion = $pkg['dependencies']['@tiptap/core'] ?? '';
check('getVersion() 与 package.json 的 @tiptap/core 一致',
    $engineVersion !== '' && $driver->getVersion() === $engineVersion,
    $driver->getVersion() . ' vs ' . $engineVersion);

$html = $driver->renderHtml('body', "# 已有内容\n\n正文");

check('容器 data-editor=wiseeditor', attrOf($html, 'data-editor') === 'wiseeditor');
check('引擎类型仍是 tiptap（同一个编辑器内核）', attrOf($html, 'data-editor-type') === 'tiptap',
    attrOf($html, 'data-editor-type'));
check('data-mode = markdown', attrOf($html, 'data-mode') === 'markdown', attrOf($html, 'data-mode'));
$textareaTag = '';
if (preg_match('/<textarea[^>]*>/', $html, $tm)) {
    $textareaTag = $tm[0];
}
$textareaId = attrOf($textareaTag, 'id');
check('表单字段是 textarea（存 Markdown 原文）',
    $textareaId === attrOf($html, 'id') . '_textarea' && attrOf($textareaTag, 'name') === 'body',
    $textareaTag);
check('初值做了 HTML 转义（# 与换行原样保留）',
    strpos($html, '# 已有内容') !== false && strpos($html, '<h1>') === false);
check('id 可由 options 覆盖', attrOf($driver->renderHtml('body', '', ['id' => 'custom']), 'id') === 'custom');

$roHtml = $driver->renderHtml('body', '', ['readonly' => true, 'placeholder' => '写点什么']);
check('只读标记输出 data-readonly', attrOf($roHtml, 'data-readonly') === 'true');
check('占位文本输出 data-placeholder', attrOf($roHtml, 'data-placeholder') === '写点什么');

$opts = renderOptions($driver);

check("data-options.format = markdown", ($opts['format'] ?? '') === 'markdown', json_encode($opts['format'] ?? null));
check('data-options.view 是合法视图',
    in_array($opts['view'] ?? '', ['wysiwyg', 'split', 'source'], true), (string) ($opts['view'] ?? ''));
check('data-options 带 toolbar 数组', is_array($opts['toolbar'] ?? null) && $opts['toolbar'] !== []);
check('data-options 带 capabilities', is_array($opts['capabilities'] ?? null));
check('data-options 带 upload.url', !empty($opts['upload']['url']), (string) ($opts['upload']['url'] ?? ''));
check('data-options 带 upload.allowed_ext', isset($opts['upload']['allowed_ext']));

$js = (string) file_get_contents(__DIR__ . '/../plugins/wiseeditor/statics/js/wiseeditor-tiptap.js');
$registryBody = '';
if (preg_match('/const BUTTON_REGISTRY = \{(.*?)\n\};/s', $js, $m)) {
    $registryBody = $m[1];
}
$registered = [];
if ($registryBody !== '' && preg_match_all('/^\s{4}([A-Za-z][A-Za-z0-9]*)\s*:/m', $registryBody, $mm)) {
    $registered = $mm[1];
}

check('能从 wiseeditor-tiptap.js 解析出 BUTTON_REGISTRY', $registered !== [], 'keys=' . count($registered));

$missing = [];
foreach ((array) ($opts['toolbar'] ?? []) as $key) {
    if ($key === '|') {
        continue;
    }
    if (!in_array($key, $registered, true)) {
        $missing[] = $key;
    }
}
check('默认工具栏的 key 全部已在前端注册', $missing === [], implode(',', $missing));

Config::set(['markdown' => ['capabilities' => ['upload' => false]]], 'wiseeditor');
$optsNoUpload = renderOptions($driver);
check('配置 capabilities.upload=false → 下发 false',
    ($optsNoUpload['capabilities']['upload'] ?? null) === false,
    json_encode($optsNoUpload['capabilities']['upload'] ?? null));

Config::set(['markdown_view' => 'bogus'], 'wiseeditor');
$optsBadView = renderOptions($driver);
check('非法 view 回落到 wysiwyg', ($optsBadView['view'] ?? '') === 'wysiwyg', (string) ($optsBadView['view'] ?? ''));

Config::set(['markdown_view' => 'split'], 'wiseeditor');
$optsSplit = renderOptions($driver);
check('markdown_view=split → data-options.view=split', ($optsSplit['view'] ?? '') === 'split');

$optsOverride = renderOptions($driver, 'body', ['view' => 'source']);
check('页面级 $options[view] 优先于配置', ($optsOverride['view'] ?? '') === 'source');

$optsToolbar = renderOptions($driver, 'body', ['toolbar' => ['bold', 'italic']]);
check('页面级 $options[toolbar] 数组优先', ($optsToolbar['toolbar'] ?? []) === ['bold', 'italic'],
    json_encode($optsToolbar['toolbar'] ?? null));

$manager = new app\common\library\EditorManager();
(new EditorProvider())->registerEditors($manager);

$registry = new ReflectionProperty($manager, 'driverRegistry');
$registry->setAccessible(true);
$map = (array) $registry->getValue($manager);

check('EditorProvider 注册 markdown 类型', ($map['markdown'] ?? '') === MarkdownDriver::class, (string) ($map['markdown'] ?? ''));
check('没有遗留 milkdown 类型注册', !isset($map['milkdown']), json_encode(array_keys($map)));

check('MilkdownDriver 类已删除', !class_exists('plugin\wiseeditor\src\MilkdownDriver'));
$extPoints = require __DIR__ . '/../plugins/wiseeditor/provider.php';
check('provider 不再暴露 editor.milkdown', is_array($extPoints) && !array_key_exists('editor.milkdown', $extPoints),
    json_encode(array_keys($extPoints)));

$tipTap = new \plugin\wiseeditor\src\TipTapDriver();
$tiptapDefaults = $tipTap->getDefaultConfig();
$tiptapToolbar = (array) ($tiptapDefaults['toolbar_presets']['full'] ?? []);
$tiptapMissing = [];
foreach ($tiptapToolbar as $key) {
    if ($key === '|') {
        continue;
    }
    if (!in_array($key, $registered, true)) {
        $tiptapMissing[] = $key;
    }
}
check('富文本默认工具栏：key 全部已在前端注册', $tiptapMissing === [], implode(',', $tiptapMissing));
check('富文本默认工具栏含对齐按钮',
    in_array('alignCenter', $tiptapToolbar, true), json_encode($tiptapToolbar));

$mdToolbar = (array) ($driver->getDefaultConfig()['toolbar_presets']['full'] ?? []);
check('Markdown 默认工具栏不含段落对齐（存不下来）',
    array_intersect(['alignLeft', 'alignCenter', 'alignRight', 'alignJustify'], $mdToolbar) === [],
    json_encode($mdToolbar));

echo "\n[verify-markdown-driver] ", $fail === 0 ? "全部通过 ✓\n" : "失败 {$fail} 项 ✗\n";

exit($fail === 0 ? 0 : 1);
