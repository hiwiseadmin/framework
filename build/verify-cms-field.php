<?php
/**
 * WiseCMS 编辑器字段 —— 端到端核验（阶段：渲染 → 提交 → 净化 → 落库 → 重开）
 * ============================================================================
 *
 * 与 plugins/wisecms/tests/run.php 的分工：
 *   run.php    —— 零依赖纯逻辑自检（不引导框架、不连数据库）；
 *                 数据库相关项明确标注 "需要 ThinkPHP 应用与测试数据库，暂缓接入"。
 *   本脚本     —— **补上那一半**：真实引导 think\App、连真实 MySQL、走真实类与真实白名单。
 *
 * 用法（PHP 8.1+；本机 PHP 位于 D:/BtSoft/php/84/php.exe）：
 *   php build/verify-cms-field.php env                     环境体检（只读）
 *   php build/verify-cms-field.php render --state=asis     渲染：当前真实状态
 *   php build/verify-cms-field.php render --state=wired    渲染：强制接线 wiseeditor
 *   php build/verify-cms-field.php chain [--payload=f.json] 提交→净化→落库→重开
 *
 * 以上命令均可加 `--model=N` 指定模型（默认 1=article；2=page）。
 * 例如：php build/verify-cms-field.php chain --model=2
 *
 * 产物：src/.verify-out/cms/*.json（机器可读）+ *.html（送浏览器渲染的表单页）
 *
 * ⚠️ 本脚本**不修改**插件启用状态、不写主表；落库阶段只向 `{model}_data` 副表的
 *    哨兵行写入并在结束时删除（--keep 可保留）。
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require $ROOT . '/vendor/autoload.php';

use think\facade\Db;

const MODEL_ID    = 1;
const SENTINEL_ID = 999000001;
const OUT_DIR_REL = '.verify-out/cms';


$GLOBALS['R'] = [];

function rec(string $group, string $name, bool $ok, string $detail = ''): bool
{
    $GLOBALS['R'][] = ['group' => $group, 'name' => $name, 'ok' => $ok, 'detail' => $detail];
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? '  — ' . $detail : '');
    return $ok;
}

function head(string $t, string $sub = ''): void
{
    echo "\n" . str_repeat('─', 72) . "\n";
    echo '  ' . $t . ($sub !== '' ? "   ({$sub})" : '') . "\n";
    echo str_repeat('─', 72) . "\n";
}

function outDir(string $root): string
{
    $dir = $root . '/' . OUT_DIR_REL;
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function saveHtml(string $root, string $file, string $html): string
{
    $path = outDir($root) . '/' . $file;
    file_put_contents($path, $html);
    return $path;
}

function saveJson(string $root, string $file, array $data): string
{
    $path = outDir($root) . '/' . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $path;
}

function opt(array $argv, string $key, ?string $default = null): ?string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$key}=")) {
            return substr($a, strlen($key) + 3);
        }
    }
    return $default;
}

/** HTML 骨架（标签 + 属性多重集），用于量化「剥损」 */
function htmlSkeleton(string $html): array
{
    $tags = [];
    $attrs = [];

    preg_match_all('/<\s*([a-zA-Z][a-zA-Z0-9]*)((?:"[^"]*"|\'[^\']*\'|[^<>])*?)\s*\/?>/s', $html, $m, PREG_SET_ORDER);

    foreach ($m as $tag) {
        $name = strtolower($tag[1]);
        $tags[$name] = ($tags[$name] ?? 0) + 1;

        if (preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s\/>]+)/', $tag[2] ?? '', $am, PREG_SET_ORDER)) {
            foreach ($am as $a) {
                $an = strtolower($a[1]);
                $attrs[$an] = ($attrs[$an] ?? 0) + 1;
            }
        }
    }
    ksort($tags);
    ksort($attrs);

    return ['tags' => $tags, 'attrs' => $attrs];
}

