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
        $connection = (string) ($options['connection'] ?? 'mysql');
        $rules      = (array) Config::get('crud', []);
        $generator  = (array) ($rules['generator'] ?? []);

        $prefix = (string) ($generator['table_prefix'] ?? '');

        if ($prefix === '') {
            $prefix = (string) Config::get('database.connections.' . $connection . '.prefix', '');
        }

        $tableShort = $prefix !== '' && str_starts_with($table, $prefix)
            ? substr($table, strlen($prefix))
            : $table;

        $class = (string) (($options['class'] ?? '') ?: $this->studly($tableShort));
        $title = (string) ($options['title'] ?? '');

        $introspector = new TableIntrospector();
        $columns      = $introspector->columns($table, $connection);

        if ($title === '') {
            $title = $this->cleanTableComment($introspector->tableComment($table, $connection), $tableShort);
        }

        // 字段覆盖合并进 P0 层（显式声明优先于一切启发式）
        $rules  = $this->mergeFieldOverrides($rules, $table, (array) ($options['field'] ?? []));
        $ignore = array_filter(array_map('trim', explode(',', (string) ($options['ignore_fields'] ?? ''))));

        $resolver = new FieldTypeResolver($rules);
        $fields   = [];

        foreach ($resolver->resolveAll($columns, $table) as $result) {
            if (in_array($result['name'], $ignore, true)) {
                $result['skip_form'] = true;
                $result['rule']      = '命令行 --ignore-fields';
                $result['layer']     = 'P0';
                $result['declared']  = true;
            }

            $fields[] = $result;
        }

        return [
            'table'          => $table,
            'table_short'    => $tableShort,
            'class'          => $class,
            'title'          => $title,
            'module'         => (string) ($options['module'] ?? 'admin'),
            'plugin'         => $this->normalizePlugin((string) ($options['plugin'] ?? '')),
            'controller_url' => $this->controllerUrl($class),
            'soft_delete'    => self::hasSoftDelete($fields),
            'generator'      => $generator,
            'fields'         => $fields,
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
     * 把 `--field=name:type:opts`（或 Web 表单提交的同结构数据）合并进 P0 overrides。
     *
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

            // 选项串沿用列注释的宽松语法，复用同一个解析器
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
