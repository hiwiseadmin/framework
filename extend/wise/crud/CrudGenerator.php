<?php
declare(strict_types=1);

namespace wise\crud;

/**
 * CrudGenerator — **判定计划 → 产物内容**（纯函数，不落盘）。
 *
 * 这是 CRUD 生成器的第二段。之所以把它与落盘分开，是因为：
 *   1. 「预览」天然成立 —— 不落盘先看代码，是 CLI `--dry-run` 的升级，
 *      也是 Web 界面「生成前预览」的基础；
 *   2. 落盘涉及覆盖策略 / 目录创建 / manifest 合并等副作用，不该混在渲染里；
 *   3. 渲染可被单测直接断言（无需临时目录）。
 *
 * 渲染契约：模板（stub）只认变量、不认模式。框架级与插件级的差异
 * **全部集中在 variables()** 里（命名空间、基类导入、$plugin 声明、接口基址…）。
 *
 * @see \docs\wiseadmin-crud-field-mapping.md
 */
class CrudGenerator
{
    /** 模板目录（含尾部分隔符） */
    protected string $stubDir;

    /**
     * @param string|null $stubDir 模板目录；默认取本类同级的 stubs/
     */
    public function __construct(?string $stubDir = null)
    {
        $this->stubDir = $stubDir ?? __DIR__ . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR;
    }

    /**
     * 模板目录（含尾部分隔符）。
     *
     * 供上层在「模板缺失」报错里指明位置。
     */
    public function stubDir(): string
    {
        return $this->stubDir;
    }

    /**
     * 计划 → 全部模板变量。
     *
     * 这里是框架级 / 插件级差异的**唯一汇聚点**：stub 只认变量，不认模式。
     *
     * @param array<string, mixed> $plan
     * @return array<string, string> ['{$class}' => 'Article', ...]
     */
    public function variables(array $plan): array
    {
        $generator = (array) $plan['generator'];
        $fields    = (array) $plan['fields'];

        $formLines  = [];
        $allowWrite = [];
        $jsonFields = [];

        foreach ($fields as $field) {
            // JSON 列（多图/多文件）需在模型上声明 $json，实现自动编解码
            if (!empty($field['multiple']) && in_array($field['type'], ['image', 'file'], true)) {
                $jsonFields[] = sprintf("'%s'", $field['name']);
            }

            if ($field['skip_form']) {
                continue;
            }

            $allowWrite[] = sprintf("'%s'", $field['name']);
            $formLines[]  = $this->renderFormLine($field);
        }

        // plan 里没有 soft_delete 时兜底现算（手工构造的 plan 也能渲染）
        $hasSoftDelete = (bool) ($plan['soft_delete'] ?? CrudPlanBuilder::hasSoftDelete($fields));
        $columns       = $this->renderColumns($plan);

        // 只导入实际用到的字段类，避免产出未使用的 import
        $fieldClasses = [];

        foreach ($fields as $field) {
            if (!$field['skip_form']) {
                $fieldClasses[$this->fieldClass($field['type'])] = true;
            }
        }

        $fieldClasses = array_keys($fieldClasses);
        sort($fieldClasses);

        $plugin    = (string) ($plan['plugin'] ?? '');
        $isPlugin  = $plugin !== '';
        $namespace = $isPlugin ? 'plugin\\' . $plugin : 'app\\' . (string) $plan['module'];
        $menuPlugin = (string) ($generator['menu_plugin'] ?? 'system');

        return [
            '{$plugin}'             => $plugin,
            '{$namespace}'          => $namespace,
            // 导入块的值是「以换行开头的完整 import 行」，空串表示该行不存在。
            // stub 里让占位符独占一行，空值时才不会留下空白行。
            // 框架级控制器与 BaseController 同命名空间，写 use 会触发 PHP 报错，故为空。
            '{$class_imports}'      => $isPlugin ? "\nuse plugin\BaseController;" : '',
            '{$model_imports}'      => "\n" . ($isPlugin
                ? 'use plugin\BaseModel;'
                : 'use app\common\model\BaseModel;')
                . ($hasSoftDelete ? "\nuse think\model\concern\SoftDelete;" : ''),
            // 框架级需要声明 $plugin（CrudAction 消费）；插件标识由 plugin\PluginTrait
            // 从命名空间（plugin\{ident}\...）自动解析，再声明一个 $plugin 只会是死代码
            '{$plugin_declaration}' => $isPlugin
                ? '    // 插件标识无需声明：plugin\PluginTrait 会从命名空间自动解析'
                : sprintf(
                    "    /** 菜单归属：宿主层固定 %s */\n    protected \$plugin = '%s';",
                    $menuPlugin,
                    $menuPlugin
                ),
            // 接口基址：框架级 /admin/{controller}，插件级 /plugin/{ident}/{controller}。
            // 插件的路由前缀不同，JS 里不能沿用 /admin/。
            '{$api_base}'           => '/' . ($isPlugin ? 'plugin/' . $plugin : (string) $plan['module'])
                . '/' . (string) $plan['controller_url'],
            '{$class}'              => (string) $plan['class'],
            '{$table}'              => (string) $plan['table_short'],
            '{$table_full}'         => (string) $plan['table'],
            '{$title}'              => (string) $plan['title'],
            '{$module}'             => (string) $plan['module'],
            '{$controller_url}'     => (string) $plan['controller_url'],
            '{$create_time}'        => date('Y-m-d H:i:s'),
            '{$timestamp}'          => date('YmdHis'),
            '{$field_imports}'      => $fieldClasses === []
                ? ''
                : 'use wise\form\schema\field\{' . implode(', ', $fieldClasses) . '};',
            '{$form_lines}'         => implode("\n", $formLines),
            '{$allow_write}'        => implode(', ', $allowWrite),
            '{$columns}'            => $columns,
            // 按条件输出整行，避免模板出现空白行或悬空 use
            '{$soft_delete_trait}'  => $hasSoftDelete ? '    use SoftDelete;' : '',
            '{$json_property}'      => $jsonFields !== []
                ? '    /** JSON 列：自动编解码（多图/多文件存附件 ID 数组） */' . "\n"
                    . '    protected $json = [' . implode(', ', $jsonFields) . '];'
                : '',
            '{$menu_plugin}'        => $menuPlugin,
            // 迁移类名必须与文件名推导结果一致：{timestamp}_menu_{table_short}.php → Menu{Class}
            '{$menu_class}'         => 'Menu' . (string) $plan['class'],
            '{$controller_dir}'     => (string) ($generator['controller_dir'] ?? 'app/admin/controller'),
            '{$model_dir}'          => (string) ($generator['model_dir'] ?? 'app/admin/model'),
            '{$migration_dir}'      => (string) ($generator['migration_dir'] ?? 'database/migrations'),
            '{$js_dir}'             => (string) ($generator['js_dir'] ?? 'public/statics/admin'),
            '{$table_short}'        => (string) $plan['table_short'],
            '{$menu_title}'         => (string) $plan['title'],
            '{$action_wrappers}'    => $this->renderActionWrappers($hasSoftDelete),
        ];
    }