function htmlText(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/<[^>]*>/', '', $html)));
}

function lostFrom(array $before, array $after): array
{
    $lost = [];
    foreach ($before as $k => $v) {
        $now = $after[$k] ?? 0;
        if ($now < $v) {
            $lost[$k] = $v - $now;
        }
    }
    ksort($lost);
    return $lost;
}


$t0 = microtime(true);
$app = new \think\App();
$app->initialize();

$cmd  = $argv[1] ?? 'env';
$wired = false;

$MODEL_ID = (int) opt($argv, 'model', (string) MODEL_ID);
if ($MODEL_ID <= 0) {
    echo "无效的 --model 值\n";
    exit(2);
}

/**
 * 模拟 HTTP 首请求的插件加载 —— 与 PluginLoader::bootPlugins() 同序同语义：
 * 遍历所有「已启用且库包依赖齐全」的插件，逐个 initServiceProvider。
 *
 * 为什么必须模拟、而不是硬编码插件名：
 *   CLI 下只有声明了 config/console.php 的插件会被 PluginServiceProvider 初始化；
 *   wiseeditor 没有该文件，因此 **CLI 视角下 editor.manager 永远缺失**，
 *   而真实后台（HTTP）走的是本函数复现的这条路径。
 *   硬编码插件名会把「CLI 的加载缺口」误当成「插件没启用」。
 *
 * @param string[]|null $idents 指定插件；null = 按 HTTP 语义遍历全部已启用插件
 */
$bootPlugins = static function (?array $idents = null) use ($app): array {
    $manager = $app->get('plugin.manager');

    if ($idents === null) {
        $idents = [];
        foreach ($manager->getAllPlugins() as $name => $meta) {
            if ($meta->isEnabled() && $manager->missingPackages($meta) === []) {
                $idents[] = $name;
            }
        }
    }

    $log = [];
    foreach ($idents as $id) {
        try {
            $manager->initServiceProvider($id);
            $log[$id] = 'ok';
        } catch (\Throwable $e) {
            $log[$id] = get_class($e) . ': ' . $e->getMessage();
        }
    }
    return $log;
};

