<?php
declare(strict_types=1);

namespace wise\crud;

/**
 * CrudMap — 「按表归档」的字段映射声明（`config/crud_map.php`）。
 *
 * 为什么需要它：
 *   `--field=status:radio:0=禁用,1=启用` 能一次性解决「字段→组件」的显式指定，
 *   但它是**一次性的**：无法版本化、无法团队评审、无法解释「为什么这张表这样生成」。
 *   CrudMap 把这批人工判定固化成声明文件，可 review、可 diff、可回滚。
 *
 * 优先级（高者胜，由 `FieldTypeResolver` 的 overrides 层实现）：
 * ```
 *   config/crud.php 的 overrides        （全局约定）
 *     <  crud_map 的 table_defaults      （表级兜底，写入 `表名.*`）
 *       <  crud_map 的 fields            （字段级声明，写入 `表名.字段`）
 *         <  CLI/Web 的 --field           （当次显式，最高）
 * ```
 * 因此「声明文件」不会让命令行失去灵活性，二者叠加而非互相覆盖。
 *
 * 结构（`config/crud_map.php`）：
 * ```
 * return [
 *     '__enabled' => true,                       // 一键回滚开关
 *     'ws_article' => [
 *         'class'       => 'Article',            // 可选：覆盖类名推导
 *         'title'       => '文章',               // 可选：覆盖标题
 *         'table_defaults' => ['type' => 'text'],// 可选：表级兜底
 *         'fields'      => [                     // 字段级声明
 *             'status' => ['type' => 'radio', 'options' => ['0' => '禁用', '1' => '启用']],
 *         ],
 *         'relations'   => [                     // 关联字段声明（见 CrudPlanBuilder::relations）
 *             'dept_id' => ['table' => 'ws_dept', 'value' => 'id', 'label' => 'name'],
 *         ],
 *         'ignore'      => ['remark'],           // 不进表单
 *     ],
 * ];
 * ```
 *
 * 说明：配置文件用下划线命名（`crud_map.php`）而非 `crud.map.php` ——
 * ThinkPHP 以**文件名**作为配置键，含点的文件名会与 `config('a.b')` 的点号
 * 路径语法冲突，导致取值失败。
 */
final class CrudMap
{
    /** 允许的表级声明键 */
    private const ALLOWED_TABLE_KEYS = ['class', 'title', 'table_defaults', 'fields', 'relations', 'ignore'];

    /** 允许的字段声明键 */
    private const ALLOWED_FIELD_KEYS = [
        'type', 'options', 'multiple', 'props', 'skip_form', 'role',
    ];

    /** @var array<string, mixed> 规范化后的表声明（已剔除 __ 保留键） */
    private array $tables = [];

    private bool $enabled;

    /**
     * @param array<string, mixed> $map    config/crud_map.php 的内容
     * @param bool|null            $enabled 显式开关；null 时取 `__enabled`，缺省为 true
     * @throws \InvalidArgumentException 结构非法时（不静默忽略）
     */
    public function __construct(array $map = [], ?bool $enabled = null)
    {
        $this->enabled = $enabled ?? (bool) ($map['__enabled'] ?? true);
        $this->tables  = self::normalize($map);
    }

    /**
     * 从框架配置读取（`config('crud_map')`）。
     */
    public static function fromConfig(): self
    {
        if (!class_exists(\think\facade\Config::class)) {
            return new self();
        }

        try {
            $map = (array) \think\facade\Config::get('crud_map', []);
        } catch (\Throwable) {
            $map = [];
        }

        return new self($map);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 已声明的表名列表。
     *
     * @return array<int, string>
     */
    public function tables(): array
    {
        return array_keys($this->tables);
    }

    public function has(string $table): bool
    {
        return $this->enabled && isset($this->tables[$table]);
    }

    /**
     * 取某张表的规范化声明（无声明时返回空数组）。
     *
     * @return array{class?:string,title?:string,table_defaults?:array<string,mixed>,fields?:array<string,array<string,mixed>>,relations?:array<string,array{table:string,value:string,label:string}>,ignore?:array<int,string>}
     */
    public function spec(string $table): array
    {
        if (!$this->has($table)) {
            return [];
        }

        return $this->tables[$table];
    }

    /**
     * 把声明合并进 `config/crud.php` 的规则（写入 overrides 层）。
     *
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    public function applyToRules(array $rules, string $table): array
    {
        $spec = $this->spec($table);

        if ($spec === []) {
            return $rules;
        }

        $rules['overrides'] = (array) ($rules['overrides'] ?? []);

        if (isset($spec['table_defaults']) && $spec['table_defaults'] !== []) {
            $key                        = $table . '.*';
            $rules['overrides'][$key]   = array_merge(
                (array) ($rules['overrides'][$key] ?? []),
                $spec['table_defaults']
            );
        }

        foreach ((array) ($spec['fields'] ?? []) as $name => $override) {
            $key                      = $table . '.' . $name;
            $rules['overrides'][$key] = array_merge(
                (array) ($rules['overrides'][$key] ?? []),
                $override
            );
        }

        return $rules;
    }

    /**
     * 某张表的关联字段声明。
     *
     * @return array<string, array{table:string,value:string,label:string}>
     */
    public function relations(string $table): array
    {
        return (array) ($this->spec($table)['relations'] ?? []);
    }

    /**
     * 某张表的忽略字段。
     *
     * @return array<int, string>
     */
    public function ignore(string $table): array
    {
        return (array) ($this->spec($table)['ignore'] ?? []);
    }

    /**
     * 解析命令行/Web 的关联声明：`字段:目标表:值列:显示列`。
     *
     * 允许目标表带库前缀，值列/显示列可省略（默认 `id` / `name`）。
     *
     * @return array{field:string,table:string,value:string,label:string}
     * @throws \InvalidArgumentException 格式非法时
     */
    public static function parseRelation(string $raw): array
    {
        $parts = array_map('trim', explode(':', $raw));

        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException(sprintf(
                '关联声明格式应为「字段:目标表:值列:显示列」，收到：%s',
                $raw
            ));
        }

        $field  = $parts[0];
        $target = $parts[1];
        $value  = (($parts[2] ?? '') !== '') ? $parts[2] : 'id';
        $label  = (($parts[3] ?? '') !== '') ? $parts[3] : 'name';

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
            throw new \InvalidArgumentException(sprintf('关联字段名非法：%s', $field));
        }