    /**
     * 生成标准动作的 public 入口
     *
     * `CrudAction` 只提供 protected `_xxx()` 实现，控制器必须显式暴露；
     * 未生成入口的动作对人工与 AI 都不存在（fail-closed）。
     *
     * 回收站动作默认**不生成**，只给注释模板：物理删除属高风险能力，
     * 应由业务确认后手动取消注释。
     */
    protected function renderActionWrappers(bool $hasSoftDelete): string
    {
        $actions = [
            'getList' => '列表数据',
            'save'    => '新增保存',
            'update'  => '更新保存',
            'delete'  => '删除',
            'status'  => '更新状态',
            'sort'    => '更新排序',
        ];

        $blocks = [];
        foreach ($actions as $action => $label) {
            $blocks[] = sprintf(
                "    /**\n     * %s\n     */\n    public function %s()\n    {\n        return \$this->_%s();\n    }",
                $label,
                $action,
                $action
            );
        }

        if ($hasSoftDelete) {
            $blocks[] = "    // 回收站动作（按需取消注释；forceDelete 为物理删除，需业务确认）\n"
                . "    // public function restore() { return \$this->_restore(); }\n"
                . "    // public function forceDelete() { return \$this->_forceDelete(); }";
        }

        return implode("\n\n", $blocks);
    }

    /**
     * 渲染单个模板。
     *
     * @param string                $stub      模板文件名（如 Controller.stub）
     * @param array<string, string> $variables
     * @return string|null 模板不存在返回 null（由调用方决定是否视为致命）
     */
    public function render(string $stub, array $variables): ?string
    {
        $path = $this->stubDir . str_replace('/', DIRECTORY_SEPARATOR, $stub);

        if (!is_file($path)) {
            return null;
        }

        $template = file_get_contents($path);

        if ($template === false) {
            return null;
        }

        // 先把模板换行统一为 LF 再替换：变量值里的换行是 LF，
        // 若模板是 CRLF，产物会出现混合换行符（PHP / JS 源码统一用 LF）。
        return strtr(str_replace(["\r\n", "\r"], "\n", $template), $variables);
    }

