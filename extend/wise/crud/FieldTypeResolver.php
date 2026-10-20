<?php
declare(strict_types=1);

namespace wise\crud;

/**
 * FieldTypeResolver — 字段类型推导引擎（命名 × DB 类型 二维，五级优先级）。
 *
 * 规范见 docs/wiseadmin-crud-field-mapping.md §3 / §4。
 *
 * 与 FastAdmin 的根本分歧（规范 §3.0）：
 *   **启发式只是"省事的默认值"，不是"事实来源"。**
 * 因此每条推导结果都携带 `layer / rule / fallback / declared`,
 * 由 `--dry-run` 输出，使判定**可解释、可审计**。
 *
 * 优先级（高命中即停）：
 *   P0 显式覆盖 → P1 精确匹配 → P2 前缀 → P3 后缀 → P4 DB 类型 → P5 默认
 *
 * 匹配语义（`matcherToken`）：
 *   $name === $token                     完整相等
 *   str_ends_with($name, '_' . $token)   下划线边界后缀
 * token 一律**不带前导下划线**，所以 `time` 命中 `create_time` 而**不**命中 `runtime`。
 */
final class FieldTypeResolver
{
    /** @param array<string, mixed> $rules config/crud.php 的内容 */
    public function __construct(private readonly array $rules)
    {
    }

    /**
     * 批量推导。
     *
     * @param array<int, array<string, mixed>> $columns TableIntrospector 的输出
     * @return array<int, array<string, mixed>> 推导结果列表（顺序与入参一致）
     */
    public function resolveAll(array $columns, string $table = ''): array
    {
        return array_map(fn (array $column): array => $this->resolve($column, $table), $columns);
    }