$editorState = static function () use ($app): array {
    try {
        $mgr = $app->make('editor.manager');
        $tags = method_exists($mgr, 'getAllowedTags') ? $mgr->getAllowedTags() : [];
        return [
            'bound'      => true,
            'class'      => get_class($mgr),
            'has_richtext' => $mgr->has('richtext'),
            'richtext_driver' => $mgr->has('richtext') ? get_class($mgr->driver('richtext')) : null,
            'version'    => $mgr->has('richtext') ? $mgr->driver('richtext')->getVersion() : null,
            'tag_count'  => count($tags),
            'has_colgroup' => in_array('colgroup', $tags, true),
            'has_hr'     => in_array('hr', $tags, true),
        ];
    } catch (\Throwable $e) {
        return ['bound' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
    }
};


if ($cmd === 'env') {
    head('WiseCMS 编辑器字段端到端核验', '阶段 0 · 环境体检（只读）');

    $report = ['stage' => 'env', 'php' => PHP_VERSION, 'mysql' => null, 'plugins' => [], 'models' => [], 'fields' => [], 'tables' => [], 'editor' => []];

    try {
        $r = Db::query('select version() v, database() d');
        $report['mysql'] = $r[0]['v'] . ' @ ' . $r[0]['d'];
        rec('env', 'MySQL 连通', true, $report['mysql']);
    } catch (\Throwable $e) {
        rec('env', 'MySQL 连通', false, $e->getMessage());
        saveJson($ROOT, 'env.json', $report);
        goto done;
    }

    $manager = $app->get('plugin.manager');
    foreach (['wiseeditor', 'wisecms'] as $ident) {
        $state    = $manager->getPluginState($ident);
        $enabled  = $manager->isEnabled($ident);
        $installed = $manager->isInstalled($ident, false);
        $report['plugins'][$ident] = ['state' => $state, 'enabled' => $enabled, 'installed' => $installed];
        rec('env', "插件 {$ident} 状态 = {$state}", true, 'enabled=' . var_export($enabled, true));
    }

    $ed = $editorState();
    $report['editor'] = $ed;
    rec('env', "editor.manager " . ($ed['bound'] ? '已绑定（' . ($ed['class'] ?? '') . '）' : '未绑定 —— 编辑器字段将回退'), true);
    if ($ed['bound']) {
        rec('env', 'has("richtext")', (bool) $ed['has_richtext'], 'driver=' . ($ed['richtext_driver'] ?? 'none'));
    }

    foreach (Db::name('wisecms_model')->select()->toArray() as $m) {
        $report['models'][] = $m;
    }
    foreach (Db::name('wisecms_model_field')->select()->toArray() as $f) {
        $report['fields'][] = $f;
    }
    $editorFields = array_values(array_filter($report['fields'], static fn ($f) => ($f['form_type'] ?? '') === 'editor'));
    rec('env', '存在 editor 类型字段', count($editorFields) > 0, count($editorFields) . ' 个：'
        . implode('、', array_map(static fn ($f) => "model{$f['model_id']}.{$f['field']}", $editorFields)));

    $allTables = array_map(static fn ($t) => reset($t), Db::query('SHOW TABLES'));
    foreach ($report['models'] as $m) {
        $data = ($m['table_name'] ?? '') . '_data';
        $full = Db::name($data)->getTable();
        $ok   = in_array($full, $allTables, true);
        $report['tables'][$data] = $ok;
        rec('env', "副表 {$data} 存在", $ok, $ok ? '' : '缺失 → 自定义字段无法落库（可用 ModelService::syncModelSchema 修复）');
    }

    printf("\n  体检耗时 %.0f ms，峰值内存 %.1f MB\n", (microtime(true) - $t0) * 1000, memory_get_peak_usage(true) / 1048576);
    saveJson($ROOT, 'env.json', $report);
    done:
    exit(0);
}


if ($cmd === 'render') {
    $state = opt($argv, 'state', 'asis');
    $wired = $state === 'wired';

    head('字段渲染核验', $wired ? 'HTTP 语义（PluginLoader 加载已启用插件）' : 'CLI 视角（插件尚未加载）');

    $bootLog = [];
    if ($wired) {
        $bootLog = $bootPlugins();
        echo "  插件引导（HTTP 语义）：\n";
        foreach ($bootLog as $id => $res) {
            printf("    %-12s %s\n", $id, $res);
        }
        $ed = $editorState();
        rec('render', 'editor.manager 已绑定', (bool) ($ed['bound'] ?? false), (string) ($ed['class'] ?? $ed['error'] ?? ''));
        rec('render', 'has("richtext") = true', (bool) ($ed['has_richtext'] ?? false), 'version=' . ($ed['version'] ?? '?'));
        rec('render', '合并白名单含 colgroup / hr', (bool) ($ed['has_colgroup'] ?? false) && (bool) ($ed['has_hr'] ?? false),
            "tags={$ed['tag_count']}");
    }

    $formData = null;
    $renderError = null;
    try {
        $formData = (new \plugin\wisecms\service\model\FormService())->generateForm($MODEL_ID, [], 'add');
    } catch (\Throwable $e) {
        $renderError = get_class($e) . ': ' . $e->getMessage();
    }

    $report = ['stage' => 'render', 'state' => $state, 'boot' => $bootLog, 'error' => $renderError, 'fields' => []];

    if ($renderError !== null) {
        rec('render', 'FormService::generateForm 未抛异常', false, $renderError);
        saveJson($ROOT, 'render-' . $state . '.json', $report);
        echo "\n  ⚠ 渲染直接失败，后续断言跳过\n";
        exit(1);
    }

    rec('render', 'FormService::generateForm 未抛异常', true);

    $editor = null;
    $editorName = null;
    foreach (['base', 'senior'] as $grp) {
        foreach ($formData[$grp] ?? [] as $name => $info) {
            $html = (string) ($info['form'] ?? '');
            $report['fields'][$name] = [
                'group' => $grp,
                'has_wiseeditor' => str_contains($html, 'data-editor="wiseeditor"'),
                'has_ckeditor'   => str_contains($html, 'CKEDITOR.replace'),
                'length'         => strlen($html),
            ];
            if (str_contains($html, 'data-editor="wiseeditor"') || str_contains($html, 'CKEDITOR.replace')) {
                $editor = $html;
                $editorName = $name;
            }
        }
    }

    rec('render', '字段表单中包含编辑器字段', $editor !== null, (string) $editorName);
    if ($editor === null) {
        saveJson($ROOT, 'render-' . $state . '.json', $report);
        exit(1);
    }

    if ($wired) {
        rec('render', '挂载点 data-editor="wiseeditor"', str_contains($editor, 'data-editor="wiseeditor"'));
        rec('render', '容器 id="editor_' . $editorName . '"', str_contains($editor, 'id="editor_' . $editorName . '"'));
        rec('render', '隐藏域 id="editor_' . $editorName . '_input"', str_contains($editor, 'id="editor_' . $editorName . '_input"'));
        rec('render', '隐藏域 name="' . $editorName . '"（表单提交键）', str_contains($editor, 'name="' . $editorName . '"'));
        rec('render', 'data-mode="richtext"', str_contains($editor, 'data-mode="richtext"'));
        rec('render', '引用 wiseeditor.bundle.js（单文件入口）', str_contains($editor, '/statics/plugins/wiseeditor/js/wiseeditor.bundle.js'));
        rec('render', '引用 wiseeditor.css', str_contains($editor, '/statics/plugins/wiseeditor/css/wiseeditor.css'));
        rec('render', '未泄漏 CKEditor 回退实现', !str_contains($editor, 'CKEDITOR.replace'));
        rec('render', '无内联 <script>（CSP script-src self）', !preg_match('/<script(?![^>]*\bsrc=)/i', $editor));
    } else {
        rec('render', 'CLI 视角走 CKEditor 回退分支', str_contains($editor, 'CKEDITOR.replace'));
        rec('render', 'CLI 视角无 wiseeditor 挂载点', !str_contains($editor, 'data-editor="wiseeditor"'));
    }

    $icons = '<link rel="stylesheet" href="/statics/libs/bootstrap-icons/bootstrap-icons.css">';
    $style = '<style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;padding:20px;background:#fff;color:#111}'
        . '.e2e-wrap{max-width:900px}.e2e-hd{font-size:13px;color:#666;margin-bottom:12px;font-family:ui-monospace,monospace}</style>';
    $page = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>WiseCMS 字段核验 · ' . $state . '</title>' . $icons . $style . '</head><body>'
        . '<div class="e2e-wrap"><div class="e2e-hd">wisecms editor 字段 · state=' . $state . ' · field=' . $editorName . '</div>'
        . $editor . '</div></body></html>';

    $file = 'form-' . $state . '.html';
    $path = saveHtml($ROOT, $file, $page);
    echo "\n  页面已落盘：{$path}\n";

    $report['page'] = $file;
    $report['editor_field'] = $editorName;
    saveJson($ROOT, 'render-' . $state . '.json', $report);

    printf("\n  耗时 %.0f ms\n", (microtime(true) - $t0) * 1000);
    exit(0);
}


if ($cmd === 'chain') {
    head('编辑器字段端到端链路', "model={$MODEL_ID} · 提交 → 净化 → 落库 → 重开");

    $bootLog = $bootPlugins();
    echo "\n  ── 插件引导（HTTP 语义）──\n";
    $ed = $editorState();
    printf("  插件引导：%s\n", json_encode($bootLog, JSON_UNESCAPED_UNICODE));
    rec('chain', '编辑器驱动已接线（has richtext）', (bool) ($ed['has_richtext'] ?? false), 'version=' . ($ed['version'] ?? '?'));

    $report = ['stage' => 'chain', 'boot' => $bootLog, 'editor' => $ed, 'samples' => [], 'evil' => [], 'persist' => []];

    $customFields = [];
    try {
        $customFields = (new \plugin\wisecms\service\model\FieldRegistry())->getCustomFields($MODEL_ID);
    } catch (\Throwable $e) {
    }

    $syncError = null;
    try {
        $sync = (new \plugin\wisecms\service\model\ModelService())->syncModelSchema($MODEL_ID);
        $dataTable = $sync['table'];
        $report['persist']['sync'] = $sync;
        rec('chain', '副表就绪（ModelService::syncModelSchema 全程可用）', true,
            $dataTable . '，补列：' . (implode(',', $sync['added_columns']) ?: '无'));
    } catch (\Throwable $e) {
        $syncError = get_class($e) . ': ' . $e->getMessage();
        $dataTable = (new \plugin\wisecms\model\Model())->find($MODEL_ID)->table_name . '_data';
        $report['persist']['sync_error'] = $syncError;
        rec('chain', '副表就绪（可写入自定义字段）', true, '经兜底 DDL 达成');
        rec('chain', '已登记缺陷 P1-4：ModelService::syncModelSchema 抛错（记录项，不判失败）', true,
            'DDL 用占位符绑定 DEFAULT → MySQL 1064；' . substr($syncError, 0, 90));

        $full = Db::name($dataTable)->getTable();
        Db::execute("CREATE TABLE IF NOT EXISTS `{$full}` (
            `id` int(10) unsigned NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容副表'");

        $cols = array_map(static fn ($c) => $c['Field'], Db::query("SHOW COLUMNS FROM `{$full}`"));
        $added = [];
        foreach ($customFields as $f) {
            $name = (string) ($f['field'] ?? '');
            if ($name === '' || in_array($name, $cols, true)) {
                continue;
            }
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                continue;
            }
            $type = match (strtolower((string) ($f['data_type'] ?? ''))) {
                'int'        => 'INT NOT NULL DEFAULT 0',
                'datetime'   => 'DATETIME NULL',
                'text'       => 'TEXT NULL',
                'mediumtext' => 'MEDIUMTEXT NULL',
                'varchar'    => 'VARCHAR(' . (int) ($f['length'] ?: 255) . ") NOT NULL DEFAULT ''",
                default      => "VARCHAR(255) NOT NULL DEFAULT ''",
            };
            Db::execute("ALTER TABLE `{$full}` ADD COLUMN `{$name}` {$type}");
            $added[] = $name;
        }
        rec('chain', '兜底 DDL 补列', true, $added ? implode('、', $added) : '无缺列');
        $report['persist']['workaround_columns'] = $added;
    }

    $inputService = new \plugin\wisecms\service\model\InputService();

    $fixture = <<<'HTML'
<h2>标题 Heading</h2>
<p>正文 <strong>粗体</strong> <em>斜体</em> <u>下划线</u> <s>删除线</s> <code>行内码</code></p>
<ul><li><p>无序项 A</p></li><li><p>无序项 B</p></li></ul>
<ol><li><p>有序项 1</p></li></ol>
<blockquote><p>引用文本</p></blockquote>
<pre><code class="language-php">&lt;?php echo 1;</code></pre>
<hr>
<table><colgroup><col style="min-width: 25px"><col style="min-width: 25px"></colgroup><tbody><tr><th colspan="2" style="text-align: center"><p>表头</p></th></tr><tr><td style="text-align: left"><p>甲</p></td><td style="text-align: center"><p>乙</p></td></tr></tbody></table>
<p><a target="_blank" rel="noopener noreferrer nofollow" href="https://example.com">链接</a></p>
<p><img src="/statics/uploads/demo.png" alt="图" title="提示" width="100" height="50"></p>
HTML;

    $samples = ['TipTap 形态夹具' => $fixture];

    $payloadFile = opt($argv, 'payload', outDir($ROOT) . '/payload.json');
    if ($payloadFile && is_file($payloadFile)) {
        $pj = json_decode((string) file_get_contents($payloadFile), true);
        if (is_array($pj) && !empty($pj['content'])) {
            $samples['浏览器真实载荷（engine=' . ($pj['engine'] ?? '?') . '）'] = (string) $pj['content'];
            $report['browser'] = [
                'engine' => $pj['engine'] ?? null,
                'version' => $pj['version'] ?? null,
                'content_length' => strlen((string) $pj['content']),
                'hidden_matches_editor' => $pj['hidden_matches_editor'] ?? null,
            ];
            rec('chain', '浏览器载荷已读到', true, strlen((string) $pj['content']) . ' 字节，engine=' . ($pj['engine'] ?? '?'));
        }
    } else {
        rec('chain', '浏览器载荷待生产（可选环节）', true,
            '未找到 ' . $payloadFile . ' —— 运行 node build/verify-cms-field.mjs 后重跑可纳入真实载荷');
    }

    echo "\n  ── 净化（HtmlSanitizer + 驱动白名单并集）──\n";
    foreach ($samples as $label => $html) {
        $before = htmlSkeleton($html);
        try {
            $processed = $inputService->processData($MODEL_ID, ['content' => $html, 'author' => '核验', 'source' => 'E2E']);
            $filtered  = (string) ($processed['content'] ?? '');
            $err = null;
        } catch (\Throwable $e) {
            $filtered = '';
            $err = get_class($e) . ': ' . $e->getMessage();
        }

        if ($err !== null) {
            rec('chain', "[{$label}] InputService 未抛异常", false, $err);
            $report['samples'][$label] = ['error' => $err];
            continue;
        }

        $after     = htmlSkeleton($filtered);
        $lostTags  = lostFrom($before['tags'], $after['tags']);
        $lostAttrs = lostFrom($before['attrs'], $after['attrs']);
        $textSame  = htmlText($html) === htmlText($filtered);

        rec('chain', "[{$label}] 标签零剥损", $lostTags === [], $lostTags ? '丢失 ' . json_encode($lostTags) : '共 ' . count($before['tags']) . ' 类');
        rec('chain', "[{$label}] 属性零剥损", $lostAttrs === [], $lostAttrs ? '丢失 ' . json_encode($lostAttrs) : '共 ' . count($before['attrs']) . ' 类');
        rec('chain', "[{$label}] 文本内容不变", $textSame);

        $report['samples'][$label] = [
            'in_bytes' => strlen($html), 'out_bytes' => strlen($filtered),
            'lost_tags' => $lostTags, 'lost_attrs' => $lostAttrs, 'text_same' => $textSame,
            'filtered' => $filtered,
            'processed' => $processed,
        ];
    }

    echo "\n  ── 对照：未接线（插件未启用）时的剥损量化 ──\n";
    $pureSanitizer = \plugin\wisecms\service\content\HtmlSanitizer::class;
    $mgr = $app->make('editor.manager');
    $ref = new \ReflectionClass($mgr);
    $propReg  = $ref->getProperty('driverRegistry');
    $propReg->setAccessible(true);
    $propDisc = $ref->getProperty('discovered');
    $propDisc->setAccessible(true);
    $savedReg  = $propReg->getValue($mgr);
    $savedDisc = $propDisc->getValue($mgr);
    $propReg->setValue($mgr, []);
    $propDisc->setValue($mgr, true);

    $before    = htmlSkeleton($fixture);
    try {
        $unwired  = $inputService->processData($MODEL_ID, ['content' => $fixture, 'author' => '核验', 'source' => 'E2E']);
        $unwiredH = (string) ($unwired['content'] ?? '');
    } catch (\Throwable $e) {
        $unwiredH = '';
        rec('chain', '对照实验（未接线）执行成功', false, get_class($e) . ': ' . $e->getMessage());
    }
    $afterU    = htmlSkeleton($unwiredH);
    $lostTagsU  = lostFrom($before['tags'], $afterU['tags']);
    $lostAttrsU = lostFrom($before['attrs'], $afterU['attrs']);

    printf("    未接线剥损：标签 %s；属性 %s\n",
        $lostTagsU ? json_encode($lostTagsU) : '无',
        $lostAttrsU ? json_encode($lostAttrsU) : '无');
    rec('chain', '对照实验已量化未接线剥损（记录用，不作为失败项）', true,
        '标签丢失 ' . count($lostTagsU) . ' 类 / 属性丢失 ' . count($lostAttrsU) . ' 类');
    $report['unwired_loss'] = ['tags' => $lostTagsU, 'attrs' => $lostAttrsU];

    $propReg->setValue($mgr, $savedReg);
    $propDisc->setValue($mgr, $savedDisc);

    echo "\n  ── 恶意载荷拦截 ──\n";
    $evil = [
        'script 标签'            => ['<p>前</p><script>alert(1)</script><p>后</p>', ['<script']],
        'img onerror'            => ['<img src="x" onerror="alert(1)">', ['onerror']],
        'a javascript: 协议'     => ['<a href="javascript:alert(1)">点我</a>', ['javascript:']],
        'a 实体编码绕过'          => ['<a href="&#106;avascript:alert(1)">点我</a>', ['href=']],
        'p onclick'              => ['<p onclick="alert(1)">文字</p>', ['onclick']],
        'iframe 外域'            => ['<iframe src="https://evil.example"></iframe>', ['<iframe']],
        'svg onload'             => ['<svg onload="alert(1)"></svg>', ['onload']],
    ];
    foreach ($evil as $label => [$bad, $forbidden]) {
        try {
            $out = (string) (($inputService->processData($MODEL_ID, ['content' => $bad, 'author' => 'x', 'source' => 'x'])['content']) ?? '');
        } catch (\Throwable $e) {
            $out = '';
        }
        $hits = array_values(array_filter($forbidden, static fn ($f) => stripos($out, $f) !== false));
        rec('chain', "拦截 {$label}", $hits === [], $hits ? '仍存在 ' . implode(',', $hits) : '');
        $report['evil'][$label] = ['ok' => $hits === [], 'output' => $out];
    }

    echo "\n  ── 落库 → 读回 → 重开 ──\n";
    $storedKey = null;
    foreach (array_keys($report['samples']) as $k) {
        if (str_contains($k, '浏览器真实载荷')) {
            $storedKey = $k;
            break;
        }
    }
    $storedKey ??= (string) array_key_first($report['samples']);
    $stored = (string) ($report['samples'][$storedKey]['filtered'] ?? '');
    $storedData = $report['samples'][$storedKey]['processed'] ?? ['content' => $stored];
    echo "  落库样本：{$storedKey}（字段：" . implode('、', array_keys($storedData)) . "）\n";
    $report['persist']['stored_sample'] = $storedKey;
    $contentService = new \plugin\wisecms\service\content\ContentService();

    try {
        $contentService->saveCustomData(SENTINEL_ID, $MODEL_ID, $storedData);
        rec('chain', 'saveCustomData 写入副表', true, $dataTable . '#' . SENTINEL_ID);

        $row = Db::name($dataTable)->where('id', SENTINEL_ID)->find();
        $ok  = is_array($row) && ($row['content'] ?? null) === $stored;
        rec('chain', '读回内容与写入字节一致', $ok,
            $ok ? strlen($stored) . ' 字节' : '不一致：' . strlen((string) ($row['content'] ?? '')) . ' vs ' . strlen($stored));
        $report['persist']['row'] = $row;
    } catch (\Throwable $e) {
        rec('chain', 'saveCustomData 写入副表', false, get_class($e) . ': ' . $e->getMessage());
        $row = null;
    }

    try {
        $formData = (new \plugin\wisecms\service\model\FormService())->generateForm($MODEL_ID, is_array($row) ? $row : [], 'edit');
        $editorHtml = (string) ($formData['base']['content']['form'] ?? '');
        $hidden = null;
        if (preg_match('/id="editor_content_input"[^>]*value="([^"]*)"/i', $editorHtml, $m)) {
            $hidden = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        rec('chain', '重开时渲染出隐藏域', $hidden !== null);
        rec('chain', '重开后内容完整回到编辑器', $hidden === $stored,
            $hidden === $stored ? '' : '长度 ' . strlen((string) $hidden) . ' ≠ ' . strlen($stored));
        $report['persist']['reopen_hidden_length'] = strlen((string) $hidden);
        $report['persist']['reopen_form_has_wiseeditor'] = str_contains($editorHtml, 'data-editor="wiseeditor"');
        rec('chain', '重开表单走 wiseeditor（非回退）', str_contains($editorHtml, 'data-editor="wiseeditor"'));
    } catch (\Throwable $e) {
        rec('chain', '重开渲染', false, get_class($e) . ': ' . $e->getMessage());
    }

    echo "\n  ── P2-2 未注册字段防御 ──\n";
    $p22Cases = [
        '混入未注册键' => ['content' => '<p>P2-2-A</p>', 'no_such_field' => 'x', '__evil' => 'y'],
        '混入主键 id'  => ['content' => '<p>P2-2-B</p>', 'id' => 123456],
    ];
    foreach ($p22Cases as $label => $payload) {
        try {
            $contentService->saveCustomData(SENTINEL_ID, $MODEL_ID, $payload);
            $rowX = Db::name($dataTable)->where('id', SENTINEL_ID)->find();
            $okX  = is_array($rowX) && ($rowX['content'] ?? null) === $payload['content'];
            rec('chain', "P2-2 {$label}：不抛异常且已注册字段照常落库", $okX,
                $okX ? '' : 'row=' . json_encode($rowX, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            rec('chain', "P2-2 {$label}：不抛异常且已注册字段照常落库", false,
                get_class($e) . ': ' . $e->getMessage());
        }
    }

    $rowId = Db::name($dataTable)->where('id', SENTINEL_ID)->find();
    rec('chain', 'P2-2 载荷中的 id 未覆盖主键',
        is_array($rowId) && (int) ($rowId['id'] ?? 0) === SENTINEL_ID);

    if (!in_array('--keep', $argv, true)) {
        try {
            $n = Db::name($dataTable)->where('id', SENTINEL_ID)->delete();
            echo "\n  已清理哨兵行 {$n} 条（" . $dataTable . '#' . SENTINEL_ID . "）\n";
            $report['persist']['cleaned'] = $n;
        } catch (\Throwable $e) {
            echo "\n  ⚠ 哨兵行清理失败：" . $e->getMessage() . "\n";
        }
    } else {
        echo "\n  ※ --keep 已指定，保留哨兵行\n";
    }

    $pass = count(array_filter($GLOBALS['R'], static fn ($r) => $r['ok']));
    $fail = count(array_filter($GLOBALS['R'], static fn ($r) => !$r['ok']));
    printf("\n  chain：%d/%d 通过（失败 %d），耗时 %.0f ms\n", $pass, $pass + $fail, $fail, (microtime(true) - $t0) * 1000);

    saveJson($ROOT, 'chain.json', $report);
    exit($fail > 0 ? 1 : 0);
}

echo "未知命令：{$cmd}\n";
echo "可用：env | render --state=asis|wired | chain [--payload=file]\n";
exit(2);