        return [
            'field' => $field,
            'table' => $target,
            'value' => $value,
            'label' => $label,
        ];
    }

    /**
     * 把「本次显式声明」合并进声明文件（CLI 与 Web 共用同一实现）。
     *
     * 安全策略：**不覆盖手写文件**。只有文件不存在、或带 `@wise:generated`
     * 标记（由本方法生成过）时才写盘；否则返回可粘贴片段，交由人手工合并，
     * 避免把人的注释与排版冲掉。
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, array<string, string>> $relations
     * @return array{status:string,path:string,snippet:string,message:string}
     */
    public static function mergeInto(
        string $path,
        string $table,
        array $fields,
        array $relations
    ): array {
        $entry = [];

        if ($fields !== []) {
            $entry['fields'] = $fields;
        }

        if ($relations !== []) {
            $entry['relations'] = $relations;
        }

        $snippet = $entry === [] ? '' : sprintf("'%s' => %s,", $table, self::exportPhp($entry, 0));

        if ($entry === []) {
            return [
                'status'  => 'noop',
                'path'    => $path,
                'snippet' => '',
                'message' => '没有可保存的显式声明（--field / --relation 均为空）',
            ];
        }

        $exists    = is_file($path);
        $content   = $exists ? (string) file_get_contents($path) : '';
        $generated = !$exists || str_contains($content, '@wise:generated');

        if (!$generated) {
            return [
                'status'  => 'snippet',
                'path'    => $path,
                'snippet' => $snippet,
                'message' => '声明文件已存在且非本工具生成，为避免覆盖手写注释，请手工合并片段',
            ];
        }

        $map      = $exists ? (array) (include $path) : [];
        $existing = (array) ($map[$table] ?? []);

        if ($fields !== []) {
            $existing['fields'] = array_merge((array) ($existing['fields'] ?? []), $fields);
        }

        if ($relations !== []) {
            $existing['relations'] = array_merge((array) ($existing['relations'] ?? []), $relations);
        }

        $map[$table] = $existing;

        $payload = self::renderFile($map);

        $tmp = $path . '.' . getmypid() . '.tmp';

        if (file_put_contents($tmp, $payload) === false || @rename($tmp, $path) === false) {
            @unlink($tmp);

            return [
                'status'  => 'error',
                'path'    => $path,
                'snippet' => $snippet,
                'message' => '写入失败：' . $path,
            ];
        }

        return [
            'status'  => 'written',
            'path'    => $path,
            'snippet' => $snippet,
            'message' => sprintf('已写入声明：%s（表 %s）', $path, $table),
        ];
    }

    /**
     * 渲染完整声明文件（带生成标记，供下一次安全覆盖）。
     *
     * @param array<string, mixed> $map
     */
    public static function renderFile(array $map): string
    {
        return "<?php\n"
            . "// @wise:generated 由 CRUD 构建器 / `php think wise:crud --save-map` 生成；\n"
            . "// 可以手改，但手改后本工具将不再自动覆盖本文件（只打印片段），以避免冲掉注释。\n"
            . "declare(strict_types=1);\n\n"
            . "return " . self::exportPhp($map, 0) . ";\n";
    }

    /**
     * 数组 → 缩进友好的 PHP 字面量（不用 var_export，避免 `array (` 与长行）。
     *
     * @param array<array-key, mixed> $value
     */
    public static function exportPhp(array $value, int $level = 0): string
    {
        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $level + 1);
        $lines  = ['['];

        foreach ($value as $key => $item) {
            $rendered = is_array($item) ? self::exportPhp($item, $level + 1) : var_export($item, true);
            $lines[]  = sprintf(
                '%s%s => %s,',
                $indent,
                is_int($key) ? (string) $key : var_export((string) $key, true),
                $rendered
            );
        }

        $lines[] = str_repeat('    ', $level) . ']';

        return implode("\n", $lines);
    }

    /**
     * 规范化 + 校验整份声明。
     *
     * @param array<string, mixed> $map
     * @return array<string, array<string, mixed>>
     * @throws \InvalidArgumentException
     */
    private static function normalize(array $map): array
    {
        $tables = [];

        foreach ($map as $table => $spec) {
            $table = (string) $table;

            if (str_starts_with($table, '__')) {
                continue;
            }

            if ($table === '') {
                throw new \InvalidArgumentException('crud_map 存在空的表名键');
            }

            if (!is_array($spec)) {
                throw new \InvalidArgumentException(sprintf('crud_map[%s] 必须是数组', $table));
            }

            foreach (array_keys($spec) as $key) {
                if (!in_array($key, self::ALLOWED_TABLE_KEYS, true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'crud_map[%s] 含未知键 "%s"，允许：%s',
                        $table,
                        $key,
                        implode(' / ', self::ALLOWED_TABLE_KEYS)
                    ));
                }
            }

            $normalized = [];

            if (isset($spec['class'])) {
                $normalized['class'] = (string) $spec['class'];
            }

            if (isset($spec['title'])) {
                $normalized['title'] = (string) $spec['title'];
            }

            if (isset($spec['table_defaults'])) {
                $normalized['table_defaults'] = self::normalizeField($table, '*', (array) $spec['table_defaults']);
            }

            if (isset($spec['fields'])) {
                if (!is_array($spec['fields'])) {
                    throw new \InvalidArgumentException(sprintf('crud_map[%s].fields 必须是数组', $table));
                }

                $fields = [];

                foreach ($spec['fields'] as $name => $override) {
                    $name = (string) $name;

                    if ($name === '') {
                        throw new \InvalidArgumentException(sprintf('crud_map[%s].fields 存在空字段名', $table));
                    }

                    $fields[$name] = self::normalizeField($table, $name, (array) $override);
                }

                $normalized['fields'] = $fields;
            }

            if (isset($spec['relations'])) {
                if (!is_array($spec['relations'])) {
                    throw new \InvalidArgumentException(sprintf('crud_map[%s].relations 必须是数组', $table));
                }

                $relations = [];

                foreach ($spec['relations'] as $name => $relation) {
                    $name     = (string) $name;
                    $relation = (array) $relation;

                    foreach (['table', 'value', 'label'] as $required) {
                        if (!isset($relation[$required]) || (string) $relation[$required] === '') {
                            throw new \InvalidArgumentException(sprintf(
                                'crud_map[%s].relations[%s] 缺少必填项 "%s"',
                                $table,
                                $name,
                                $required
                            ));
                        }
                    }

                    $relations[$name] = [
                        'table' => (string) $relation['table'],
                        'value' => (string) $relation['value'],
                        'label' => (string) $relation['label'],
                    ];
                }

                $normalized['relations'] = $relations;
            }

            if (isset($spec['ignore'])) {
                $normalized['ignore'] = array_values(array_filter(
                    array_map('strval', (array) $spec['ignore']),
                    static fn (string $name): bool => $name !== ''
                ));
            }

            $tables[$table] = $normalized;
        }

        return $tables;
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    private static function normalizeField(string $table, string $field, array $override): array
    {
        foreach (array_keys($override) as $key) {
            if (!in_array($key, self::ALLOWED_FIELD_KEYS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'crud_map[%s].fields[%s] 含未知键 "%s"，允许：%s',
                    $table,
                    $field,
                    $key,
                    implode(' / ', self::ALLOWED_FIELD_KEYS)
                ));
            }
        }

        if (isset($override['options']) && !is_array($override['options'])) {
            throw new \InvalidArgumentException(sprintf(
                'crud_map[%s].fields[%s].options 必须是 值=>标签 的数组',
                $table,
                $field
            ));
        }

        if (isset($override['type']) && !is_string($override['type'])) {
            throw new \InvalidArgumentException(sprintf(
                'crud_map[%s].fields[%s].type 必须是字符串',
                $table,
                $field
            ));
        }

        if (isset($override['skip_form'])) {
            $override['skip_form'] = (bool) $override['skip_form'];
        }

        if (isset($override['multiple'])) {
            $override['multiple'] = (bool) $override['multiple'];
        }

        return $override;
    }
}
