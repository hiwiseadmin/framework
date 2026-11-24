<?php
/**
 * ============================================================
 * 收口护栏（C-4）— 一条命令校验四类「已关闭、不允许再开」的口子
 * ============================================================
 *
 * 覆盖项＼�：
 *   1. globals    框架 / 核心包内不得直读超全局变量（C-2）
 *                 —— 常驻运行时下超全局是「上一个请求的残留」，必须走 RequestContext / Request
 *   2. backups    仓库内不得出现 `*.YYYYMMDD.N` 备份文件与 `plugin_backup_*` 目录（C-1）
 *   3. headers    禁止全局 `header()` / `header_remove()` / `http_response_code()` /
 *                 `setcookie()` / `setrawcookie()` 直写响应（C-3 / C-7）
 *                 —— think-swoole 下必须经 Response 写回协程响应
 *   4. toolgate   AI 工具执行不得绕过统一入口（B-2）
 *                 —— 直接 `$tool->call()` 会跳过授权、审批与审计
 *   5. aitools    AI 工具命名与注册必须满足 provider 契约（B-3）
 *                 —— 下发名匹配 `^[a-zA-Z0-9_-]+$` 且唯一、可反查；注册路径不得整段清命名空间
 *
 * 用法：
 *   php build/verify-guardrails.php                    # 全部
 *   php build/verify-guardrails.php --only=globals     # 只跑一项（逗号分隔多项）
 *
 * 退出码：0 全通过；1 存在违规（可直接用于 CI / 提交前钩子）
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$srcRoot  = dirname(__DIR__);
$repoRoot = dirname($srcRoot);

echo "[verify-guardrails] 收口护栏\n";

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
 * 递归收集 PHP 文件（跳过依赖与运行时目录，以及测试目录由调用方决定）
 *
 * @param string[] $roots
 * @param string[] $skipDirs
 * @return string[]
 */
function phpFiles(array $roots, array $skipDirs = []): array
{
    $skipDirs = array_merge(
        ['vendor', 'node_modules', 'runtime', '.git', '.tmp', 'statics', 'uploads'],
        $skipDirs
    );

    $files = [];

    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file) use ($skipDirs): bool {
                    if ($file->isDir()) {
                        return !in_array($file->getFilename(), $skipDirs, true);
                    }

                    return $file->getExtension() === 'php';
                }
            )
        );

        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * 抹掉注释与字符串字面量（等长空格替换，**保留换行与偏移**，便于回填行号）
 */
function stripNonCode(string $code): string
{
    $out = '';

    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                $out .= preg_replace('/[^\n]/', ' ', $token[1]);
                continue;
            }
            $out .= $token[1];
            continue;
        }

        $out .= $token;
    }

    return $out;
}

/**
 * 相对路径显示
 */
function relPath(string $path, string $base): string
{
    $path = str_replace('\\', '/', $path);
    $base = rtrim(str_replace('\\', '/', $base), '/');

    return str_starts_with($path, $base . '/') ? substr($path, strlen($base) + 1) : $path;
}

/**
 * 将「偏移量」转换为行号
 */
function lineOf(string $code, int $offset): int
{
    return substr_count(substr($code, 0, $offset), "\n") + 1;
}

/** 解析 --only 过滤 */
$only = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = array_values(array_filter(array_map('trim', explode(',', substr($arg, 7)))));
    }
}

$shouldRun = static fn(string $name): bool => $only === [] || in_array($name, $only, true);

if ($shouldRun('globals')) {
    $pattern = '/\$_(GET|POST|SERVER|COOKIE|SESSION|FILES|REQUEST|ENV)\b|\$GLOBALS\b/';
    $hits    = [];

    foreach (phpFiles([$srcRoot . '/app', $srcRoot . '/packages/wiseadmin']) as $file) {
        if (str_contains(str_replace('\\', '/', $file), '/tests/')) {
            continue;
        }

        if (basename($file) === 'composer-setup.php') {
            continue;
        }

        $code = stripNonCode((string) file_get_contents($file));

        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $match) {
                $hits[] = relPath($file, $srcRoot) . ':' . lineOf($code, (int) $match[1]);
            }
        }
    }

    check(
        '禁止超全局变量直读（app + packages）',
        $hits === [],
        $hits === [] ? '' : implode(', ', $hits)
    );
}

if ($shouldRun('backups')) {
    $badFiles = [];
    $badDirs  = [];
    $skipDirs = ['vendor', 'node_modules', 'runtime', '.git', '.tmp', 'uploads', 'dist', '.phpunit.cache'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file) use ($skipDirs): bool {
                return $file->isDir()
                    ? !in_array($file->getFilename(), $skipDirs, true)
                    : true;
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        $name = $file->getFilename();

        if ($file->isDir()) {
            if (str_starts_with($name, 'plugin_backup_')) {
                $badDirs[] = relPath($file->getPathname(), $repoRoot);
            }
            continue;
        }

        if (preg_match('/\.\d{8}\.\d+(\.[A-Za-z0-9]+)?$/', $name) === 1) {
            $badFiles[] = relPath($file->getPathname(), $repoRoot);
        }
    }

    check(
        '禁止 *.YYYYMMDD.N 备份文件',
        $badFiles === [],
        $badFiles === [] ? '' : implode(', ', array_slice($badFiles, 0, 8))
    );

    check(
        '禁止 plugin_backup_* 目录',
        $badDirs === [],
        $badDirs === [] ? '' : implode(', ', array_slice($badDirs, 0, 8))
    );
}

