<?php
/**
 * ============================================================
 * CRUD 声明契约自检（`config/crud_map.php`）
 * ============================================================
 *
 * 为什么需要：声明文件是「人写的」，而生成器信任它。一旦写错（字段名拼错、
 * 目标表改名、组件类型写错），错误会在生成时以 `InvalidArgumentException`
 * 或更糟的静默默认值出现。这里把它提前到「提交前」。
 *
 * 校验项：
 *   1. 结构合法 —— 未知键、缺失必填、类型不对，一律失败（不静默忽略）
 *   2. 表/字段存在 —— 声明的表与字段必须真实存在于数据库
 *   3. 组件已注册 —— 声明的 type 必须能映射到具体表单字段类（不是兜底 Text）
 *   4. 关联合法 —— 目标表存在、值列与显示列存在
 *
 * 无数据库时：**降级为 SKIP 并以 0 退出**（结构与类型校验仍会执行）。
 * 这样 CI 无需数据库也能守住「声明文件本身是合法的」这条底线。
 *
 * 用法：`php build/verify-crud-map.php`（或 `npm run verify:crud-map`）
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use wise\crud\CrudGenerator;
use wise\crud\CrudMap;
use wise\crud\TableIntrospector;

$failures = 0;
$skipped  = 0;

/**
 * @param array<int, string> $details
 */
function result(bool $ok, string $label, string $detail = ''): void
{
    global $failures;

    if ($ok) {
        printf("[PASS] %s%s\n", $label, $detail !== '' ? ' :: ' . $detail : '');

        return;
    }

    $failures++;
    printf("[FAIL] %s%s\n", $label, $detail !== '' ? ' :: ' . $detail : '');
}

echo "[verify-crud-map] CRUD 声明契约\n";

$configFile = __DIR__ . '/../config/crud_map.php';

if (!is_file($configFile)) {
    echo "[SKIP] config/crud_map.php 不存在（未使用声明文件）\n";
    exit(0);
}

$raw = require $configFile;

if (!is_array($raw)) {
    result(false, '声明文件返回值必须是数组', get_debug_type($raw));
    exit(1);
}

try {
    $map = new CrudMap($raw);
    result(true, '结构合法', sprintf('%d 张表已声明', count($map->tables())));
} catch (Throwable $e) {
    result(false, '结构非法', $e->getMessage());
    exit(1);
}

if (!$map->isEnabled()) {
    echo "[SKIP] __enabled=false，声明文件当前不生效\n";
    exit(0);
}

$generator    = new CrudGenerator();
$typeFailures = [];

foreach ($map->tables() as $table) {
    $spec = $map->spec($table);

    foreach ((array) ($spec['fields'] ?? []) as $field => $override) {
        if (!isset($override['type'])) {
            continue;
        }

        $type  = (string) $override['type'];
        $class = $generator->fieldClass($type);

        if ($class === 'Text' && $type !== 'text') {
            $typeFailures[] = sprintf('%s.%s → %s', $table, $field, $type);
        }
    }
}

result($typeFailures === [], '组件类型已注册', implode(', ', $typeFailures));

try {
    $app = new think\App();
    $app->initialize();

    $introspector = new TableIntrospector();
} catch (Throwable $e) {
    echo "[SKIP] 无法初始化应用/数据库：" . $e->getMessage() . "\n";
    exit($failures === 0 ? 0 : 1);
}

$tableFailures    = [];
$fieldFailures    = [];
$relationFailures = [];

foreach ($map->tables() as $table) {
    try {
        $columns = $introspector->columns($table);
    } catch (Throwable $e) {
        $tableFailures[] = $table . '（' . $e->getMessage() . '）';
        continue;
    }

    $names = array_map(static fn (array $column): string => (string) $column['name'], $columns);
    $spec  = $map->spec($table);

    foreach (array_merge(array_keys((array) ($spec['fields'] ?? [])), (array) ($spec['ignore'] ?? [])) as $field) {
        if (!in_array((string) $field, $names, true)) {
            $fieldFailures[] = sprintf('%s.%s', $table, (string) $field);
        }
    }

    foreach ((array) ($spec['relations'] ?? []) as $field => $relation) {
        try {
            $targetColumns = $introspector->columns((string) $relation['table']);
        } catch (Throwable $e) {
            $relationFailures[] = sprintf('%s.%s → 目标表 %s 不可读', $table, (string) $field, (string) $relation['table']);
            continue;
        }

        $targetNames = array_map(static fn (array $column): string => (string) $column['name'], $targetColumns);

        foreach (['value', 'label'] as $part) {
            if (!in_array((string) $relation[$part], $targetNames, true)) {
                $relationFailures[] = sprintf(
                    '%s.%s → %s.%s 列不存在',
                    $table,
                    (string) $field,
                    (string) $relation['table'],
                    (string) $relation[$part]
                );
            }
        }
    }
}

result($tableFailures === [], '声明的表存在', implode(', ', $tableFailures));
result($fieldFailures === [], '声明的字段存在', implode(', ', $fieldFailures));
result($relationFailures === [], '关联目标合法', implode(', ', $relationFailures));

if ($failures > 0) {
    printf("\n[verify-crud-map] 失败 %d 项\n", $failures);
    exit(1);
}

echo "[verify-crud-map] 全部通过\n";
exit(0);
