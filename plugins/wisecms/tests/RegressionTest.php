<?php

declare(strict_types=1);

/**
 * WiseCMS 回归测试（PR1~PR3 改动守护）
 *
 * 零依赖（无需 PHPUnit / ThinkPHP 应用引导 / 数据库），直接运行：
 *   php src/plugins/wisecms/tests/RegressionTest.php
 *
 * 覆盖纯逻辑改动：
 *   - cms_log()            日志兜底：缺包/无 app 时也不外抛
 *   - CrontabService       内置任务结构（wise-crontab 规范）
 *   - SearchService        highlight 转义 + 高亮包裹
 *   - ContentWorkflow      place/status 双向映射（wise-workflow 接入）
 *   - 源码头守卫          错误类名不得回归（class_exists/app 使用层面）
 */

// ---------- 自动加载 plugin\wisecms ----------
spl_autoload_register(static function (string $class): void {
    $prefix = 'plugin\\wisecms\\';
    if (str_starts_with($class, $prefix)) {
        $rel  = substr($class, strlen($prefix));
        $path = __DIR__ . '/../' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
});

// ---------- 断言工具 ----------
$GLOBALS['_pass'] = 0;
$GLOBALS['_fail'] = 0;
$GLOBALS['_failures'] = [];

function check(string $name, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['_pass']++;
        echo "  [PASS] {$name}\n";
    } else {
        $GLOBALS['_fail']++;
        $GLOBALS['_failures'][] = $name . ($detail !== '' ? " — {$detail}" : '');
        echo "  [FAIL] {$name}\n";
    }
}

function callProtected(object $obj, string $method, array $args = [])
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($obj, $args);
}

echo "==============================================================\n";
echo " WiseCMS 回归自检（PR1~PR3）\n";
echo ' PHP ' . PHP_VERSION . '   entry: ' . basename(__FILE__) . "\n";
echo "==============================================================\n\n";

// ===================== cms_log 兜底 =====================

require_once __DIR__ . '/../common.php';

check('cms_log 函数已定义', function_exists('cms_log'));

$threw = false;
try {
    // 未引导 ThinkPHP 应用、且 wise-support 不可用时，cms_log 也应静默（内部吞掉 Log 解析异常）
    cms_log('error', 'probe');
} catch (\Throwable $e) {
    $threw = true;
}
check('cms_log 在缺包/无 app 环境下不外抛', !$threw, $threw ? 'threw' : '');

// ===================== CrontabService 内置任务 =====================

$crontab = new \plugin\wisecms\service\engine\CrontabService();
$tasks   = $crontab->getBuiltinTasks();

check('内置任务数量为 6', count($tasks) === 6, (string) count($tasks));

$requiredKeys = ['name', 'description', 'command', 'cron_expression', 'timeout', 'max_retries', 'allow_concurrent', 'status'];
$schemaOk = true;
$commandOk = true;
$cronOk = true;
foreach ($tasks as $task) {
    foreach ($requiredKeys as $k) {
        if (!array_key_exists($k, $task)) {
            $schemaOk = false;
        }
    }
    if (!str_starts_with((string) $task['command'], 'php think wisecms:')) {
        $commandOk = false;
    }
    if (!preg_match('/^(\S+\s+){4}\S+$/', (string) $task['cron_expression'])) {
        $cronOk = false;
    }
}
check('任务字段齐全（wise-crontab 规范）', $schemaOk);
check('command 均为 php think wisecms:*', $commandOk);
check('cron_expression 为 5 段 Cron', $cronOk);

$suggested = $crontab->getSuggestedTasks();
$suggestOk = count($suggested) === 6;
foreach ($suggested as $s) {
    if (str_contains((string) $s['command'], 'php think ')) {
        $suggestOk = false;
    }
}
check('getSuggestedTasks 结构兼容（去掉 php think 前缀）', $suggestOk);

// ===================== SearchService::highlight =====================

$search = (new ReflectionClass(\plugin\wisecms\service\content\SearchService::class))->newInstanceWithoutConstructor();

$escaped = callProtected($search, 'highlight', ['<script>alert(1)</script> 关键词', '关键词']);
check('highlight 先转义原文（阻断 XSS）', !str_contains($escaped, '<script>') && str_contains($escaped, '&lt;script&gt;'));
check('highlight 包裹 <em class="highlight">', str_contains($escaped, '<em class="highlight">关键词</em>'));

$noInjection = callProtected($search, 'highlight', ['a" onmouseover="x', '" onmouseover']);
check('highlight 关键词转义后不注入属性', !str_contains($noInjection, 'onmouseover="x"'));

// ===================== ContentWorkflow 映射 =====================

$cw = \plugin\wisecms\service\engine\ContentWorkflow::class;

check('subject 形如 content:{id}', \plugin\wisecms\service\engine\ContentWorkflow::subject(42) === 'content:42');
check('status 2 → published', $cw::placeForStatus(2) === 'published');
check('status 3 → archived', $cw::placeForStatus(3) === 'archived');
check('status 1 → pending', $cw::placeForStatus(1) === 'pending');
check('status 0 → draft', $cw::placeForStatus(0) === 'draft');

check('published → status 2', $cw::statusForPlace('published') === 2);
check('approved → status 1', $cw::statusForPlace('approved') === 1);
check('rejected → status 0', $cw::statusForPlace('rejected') === 0);
check('archived → status 3', $cw::statusForPlace('archived') === 3);
check('未知 place 回落 draft', $cw::statusForPlace('nope') === 0);

// ===================== 错误类名守卫（防止回归） =====================

$root     = __DIR__ . '/../';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$wrongPatterns = [
    'class_exists(\\wise\\agent\\AgentManager::class)' => '应使用 wise\\agent\\agent\\AgentManager',
    'app(\\wise\\crontab\\Service'                    => '应使用 wise\\crontab\\CrontabManager',
    'app(\\wise\\image\\Service'                      => '应使用 wise\\image\\ImageManager / wise_image()',
    'app(\\wise\\attachment\\Service'                 => '应使用 wise\\attachment\\factory\\AttachmentStore',
    'app(\\wise\\notification\\Service'               => '应使用 wise\\notification\\facade\\Notification',
];

$violations = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $content = (string) file_get_contents($file->getPathname());
    foreach ($wrongPatterns as $pattern => $desc) {
        if (str_contains($content, $pattern)) {
            $violations[] = $file->getFilename() . ': ' . $desc;
        }
    }
}
check('错误类名（旧假 API）未回归', count($violations) === 0, implode('; ', $violations));

// ===================== 汇总 =====================

echo "\n--------------------------------------------------------------\n";
echo "通过 {$GLOBALS['_pass']} 项，失败 {$GLOBALS['_fail']} 项\n";
if ($GLOBALS['_failures'] !== []) {
    echo "\n失败明细：\n";
    foreach ($GLOBALS['_failures'] as $msg) {
        echo "  - {$msg}\n";
    }
}
echo "--------------------------------------------------------------\n";

if ($GLOBALS['_fail'] > 0 || $GLOBALS['_pass'] === 0) {
    echo "回归自检未通过。\n";
    exit(1);
}

echo "回归自检通过。\n";
exit(0);