if ($shouldRun('headers')) {
    $allow = [
        'packages/wiseadmin/wise-agent/src/http/SseResponse.php' => ['header', 'http_response_code'],
        'app/middleware/SecurityHeaders.php'                     => ['header_remove'],
    ];

    $patterns = [
        'header'             => '/(?<![\w$>:])header\s*\(/',
        'header_remove'      => '/(?<![\w$>:])header_remove\s*\(/',
        'http_response_code' => '/(?<![\w$>:])http_response_code\s*\(/',
        'setcookie'          => '/(?<![\w$>:])setcookie\s*\(/',
        'setrawcookie'       => '/(?<![\w$>:])setrawcookie\s*\(/',
    ];

    $hits = [];

    foreach (phpFiles([$srcRoot . '/app', $srcRoot . '/packages/wiseadmin']) as $file) {
        if (str_contains(str_replace('\\', '/', $file), '/tests/')) {
            continue;
        }
        if (basename($file) === 'composer-setup.php') {
            continue;
        }

        $relative = relPath($file, $srcRoot);
        $allowed  = $allow[$relative] ?? [];

        $code = stripNonCode((string) file_get_contents($file));

        $code = (string) preg_replace('/function\s+&?\w+\s*\(/', 'function(', $code);

        foreach ($patterns as $fn => $pattern) {
            if (in_array($fn, $allowed, true)) {
                continue;
            }

            if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as $match) {
                    $hits[] = $relative . ':' . lineOf($code, (int) $match[1]) . ' ' . $fn . '()';
                }
            }
        }
    }

    check(
        '禁止直接写响应头 / 状态码 / Cookie（须经 Response）',
        $hits === [],
        $hits === [] ? '' : implode(', ', $hits)
    );
}

if ($shouldRun('toolgate')) {
    $roots      = [$srcRoot . '/packages/wiseadmin/wise-mcp/src', $srcRoot . '/packages/wiseadmin/wise-agent/src'];
    $pkgRoot    = $srcRoot . '/packages/wiseadmin';
    $allowFiles = [
        'wise-mcp/src/execution/HandlerToolInvoker.php',
        'wise-agent/src/mcp/AgentToolInvoker.php',
    ];

    $hits = [];

    foreach (phpFiles($roots) as $file) {
        $relative = relPath($file, $pkgRoot);

        if (in_array($relative, $allowFiles, true)) {
            continue;
        }

        $code = stripNonCode((string) file_get_contents($file));

        if (preg_match_all('/->\s*call\s*\(/', $code, $matches) > 0) {
            $hits[] = $relative . '（' . count($matches[0]) . ' 处）';
        }
    }

    check(
        '禁止绕过统一工具执行入口直接 $tool->call()',
        $hits === [],
        $hits === [] ? '' : implode(', ', $hits)
    );
}

if ($shouldRun('aitools')) {
    $agentSrc = $srcRoot . '/packages/wiseadmin/wise-agent/src';

    $allowRemoveNamespace = ['AgentServiceProvider.php', 'ToolRegistry.php'];
    $removeHits           = [];

    foreach (phpFiles([$agentSrc]) as $file) {
        if (in_array(basename($file), $allowRemoveNamespace, true)) {
            continue;
        }

        $code = stripNonCode((string) file_get_contents($file));

        if (str_contains($code, 'removeNamespace(')) {
            $removeHits[] = relPath($file, $srcRoot);
        }
    }

    check(
        '禁止在注册路径整段清除命名空间（B3-1）',
        $removeHits === [],
        $removeHits === [] ? '' : implode(', ', $removeHits)
    );

    $registryFile = $agentSrc . '/tool/ToolRegistry.php';
    $bodyLines    = [];
    $capturing    = false;

    foreach (explode("\n", stripNonCode((string) @file_get_contents($registryFile))) as $line) {
        if (!$capturing && str_contains($line, 'function getToolSchemas')) {
            $capturing  = true;
            $bodyLines[] = $line;
            continue;
        }

        if ($capturing) {
            $bodyLines[] = $line;
            if (preg_match('/^    \}/', $line) === 1) {
                break;
            }
        }
    }

    $body = implode("\n", $bodyLines);

    $schemaOk = $body !== ''
        && !str_contains($body, '->getName()')
        && substr_count($body, 'wireName(') >= 3;

    check(
        'getToolSchemas() 必须经 wireName() 下发',
        $schemaOk,
        $schemaOk ? '' : ($body === '' ? '未定位到 getToolSchemas() 方法体' : '仍存在 getName() 或缺少 wireName()')
    );

    $registry = \wise\agent\tool\ToolRegistry::newInstance();
    $tool     = new \wise\agent\tool\FakeEchoTool();

    $registry->register($tool, 'list_users', 'plugin:demo');
    $registry->register($tool, 'do.thing', 'mcp:server');
    $registry->register($tool, 'plain_tool');

    $schemas = $registry->getToolSchemas();
    $names   = array_map(static fn(array $s): string => (string) ($s['function']['name'] ?? ''), $schemas);

    $illegal = array_values(array_filter(
        $names,
        static fn(string $n): bool => preg_match('/^[a-zA-Z0-9_-]+$/', $n) !== 1
    ));

    check(
        '下发工具名匹配 ^[a-zA-Z0-9_-]+$',
        $illegal === [],
        $illegal === [] ? '' : implode(', ', $illegal)
    );

    check(
        '下发工具名唯一',
        count($names) === count(array_unique($names)),
        implode(', ', $names)
    );

    $unresolvable = array_values(array_filter(
        $names,
        static fn(string $n): bool => $registry->get($n) === null
    ));

    check(
        '下发工具名可反查到注册键',
        $unresolvable === [],
        $unresolvable === [] ? '' : implode(', ', $unresolvable)
    );
}

echo $fail === 0 ? "\n[verify-guardrails] 全部通过\n" : "\n[verify-guardrails] 失败 {$fail} 项\n";

exit($fail === 0 ? 0 : 1);
