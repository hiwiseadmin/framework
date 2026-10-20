<?php

declare(strict_types=1);

namespace wise\plugin\config;

/**
 * 插件配置 Schema 归一化器。
 *
 * 职责：把 manifest.json 中形态各异的 config 声明，收敛成统一结构，
 * 并提供默认值提取、写入值校验与必填校验。
 *
 * 兼容的三种历史写法：
 *   A. 对象 map + value：  "config": {"key": {"type":"string","title":"x","value":"y"}}
 *   B. 数组 list + name：  "config": [{"name":"enable","type":"radio","value":"1"}]
 *   C. 对象 map + default："config": {"cache_time": {"type":"number","default":3600}}
 *
 * 归一化后的字段结构：
 *   [
 *       '配置键' => [
 *           'key'      => string  配置键
 *           'type'     => string  表单类型（见 TYPES）
 *           'title'    => string  显示标题
 *           'value'    => mixed   默认值
 *           'options'  => array   选项（select/radio/checkbox 用）
 *           'tips'     => string  说明文字
 *           'required' => bool    是否必填
 *           'group'    => string  分组名
 *           'sort'     => int     排序
 *       ]
 *   ]
 *
 * 设计约束：本类不依赖框架容器、不触碰数据库，保持纯静态以便直接单测。
 */
class PluginConfigSchema
{
    /**
     * 保留键前缀。
     *
     * 以该前缀开头的键属于框架内部状态（如 PluginStateManager 写入的
     * __state.enabled / __state.installed），不参与业务配置的读写。
     */
    public const RESERVED_PREFIX = '__';

    /**
     * 支持的表单字段类型。
     *
     * 注意：'boolean' 与 'switch' 等价，二者均渲染为开关、均校验为 bool。
     * 保留 'boolean' 是因为存量 manifest（wisecms / shortlink / wiseeditor /
     * workflowdemo 共 6 处）已在使用该写法，不可简单降级为 string ——
     * 否则布尔默认值 true 会被强转为字符串 "1"，语义丢失。
     */
    public const TYPES = [
        'string', 'text', 'textarea', 'number', 'select', 'radio',
        'checkbox', 'switch', 'boolean', 'color', 'date', 'editor', 'image', 'array',
    ];

    /**
     * 布尔型字段类型（渲染为开关，值恒为 bool）。
     */
    public const BOOL_TYPES = ['switch', 'boolean'];

    /**
     * 归一化 manifest 的 config 字段。
     *
     * 非法声明（键名为空、定义非数组、保留键）会被静默跳过，
     * 未知 type 降级为 string，保证输出结构始终可用。
     *
     * @param array $raw manifest.json 的 config 原值（可能是 map 或 list，也可能是空数组/null）
     * @return array<string, array> 按 group + sort + key 排序后的归一化 schema
     */
    public static function normalize(array $raw): array
    {
        $fields = [];

        foreach ($raw as $key => $def) {
            // 形态 B：数组 list，键名藏在 name 字段里
            if (is_int($key) && is_array($def)) {
                $key = (string) ($def['name'] ?? '');
            }

            // 跳过非法声明：键名为空、定义非数组、保留键
            if ($key === '' || !is_array($def) || self::isReserved($key)) {
                continue;
            }

            $key  = (string) $key;
            $type = (string) ($def['type'] ?? 'string');
            if (!in_array($type, self::TYPES, true)) {
                $type = 'string';
            }

            $fields[$key] = [
                'key'      => $key,
                'type'     => $type,
                'title'    => (string) ($def['title'] ?? $key),
                // 默认值：value 优先，回退 default，再回退类型零值
                'value'    => $def['value'] ?? $def['default'] ?? self::emptyValue($type),
                'options'  => (array) ($def['options'] ?? []),
                'tips'     => (string) ($def['tips'] ?? $def['description'] ?? ''),
                'required' => (bool) ($def['required'] ?? false),
                // 展示层级：tab（标签页，可空 = 不分组到标签页）> group（标签页内的分区）
                'tab'      => (string) ($def['tab'] ?? ''),
                'group'    => (string) ($def['group'] ?? 'default'),
                'sort'     => (int) ($def['sort'] ?? 0),
            ];
        }

        // 同一 tab + 同组内按 sort 升序，其余按 tab/组名升序
        uasort($fields, static fn (array $a, array $b): int =>
            [$a['tab'], $a['group'], $a['sort'], $a['key']] <=> [$b['tab'], $b['group'], $b['sort'], $b['key']]);

        return $fields;
    }

    /**
     * 提取 schema 中的默认值映射。
     *
     * @param array $schema 已归一化的 schema
     * @return array<string, mixed>
     */
    public static function defaults(array $schema): array
    {
        return array_map(static fn (array $field): mixed => $field['value'], $schema);
    }

    /**
     * 按字段类型校验并强转待写入的值。
     *
     * 策略是「收敛」而非「拒绝」：非法值回退到该字段的默认值，
     * 保证存储层数据始终类型正确，避免脏值流入业务代码。
     *
     * @param array $field 单个归一化字段定义
     * @param mixed $value 待写入值
     * @return mixed 校验后的值
     */
    public static function validate(array $field, mixed $value): mixed
    {
        switch ($field['type']) {
            case 'number':
                return is_numeric($value) ? $value + 0 : ($field['value'] + 0);

            case 'switch':
            case 'boolean':
                // 表单未勾选时不提交，故 null 视为 false
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'checkbox':
                $value = is_array($value)
                    ? array_values($value)
                    : ($value === '' || $value === null ? [] : [$value]);
                // 仅保留 options 中声明过的选项，防止注入任意值
                $allowed = array_map('strval', array_keys($field['options']));
                return array_values(array_intersect(array_map('strval', $value), $allowed));

            case 'select':
            case 'radio':
                $value = (string) $value;
                return array_key_exists($value, $field['options']) ? $value : (string) $field['value'];

            case 'array':
                return is_array($value) ? $value : (array) $field['value'];

            default:
                return is_scalar($value) ? (string) $value : (string) $field['value'];
        }
    }

    /**
     * 校验必填项。
     *
     * @param array $schema 已归一化的 schema
     * @param array $values 待保存的值（键为配置键）
     * @return string[] 缺失的必填字段标题列表，为空表示校验通过
     */
    public static function validateRequired(array $schema, array $values): array
    {
        $missing = [];

        foreach ($schema as $key => $field) {
            if (empty($field['required'])) {
                continue;
            }

            $value = $values[$key] ?? null;
            if ($value === null || $value === '' || $value === [] || $value === false) {
                $missing[] = $field['title'];
            }
        }

        return $missing;
    }

    /**
     * 判断给定键是否为框架保留键。
     *
     * @param string $key 配置键
     * @return bool true 表示是保留键，禁止业务读写
     */
    public static function isReserved(string $key): bool
    {
        return str_starts_with($key, self::RESERVED_PREFIX);
    }

    /**
     * 按类型返回零值。
     *
     * @param string $type 已校验过的类型
     * @return mixed
     */
    protected static function emptyValue(string $type): mixed
    {
        return match ($type) {
            'number'            => 0,
            'switch', 'boolean' => false,
            'checkbox', 'array' => [],
            default             => '',
        };
    }
}