    /**
     * 推导单个字段。
     *
     * @param array<string, mixed> $column
     * @return array<string, mixed>
     */
    public function resolve(array $column, string $table = ''): array
    {
        $name        = (string) ($column['name'] ?? '');
        $dbType      = strtolower((string) ($column['db_type'] ?? ''));
        $columnType  = strtolower((string) ($column['column_type'] ?? $dbType));
        $comment     = (string) ($column['comment'] ?? '');
        $isPrimary   = (bool) ($column['is_primary'] ?? false);

        $options  = $this->deriveOptions($dbType, $columnType, $comment);
        $fallback = $this->fallbackType($dbType, $columnType);

        $result = [
            'name'        => $name,
            'db_type'     => $dbType,
            'column_type' => $columnType,
            'comment'     => $comment,
            'nullable'    => (bool) ($column['nullable'] ?? true),
            'default'     => $column['default'] ?? null,
            'is_primary'  => $isPrimary,
            'type'        => $fallback,
            'props'       => [],
            'options'     => $options,
            'multiple'    => false,
            'skip_form'   => false,
            'system'      => null,
            'layer'       => 'P5',
            'rule'        => '默认 text',
            'fallback'    => $fallback,
            'declared'    => false,
            'warnings'    => [],
        ];

        // ── P0 显式覆盖 ────────────────────────────────
        $override = $this->matchOverride($table, $name);

        if ($override !== null) {
            return $this->applyOverride($result, $override);
        }

        // ── P1 主键 / 系统字段 ─────────────────────────
        if ($isPrimary || in_array($name, (array) ($this->rules['primary_key'] ?? []), true)) {
            return $this->markSystem($result, 'primary', 'P1', '精确=主键');
        }

        $systemFields = (array) ($this->rules['system_fields'] ?? []);

        if (isset($systemFields[$name])) {
            return $this->markSystem(
                $result,
                (string) $systemFields[$name],
                'P1',
                sprintf('系统字段白名单（%s）', $name)
            );
        }

        // ── P1 语义字段精确匹配 ─────────────────────────
        $exact = (array) ($this->rules['exact'] ?? []);

        if (isset($exact[$name]) && is_array($exact[$name])) {
            return $this->applyExact($result, $name, $exact[$name]);
        }

        // ── P2 前缀 → switch ──────────────────────────
        foreach ((array) ($this->rules['prefix_switch'] ?? []) as $prefix) {
            if (str_starts_with($name, (string) $prefix) && $this->isBooleanish($dbType, $columnType)) {
                $result['type']   = 'switch';
                $result['layer']  = 'P2';
                $result['rule']   = sprintf('前缀 %s → switch', $prefix);

                return $result;
            }
        }

        // ── P3 后缀规则（顺序即优先级）─────────────────
        foreach ((array) ($this->rules['suffix_rules'] ?? []) as $rule) {
            if (!is_array($rule) || !$this->matchesRule($name, $rule)) {
                continue;
            }
            if (!$this->satisfiesDbType($dbType, $rule)) {
                continue;
            }

            $type = (string) ($rule['type'] ?? '');

            // 被规则命中但类型需要枚举支撑（radio/select）而枚举缺失时：
            // 不静默降级，而是记录警告并继续向下（由调用方在 --dry-run 中可见）
            if ($type === 'select' && $options === [] && !empty($rule['note']) && str_contains((string) $rule['note'], '关联')) {
                $result['warnings'][] = sprintf('命中「%s」但关联表未知，已生成 TODO', $rule['semantics'] ?? $type);
            }

            $result['type']     = $type;
            $result['multiple'] = (bool) ($rule['multiple'] ?? false);
            $result['layer']    = 'P3';
            $result['rule']     = sprintf(
                '后缀命中 %s（%s）',
                implode('|', (array) ($rule['match'] ?? [])),
                $name
            );

            if (isset($rule['props']) && is_array($rule['props'])) {
                $result['props'] = $rule['props'];
            }

            if (isset($rule['note'])) {
                $result['rule'] .= ' · ' . $rule['note'];
            }

            return $result;
        }

        // ── P4 DB 类型兜底 ────────────────────────────
        if (in_array($dbType, ['enum', 'set'], true)) {
            $result['type']     = 'select';
            $result['multiple'] = $dbType === 'set';
            $result['layer']    = 'P4';
            $result['rule']     = sprintf('DB=%s', $dbType);

            if ($options === []) {
                $result['warnings'][] = sprintf('DB=%s 但未解析出枚举，请核对列注释', $dbType);
            }

            return $result;
        }

        $result['layer'] = 'P4';
        $result['rule']  = sprintf('DB 类型兜底 %s', $dbType);

        return $result;
    }

    // ────────────────────────────────────────────────
    //  各层应用
    // ────────────────────────────────────────────────

