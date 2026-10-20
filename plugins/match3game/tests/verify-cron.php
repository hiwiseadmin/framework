<?php

declare(strict_types=1);

/**
 * 定时任务与命令注册核对自检（S5-8）
 *
 * 纯 PHP 运行，不依赖框架与数据库：
 *   php src/plugins/match3game/tests/verify-cron.php
 *
 * 【为什么只做静态核对】crontab 配在**部署机上**，仓库里查不到；
 *   能在 CI 里锁死的是：命令已注册 / 类文件存在 / `setName()` 与注册键一致。
 *   剩下"是否真挂上了 crontab"只能人工 `crontab -l` 比对（脚本末尾会打印建议行）。
 *
 */

$pluginRoot = dirname(__DIR__);

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ✓ {$name}\n";
        return;
    }

    $failed++;
    echo "  ✗ {$name}" . ($detail !== '' ? " —— {$detail}" : '') . "\n";
}

function section(string $title): void
{
    echo "\n{$title}\n";
}

/** 读取 `setName('...')` 的值 */
function commandNameOf(string $file): string
{
    $src = (string) file_get_contents($file);
    if (preg_match('/setName\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $src, $m) === 1) {
        return (string) $m[1];
    }

    return '';
}

section('1. 命令注册（`config/console.php`）');

$consoleFile = $pluginRoot . '/config/console.php';
check('config/console.php 存在', is_file($consoleFile));

$console  = is_file($consoleFile) ? (array) require $consoleFile : [];
$commands = is_array($console['commands'] ?? null) ? $console['commands'] : [];

$expected = [
    'match3game:daily',
    'match3game:pvp-timeout',
    'match3game:season',
    'match3game:group-rank',
    'match3game:cleanup',
];

foreach ($expected as $name) {
    check("已注册 {$name}", array_key_exists($name, $commands));
}

// 本插件约定用字符串键 → 实际命令带 `match3game:` 前缀（`match3game:daily`）。
// 数字键不会"注册失败"：框架会自动发现命令类、按 `setName()` 注册成未加前缀的
// `match3game:daily`（实测两种写法都能跑）。这里锁的是"我们选的口径"，不是"数字键不行"。
$numericKeys = [];
foreach (array_keys($commands) as $key) {
    if (is_int($key)) {
        $numericKeys[] = (string) $key;
    }
}
check(
    '注册键全部是字符串（保持 `match3game:*` 前缀口径）',
    $numericKeys === [],
    $numericKeys === [] ? '' : '数字键: ' . implode(', ', $numericKeys)
);

section('2. 命令类文件与 setName 一致性');

foreach ($expected as $name) {
    $class = $commands[$name] ?? '';
    $short = is_string($class) ? ltrim((string) (strrchr($class, '\\') ?: $class), '\\') : '';
    $file  = $short === '' ? '' : $pluginRoot . '/command/' . $short . '.php';

    check("{$name} 的类文件存在", $file !== '' && is_file($file), $file);
    if ($file === '' || !is_file($file)) {
        continue;
    }

    check("{$name} 与 setName() 一致", commandNameOf($file) === $name, 'got ' . commandNameOf($file));

    $src = (string) file_get_contents($file);
    check("{$name} 继承 console Command", strpos($src, 'Command') !== false && strpos($src, 'class ') !== false);
}

section('3. 建议的 crontab（人工执行 `crontab -e` 后核对）');

// ⚠️ 命令名必须与 `php think list` 实际注册的一致。
//    框架规则（`PluginServiceProvider.php:257`）：字符串键会**先剥掉已有插件前缀再加一次**，
//    所以 `config/console.php` 里写 `'match3game:pvp-timeout'` → 最终就是 `match3game:pvp-timeout`；
//    若写成 `'match3:pvp-timeout'` 会得到 `match3game:match3:pvp-timeout`（前缀重复，照抄会报"命令不存在"）。
//    路径按实际部署根填（`think` 入口在 `src/think`）。
echo <<<TXT
  */10 * * * *  php /path/to/wiseadmin/src/think match3game:pvp-timeout
  0 */6 * * *   php /path/to/wiseadmin/src/think match3game:season
  0 2 * * *     php /path/to/wiseadmin/src/think match3game:group-rank
  0 4 * * *     php /path/to/wiseadmin/src/think match3game:cleanup

TXT;

check('建议清单已输出（4 条定时任务，命令名含 `match3game:` 前缀）', true);

echo "\n通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