    /**
     * 表单类型 → wise-form-schema 字段类名。
     *
     * @see tests/StubRenderingTest::testEveryMappedFieldClassExists 每个映射都必须真实存在
     */
    public function fieldClass(string $type): string
    {
        return match ($type) {
            'textarea' => 'Textarea',
            'editor'   => 'Editor',
            'number'   => 'Number',
            'select'   => 'Select',
            'radio'    => 'Radio',
            'checkbox' => 'Checkbox',
            'switch'   => 'SwitchField',
            'datetime' => 'Datetime',
            'date'     => 'Date',
            'time'     => 'Time',
            'color'    => 'Color',
            'email'    => 'Email',
            'password' => 'Password',
            'hidden'   => 'Hidden',
            'image'    => 'Image',
            'file'     => 'File',
            'avatar'   => 'Image',   // 附件包/avatar 字段类型就绪前先以 Image 承载
            default    => 'Text',
        };
    }

    /**
     * 生成 `defineForm()` 里的一行声明（含判定依据注释）。
     *
     * @param array<string, mixed> $field
     */
    protected function renderFormLine(array $field): string
    {
        $class = $this->fieldClass($field['type']);
        $args  = sprintf("'%s', '%s'", $field['name'], $this->escape($this->label($field)));

        $chain = '';

        if (!empty($field['options'])) {
            $chain .= '->options([' . $this->renderOptions((array) $field['options']) . '])';
        } elseif (in_array($field['type'], ['select', 'radio'], true)) {
            $chain .= '->options([]) /* TODO: 关联表未知，请补 options 或远程搜索 */';
        }

        if (!empty($field['multiple'])) {
            $chain .= '->multiple()';
        }

        if (!$field['nullable'] && $field['default'] === null && !in_array($field['type'], ['switch', 'image', 'file', 'avatar'], true)) {
            $chain .= '->required()';
        }

        if ($field['type'] === 'image' || $field['type'] === 'file') {
            $chain .= "/* 附件 ID 模式，需 ws-attachment 组件 */";
        }

        $comment = sprintf(
            '        // %s: %s · %s(%s) · 备选 %s',
            $field['name'],
            $field['column_type'],
            $field['layer'],
            $field['rule'],
            $field['fallback']
        );

        return $comment . "\n        " . sprintf(
            '$form->add(%s::make(%s)%s);',
            $class,
            $args,
            $chain
        );
    }

    /**
     * @param array<string, string> $options
     */
    protected function renderOptions(array $options): string
    {
        $parts = [];

        foreach ($options as $value => $label) {
            $parts[] = sprintf("'%s' => '%s'", $this->escape((string) $value), $this->escape((string) $label));
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $field
     */
    protected function label(array $field): string
    {
        if ((string) $field['comment'] !== '') {
            // 去掉注释中的枚举说明部分，只留人类可读标签
            $label = preg_split('/[\s,，;；]/u', (string) $field['comment'])[0] ?? '';

            if ($label !== '') {
                return $label;
            }
        }

        return (string) $field['name'];
    }

    protected function escape(string $text): string
    {
        return str_replace("'", "\\'", $text);
    }

    /**
     * 列表页列配置（v1：生成期固化，沿用既有 EnhancedTable 模式）。
     *
     * @param array<string, mixed> $plan
     */
    protected function renderColumns(array $plan): string
    {
        $lines = [
            '                {',
            "                    field: 'ck',",
            '                    checkbox: true,',
            "                    align: 'center',",
            "                    valign: 'middle'",
            '                },',
            '                {',
            "                    field: 'id',",
            "                    title: 'ID',",
            "                    width: '60',",
            '                    sortable: true',
            '                },',
        ];

        foreach ($plan['fields'] as $field) {
            if ($field['skip_form'] && !in_array($field['system'], ['create_time', 'update_time'], true)) {
                continue;
            }

            $formatter = match (true) {
                $field['type'] === 'switch'                         => 'status',
                in_array($field['type'], ['image', 'file'], true)   => 'image',
                default                                             => 'normal',
            };

            $lines[] = '                {';
            $lines[] = sprintf("                    field: '%s',", $field['name']);
            $lines[] = sprintf("                    title: '%s',", $this->escape($this->label($field)));
            $lines[] = sprintf("                    formatter: '%s',", $formatter);

            if (!empty($field['options']) && count($field['options']) <= 10) {
                $pairs = [];

                foreach ($field['options'] as $value => $label) {
                    $pairs[] = sprintf("'%s': '%s'", $this->escape((string) $value), $this->escape((string) $label));
                }

                $lines[] = '                    searchList: {' . implode(', ', $pairs) . '},';
            }

            if (in_array($field['name'], ['create_time', 'update_time', 'sort', 'weigh'], true)) {
                $lines[] = '                    sortable: true,';
            }

            $lines[] = "                    align: 'left'";
            $lines[] = '                },';
        }

        $lines[] = '                {';
        $lines[] = "                    field: 'operate',";
        $lines[] = "                    buttons: ['del'],";
        $lines[] = "                    title: '操作',";
        $lines[] = "                    formatter: 'operate'";
        $lines[] = '                }';

        return implode("\n", $lines);
    }
}
