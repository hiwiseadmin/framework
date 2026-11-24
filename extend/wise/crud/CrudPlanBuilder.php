<?php
declare(strict_types=1);

namespace wise\crud;

use think\facade\Config;

/**
 * CrudPlanBuilder — 表名 + 选项 → **判定计划**。
 *
 * 这是 CRUD 生成器的第一段，唯一职责是「读表 + 推导」，
 * **不渲染、不落盘、不依赖 console**，因此可被 CLI 与 Web 界面共用。
 *
 * 产物「计划」的结构（下游 CrudGenerator / CrudWriter 的输入契约）：
 * ```
 * table          数据表全名（含前缀）
 * table_short    去掉库前缀的表名（模型 $name / 迁移文件名用）
 * class          控制器与模型类名
 * title          菜单与页面标题
 * module         目标应用模块（框架级）
 * plugin         插件标识；空串表示框架级
 * controller_url 控制器 URL 段（如 Article → article）
 * soft_delete    是否含软删除列
 * generator      config/crud.php 的 generator 段
 * fields[]       逐字段判定结果（含 layer/rule/declared/fallback/warnings）
 * ```
 *
 * 选项用**普通数组**而非 `think\console\Input` —— 这是解开 CLI 耦合的关键，
 * 也是 Web 界面能复用同一套推导逻辑的前提。
 *
 * @see \docs\wiseadmin-crud-field-mapping.md 字段类型推导规范
 */