    /**
     * P0：应用显式覆盖。
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function applyOverride(array $result, array $override): array
    {
        if (!empty($override['skip_form'])) {
            return $this->markSystem($result, (string) ($override['role'] ?? 'reserved'), 'P0', '显式声明 skip_form');
        }

        $result['type']     = (string) ($override['type'] ?? $result['type']);
        $result['multiple'] = (bool) ($override['multiple'] ?? false);

        if (isset($override['props']) && is_array($override['props'])) {
            $result['props'] = array_merge($result['props'], $override['props']);
        }

        if (isset($override['options']) && is_array($override['options'])) {
            $result['options'] = $override['options'];
        }

        $result['layer']    = 'P0';
        $result['declared'] = true;
        $result['rule']     = '显式覆盖（声明）';

        return $result;
    }

    /**
     * P1：应用语义字段规则。
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function applyExact(array $result, string $name, array $spec): array
    {
        $type = (string) ($spec['type'] ?? 'text');

        if ($type === 'enum_choice') {
            $maxRadio = (int) ($spec['css'] ?? 3);

            if ($result['options'] === []) {
                // 注释解析不出枚举 —— 显式降级并留痕，不静默按文本处理
                $result['warnings'][] = sprintf('命中「%s」但列注释未解析出枚举，已降级为 %s', $name, $result['fallback']);
                $result['layer']      = 'P4';
                $result['rule']       = sprintf('精确=%s（枚举缺失，降级）', $name);
                $result['type']       = $result['fallback'];

                return $result;
            }

            $type = count($result['options']) <= $maxRadio ? 'radio' : 'select';
        }

        $result['type']  = $type;
        $result['layer'] = 'P1';

        if (isset($spec['props']) && is_array($spec['props'])) {
            $result['props'] = array_merge($result['props'], $spec['props']);
        }

        $result['rule'] = sprintf(
            '精确=%s%s',
            $name,
            $result['options'] !== [] ? sprintf(' + 注释解析 %d 项', count($result['options'])) : ''
        );

        return $result;
    }

    /**
     * 标记为"不进表单"的系统字段。
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function markSystem(array $result, string $role, string $layer, string $rule): array
    {
        $result['skip_form'] = true;
        $result['system']    = $role;
        $result['layer']     = $layer;
        $result['rule']      = $rule;

        return $result;
    }

    // ────────────────────────────────────────────────
    //  匹配与推导工具
    // ────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function matchOverride(string $table, string $name): ?array
    {
        $overrides = (array) ($this->rules['overrides'] ?? []);

        foreach ([$table . '.' . $name, $table . '.*'] as $key) {
            if ($key !== '.' && isset($overrides[$key]) && is_array($overrides[$key])) {
                return $overrides[$key];
            }
        }

        return null;
    }

    /**
     * 命名匹配：完整相等 或 下划线边界后缀。
     *
     * @param array<string, mixed> $rule
     */
    private function matchesRule(string $name, array $rule): bool
    {
        foreach ((array) ($rule['match'] ?? []) as $token) {
            $token = (string) $token;

            if ($name === $token || str_ends_with($name, '_' . $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * DB 类型约束：规则未声明 db_type 时视为不限。
     *
     * @param array<string, mixed> $rule
     */
    private function satisfiesDbType(string $dbType, array $rule): bool
    {
        $allowed = $rule['db_type'] ?? null;

        return !is_array($allowed) || $allowed === [] || in_array($dbType, $allowed, true);
    }

    /**
     * 是否为"布尔气质"的列（供 P2 switch 判定）。
     */
    private function isBooleanish(string $dbType, string $columnType): bool
    {
        if (in_array($columnType, (array) ($this->rules['bool_column_types'] ?? []), true)) {
            return true;
        }

        return in_array($dbType, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'bit', 'bool', 'boolean', 'char', 'varchar'], true);
    }

    /**
     * 枚举选项推导：列注释优先，其次 enum/set 的列定义。
     *
     * @return array<string, string>
     */
    private function deriveOptions(string $dbType, string $columnType, string $comment): array
    {
        $fromComment = CommentOptionParser::parse($comment);

        if ($fromComment !== []) {
            return $fromComment;
        }

        if (!in_array($dbType, ['enum', 'set'], true)) {
            return [];
        }

        if (preg_match('/^(?:enum|set)\((.*)\)$/i', $columnType, $matches) !== 1) {
            return [];
        }

        $values = str_getcsv(str_replace("'", '', $matches[1]), ',', "'", '\\');
        $values = array_values(array_filter(array_map('trim', $values), static fn (string $v): bool => $v !== ''));

        return count($values) >= 2 ? array_combine($values, $values) : [];
    }

    /**
     * P4/P5 层的兜底类型（也是各层结果的 `fallback`）。
     */
    private function fallbackType(string $dbType, string $columnType): string
    {
        if (in_array($columnType, (array) ($this->rules['bool_column_types'] ?? []), true)) {
            return 'switch';
        }

        $map = (array) ($this->rules['db_type_map'] ?? []);

        return (string) ($map[$dbType] ?? ($this->rules['default_type'] ?? 'text'));
    }
}
