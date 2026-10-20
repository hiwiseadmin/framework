<?php
declare(strict_types=1);

namespace wise\crud;

use think\facade\Db;

/**
 * TableIntrospector — 读取表结构，归一化为推导引擎可消费的列定义。
 *
 * 只做"读表 + 归一化"，**不做任何类型判断**（那是 FieldTypeResolver 的职责），
 * 因此本类可被替身注入，便于无库环境下的测试。
 */
final class TableIntrospector
{
    /**
     * 读取表的所有列。
     *
     * @param string $table  完整表名（含前缀）
     * @param string $connection 数据库连接名
     * @return array<int, array<string, mixed>> 归一化列定义
     *
     * @throws \RuntimeException 表不存在或无列时
     */
    public function columns(string $table, string $connection = 'mysql'): array
    {
        $rows = Db::connect($connection)->query(sprintf(
            'SHOW FULL COLUMNS FROM `%s`',
            str_replace('`', '', $table)
        ));

        if ($rows === []) {
            throw new \RuntimeException(sprintf('表 [%s] 不存在或无可读列', $table));
        }

        $columns = [];

        foreach ($rows as $row) {
            $columns[] = $this->normalize($row);
        }

        return $columns;
    }

    /**
     * 表注释（用于默认菜单标题）。
     */
    public function tableComment(string $table, string $connection = 'mysql'): string
    {
        $row = Db::connect($connection)->query(sprintf(
            'SHOW TABLE STATUS LIKE %s',
            "'" . str_replace("'", '', $table) . "'"
        ));

        return (string) ($row[0]['Comment'] ?? '');
    }

    /**
     * MySQL `SHOW FULL COLUMNS` 的一行 → 统一结构。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $columnType = strtolower((string) ($row['Type'] ?? ''));

        // "int(11) unsigned" → dbType = int；"enum('a','b')" → dbType = enum
        $dbType = preg_replace('/^([a-z]+).*$/s', '$1', $columnType) ?? $columnType;

        return [
            'name'        => (string) ($row['Field'] ?? ''),
            'db_type'     => $dbType,
            'column_type' => $columnType,
            'nullable'    => strtoupper((string) ($row['Null'] ?? 'YES')) === 'YES',
            'default'     => $row['Default'] ?? null,
            'comment'     => (string) ($row['Comment'] ?? ''),
            'is_primary'  => strtoupper((string) ($row['Key'] ?? '')) === 'PRI',
            'is_unsigned' => str_contains($columnType, 'unsigned'),
            'is_auto'     => str_contains(strtolower((string) ($row['Extra'] ?? '')), 'auto_increment'),
            'enum_values' => $this->parseEnumValues($columnType),
        ];
    }

    /**
     * enum/set 的候选值（供选项回退）。
     *
     * @return array<int, string>
     */
    private function parseEnumValues(string $columnType): array
    {
        if (preg_match('/^(?:enum|set)\((.*)\)$/', $columnType, $matches) !== 1) {
            return [];
        }

        $values = str_getcsv(str_replace("'", '', $matches[1]), ',', "'", '\\');

        return array_values(array_filter(array_map('trim', $values), static fn (string $v): bool => $v !== ''));
    }
}