class CrudPlanBuilder
{
    /**
     * 关联校验用的列缓存（连接:表 → 列定义）。
     *
     * Web 端一次请求可能校验多个关联字段，缓存可避免对同一张目标表重复查询。
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $columnCache = [];

    /**
     * 构建判定计划。
     *
     * 选项键（全部可选，与命令行选项一一对应）：
     *   class, title, module, plugin, field[], ignore_fields, connection
     *
     * @param string                    $table   数据表名（可含库前缀）
     * @param array<string, mixed>      $options 生成选项
     * @return array<string, mixed>
     * @throws \InvalidArgumentException 插件标识非法时
     */
    public function build(string $table, array $options = []): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '表名非法：%s（需字母或下划线开头，仅含字母、数字、下划线）',
                $table
            ));
        }

        $connection = (string) ($options['connection'] ?? 'mysql');

        $rules = $options['rules'] ?? null;
        if (!is_array($rules)) {
            $rules = (array) $this->setting('crud', []);
        }

        $generator = (array) ($rules['generator'] ?? []);

        $prefix = (string) ($generator['table_prefix'] ?? '');

        if ($prefix === '') {
            $prefix = (string) $this->setting('database.connections.' . $connection . '.prefix', '');
        }

        $tableShort = $prefix !== '' && str_starts_with($table, $prefix)
            ? substr($table, strlen($prefix))
            : $table;

        $crudMap = $options['crud_map'] ?? null;
        if (!$crudMap instanceof CrudMap) {
            $crudMap = CrudMap::fromConfig();
        }

        $mapSpec = $crudMap->spec($table);

        $class = (string) (($options['class'] ?? '') ?: ($mapSpec['class'] ?? '') ?: $this->studly($tableShort));
        $title = (string) (($options['title'] ?? '') ?: ($mapSpec['title'] ?? ''));

        $module = (string) ($options['module'] ?? 'admin');

        if (preg_match('/^[a-z][a-z0-9_]*$/', $module) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '模块名非法：%s（需小写字母开头，仅含小写字母、数字、下划线）',
                $module
            ));
        }

        if (preg_match('/[\'"\\\\$;()\x00-\x1f]/u', $title) === 1) {
            throw new \InvalidArgumentException(
                '标题含非法字符：不允许引号、反斜杠、$、分号、ASCII 括号与控制字符'
            );
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '类名非法：%s（需字母或下划线开头，仅含字母、数字、下划线）',
                $class
            ));
        }

        $introspector = $options['introspector'] ?? null;
        if (!$introspector instanceof TableIntrospector) {
            $introspector = new TableIntrospector();
        }

        $columns = $introspector->columns($table, $connection);

        if ($title === '') {
            $title = $this->cleanTableComment($introspector->tableComment($table, $connection), $tableShort);
        }

        $rules  = $crudMap->applyToRules($rules, $table);
        $rules  = $this->mergeFieldOverrides($rules, $table, (array) ($options['field'] ?? []));
        $ignore = $this->mergeIgnoreFields($crudMap->ignore($table), (string) ($options['ignore_fields'] ?? ''));

        $resolver = new FieldTypeResolver($rules);
        $fields   = [];

        foreach ($resolver->resolveAll($columns, $table) as $result) {
            if (in_array($result['name'], $ignore, true)) {
                $result['skip_form'] = true;
                $result['rule']      = '显式忽略（crud_map.ignore / --ignore-fields）';
                $result['layer']     = 'P0';
                $result['declared']  = true;
            }

            $fields[] = $result;
        }

        $fields = $this->applyRelations($fields, $table, $connection, $options, $crudMap, $introspector, $prefix);

        return [
            'table'          => $table,
            'table_short'    => $tableShort,
            'class'          => $class,
            'title'          => $title,
            'module'         => $module,
            'plugin'         => $this->normalizePlugin((string) ($options['plugin'] ?? '')),
            'controller_url' => $this->controllerUrl($class),
            'soft_delete'    => self::hasSoftDelete($fields),
            'search'         => !((bool) ($options['no_search'] ?? false)),
            'generator'      => $generator,
            'fields'         => $fields,
            'meta'           => [
                'map_applied'  => $mapSpec !== [],
                'map_declared' => array_keys((array) ($mapSpec['fields'] ?? [])),
                'map_relations' => array_keys($crudMap->relations($table)),
                'map_enabled'  => $crudMap->isEnabled(),
            ],
        ];
    }

    /**
     * 字段集是否含软删除列。
     *
     * public static：CrudGenerator 收到**手工构造**的 plan（可能没有 soft_delete 键）时
     * 用它兜底，避免两处各写一份判定。
     *
     * @param array<int, array<string, mixed>> $fields
     */
    public static function hasSoftDelete(array $fields): bool
    {
        foreach ($fields as $field) {
            if (($field['system'] ?? null) === 'delete_time') {
                return true;
            }
        }

        return false;
    }

    /**
     * 规范化插件标识。
     *
     * 插件标识同时用作**命名空间段**（`plugin\{ident}\controller`）与**目录名**，
     * 因此必须是合法标识符。非法值在此直接拒绝，而不是生成出无法被自动加载的代码。
     *
     * @param string $plugin 原始值
     * @return string 规范化后的标识；空串表示框架级
     * @throws \InvalidArgumentException 标识非法时抛出
     */
    public function normalizePlugin(string $plugin): string
    {
        $plugin = trim($plugin);

        if ($plugin === '') {
            return '';
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $plugin) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '插件标识非法：%s（需小写字母开头，仅含小写字母、数字、下划线，如 cms、my_cms）',
                $plugin
            ));
        }

        return $plugin;
    }

    /**
     * @param array<string, mixed>  $rules
     * @param array<int, string>    $specs 形如 `status:radio:0=禁用,1=启用`
     * @return array<string, mixed>
     */
    protected function mergeFieldOverrides(array $rules, string $table, array $specs): array
    {
        foreach ($specs as $spec) {
            $parts = explode(':', (string) $spec, 3);

            if (count($parts) < 2) {
                continue;
            }

            [$name, $type] = $parts;
            $rawOptions    = $parts[2] ?? '';

            $override = ['type' => $type];

            if ($rawOptions !== '') {
                $options = CommentOptionParser::parse($rawOptions);

                if ($options !== []) {
                    $override['options'] = $options;
                }
            }

            $rules['overrides'][$table . '.' . $name] = $override;
        }

        return $rules;
    }

    /**
     * 合并「忽略字段」来源：声明文件 + 命令行/Web。
     *
     * @param array<int, string> $fromMap
     * @return array<int, string>
     */
    protected function mergeIgnoreFields(array $fromMap, string $raw): array
    {
        $fromCli = array_filter(array_map('trim', explode(',', $raw)));

        return array_values(array_unique(array_merge($fromMap, $fromCli)));
    }

    /**
     * 应用并校验关联字段声明。
     *
     * 两个来源合并：`crud_map.relations`（声明文件）与 `--relation=字段:表:值列:显示列`（当次显式）。
     * 同名字段以命令行/Web 为准 —— 声明是可评审的默认值，不是不可覆盖的枷锁。
     *
     * 校验失败**立即报错**（表不存在 / 列不存在 / 字段不存在），
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed>             $options
     * @return array<int, array<string, mixed>>
     * @throws \InvalidArgumentException 关联声明非法时
     */
    protected function applyRelations(
        array $fields,
        string $table,
        string $connection,
        array $options,
        CrudMap $crudMap,
        TableIntrospector $introspector,
        string $prefix = ''
    ): array {
        $specs = [];

        foreach ($crudMap->relations($table) as $field => $spec) {
            $specs[$field] = [
                'table'  => $spec['table'],
                'value'  => $spec['value'],
                'label'  => $spec['label'],
                'source' => 'crud_map',
            ];
        }

        $rawRelations = $options['relation'] ?? [];
        if (is_string($rawRelations)) {
            $rawRelations = [$rawRelations];
        }

        foreach ((array) $rawRelations as $raw) {
            if (trim((string) $raw) === '') {
                continue;
            }

            $parsed = CrudMap::parseRelation((string) $raw);

            $specs[$parsed['field']] = [
                'table'  => $parsed['table'],
                'value'  => $parsed['value'],
                'label'  => $parsed['label'],
                'source' => '--relation',
            ];
        }

        if ($specs === []) {
            return $fields;
        }

        $ownNames = array_map(static fn (array $field): string => (string) $field['name'], $fields);

        foreach ($specs as $field => $spec) {
            if (!in_array($field, $ownNames, true)) {
                throw new \InvalidArgumentException(sprintf(
                    '关联字段 [%s] 不在表 [%s] 中，可选字段：%s',
                    $field,
                    $table,
                    implode(', ', array_slice($ownNames, 0, 12))
                ));
            }

            $target = $this->resolveTargetTable($spec['table'], $table, $connection, $introspector, $prefix);
            $specs[$field]['table'] = $target;

            $targetColumns = array_map(
                static fn (array $column): string => (string) $column['name'],
                $this->columnsOf($target, $connection, $introspector)
            );

            foreach (['value', 'label'] as $part) {
                if (!in_array($spec[$part], $targetColumns, true)) {
                    throw new \InvalidArgumentException(sprintf(
                        '关联字段 [%s] 的%s列 [%s] 在目标表 [%s] 中不存在',
                        $field,
                        $part === 'value' ? '值' : '显示',
                        $spec[$part],
                        $target
                    ));
                }
            }
        }

        foreach ($fields as $index => $field) {
            $name = (string) $field['name'];

            if (!isset($specs[$name])) {
                continue;
            }

            $spec = $specs[$name];

            $fields[$index]['type']     = 'select';
            $fields[$index]['multiple'] = (bool) ($field['multiple'] ?? false) || str_ends_with($name, '_ids');
            $fields[$index]['props']    = array_merge(
                (array) ($field['props'] ?? []),
                ['relation' => ['table' => $spec['table'], 'value' => $spec['value'], 'label' => $spec['label']]]
            );
            $fields[$index]['layer']    = 'P0';
            $fields[$index]['declared'] = true;
            $fields[$index]['rule']     = sprintf(
                '显式关联 %s.%s → %s（%s）',
                $spec['table'],
                $spec['value'],
                $spec['label'],
                $spec['source']
            );

            $warnings = array_values(array_filter(
                (array) ($field['warnings'] ?? []),
                static fn (string $warning): bool => !str_contains($warning, '关联表未知')
            ));

            if ($spec['table'] === $table) {
                $warnings[] = '自关联：请确认树形结构与父级选择器（避免把自己选成父级）';
            }

            if (!empty($field['skip_form'])) {
                $warnings[] = sprintf('该字段被标记为不进表单（%s），关联声明不会生效', (string) ($field['system'] ?? 'system'));
            }

            $fields[$index]['warnings'] = $warnings;
        }

        return $fields;
    }

    /**
     * 解析目标表名：支持写短名（`dept`）由表前缀补全，也支持写全名（`ws_dept`）。
     */
    protected function resolveTargetTable(
        string $target,
        string $current,
        string $connection,
        TableIntrospector $introspector,
        string $prefix = ''
    ): string {
        $candidates = [$target];

        if ($prefix === '') {
            $prefix = (string) $this->setting('database.connections.' . $connection . '.prefix', '');
        }
        if ($prefix !== '' && !str_starts_with($target, $prefix)) {
            $candidates[] = $prefix . $target;
        }

        foreach ($candidates as $candidate) {
            try {
                if ($this->columnsOf($candidate, $connection, $introspector) !== []) {
                    return $candidate;
                }
            } catch (\RuntimeException) {
            }
        }

        throw new \InvalidArgumentException(sprintf(
            '关联目标表 [%s] 不存在（由字段所属表 [%s] 引用）',
            $target,
            $current
        ));
    }

    /**
     * 读目标表列（进程内按表缓存，避免 Web 端逐字段重复查询）。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function columnsOf(string $table, string $connection, TableIntrospector $introspector): array
    {
        $key = $connection . ':' . $table;

        if (!isset($this->columnCache[$key])) {
            $this->columnCache[$key] = $introspector->columns($table, $connection);
        }

        return $this->columnCache[$key];
    }

    /**
     * 读取框架配置，允许在没有应用上下文（纯脚本 / 单测）时安全降级。
     */
    protected function setting(string $key, mixed $default = null): mixed
    {
        if (!class_exists(Config::class)) {
            return $default;
        }

        try {
            return Config::get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * 类名 → 控制器 URL 段（Article → article、UserProfile → user_profile）。
     */
    protected function controllerUrl(string $class): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class) ?? $class);
    }

    /**
     * 表名 → 类名（user_profile → UserProfile）。
     */
    protected function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }

    /**
     * 表注释 → 人类可读标题（「文章表」→「文章管理」）。
     */
    protected function cleanTableComment(string $comment, string $fallback): string
    {
        $comment = trim($comment);

        if ($comment === '') {
            return $fallback;
        }

        return str_ends_with($comment, '表') ? mb_substr($comment, 0, -1) . '管理' : $comment;
    }
}
