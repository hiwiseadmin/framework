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
    /**
     * 搜索区最多生成的条件数。
     *
     * 后台列表页超过 6 个筛选项就变成「表单」而不是「筛选条」；
     * 其余字段交给表格高级查询或手写声明。
     */
    public const MAX_SEARCH_FIELDS = 6;

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
            if (!empty($field['multiple']) && in_array($field['type'], ['image', 'file'], true)) {
                $jsonFields[] = sprintf("'%s'", $field['name']);
            }

            if ($field['skip_form']) {
                continue;
            }

            $allowWrite[] = sprintf("'%s'", $field['name']);
            $formLines[]  = $this->renderFormLine($field);
        }

        $hasSoftDelete = (bool) ($plan['soft_delete'] ?? CrudPlanBuilder::hasSoftDelete($fields));
        $columns       = $this->renderColumns($plan);
        $search        = $this->renderSearchBlock($fields, $plan);

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
            '{$class_imports}'      => $isPlugin ? "\nuse plugin\BaseController;" : '',
            '{$model_imports}'      => "\n" . ($isPlugin
                ? 'use plugin\BaseModel;'
                : 'use app\common\model\BaseModel;')
                . ($hasSoftDelete ? "\nuse think\model\concern\SoftDelete;" : ''),
            '{$plugin_declaration}' => $isPlugin
                ? '    // 插件标识无需声明：plugin\PluginTrait 会从命名空间自动解析'
                : sprintf(
                    "    /** 菜单归属：宿主层固定 %s */\n    protected \$plugin = '%s';",
                    $menuPlugin,
                    $menuPlugin
                ),
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
            '{$soft_delete_trait}'  => $hasSoftDelete ? '    use SoftDelete;' : '',
            '{$json_property}'      => $jsonFields !== []
                ? '    /** JSON 列：自动编解码（多图/多文件存附件 ID 数组） */' . "\n"
                    . '    protected $json = [' . implode(', ', $jsonFields) . '];'
                : '',
            '{$menu_plugin}'        => $menuPlugin,
            '{$menu_class}'         => 'Menu' . (string) $plan['class'],
            '{$controller_dir}'     => (string) ($generator['controller_dir'] ?? 'app/admin/controller'),
            '{$model_dir}'          => (string) ($generator['model_dir'] ?? 'app/admin/model'),
            '{$migration_dir}'      => (string) ($generator['migration_dir'] ?? 'database/migrations'),
            '{$js_dir}'             => (string) ($generator['js_dir'] ?? 'public/statics/admin'),
            '{$table_short}'        => (string) $plan['table_short'],
            '{$menu_title}'         => (string) $plan['title'],
            '{$action_wrappers}'    => $this->renderActionWrappers($hasSoftDelete),
            '{$search_imports}'     => $search['imports'],
            '{$search_block}'       => $search['block'],
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
            'export'  => '导出（服务端流式 CSV，筛选 / 排序与列表完全一致）',
            'save'    => '新增保存',
            'update'  => '更新保存',
            'delete'  => '删除',
            'status'  => '更新状态',
            'sort'    => '更新排序',
        ];

        $blocks = [];
        foreach ($actions as $action => $label) {
            $blocks[] = $this->actionBlock($action, $label, sprintf('return $this->_%s();', $action));
        }

        if ($hasSoftDelete) {
            $recycle = [
                'restore'        => ['还原已删除记录（页面内直接还原）', 'return $this->_restore();'],
                'recycleList'    => ['本控制器回收站列表（已软删记录）', 'return $this->_recycleList();'],
                'recycleToken'   => ['签发「彻底删除」一次性确认令牌', 'return $this->_recycleToken();'],
                'recycleRestore' => ['回收站：还原记录', 'return $this->_restore();'],
            ];

            foreach ($recycle as $action => [$label, $body]) {
                $blocks[] = $this->actionBlock($action, $label, $body);
            }

            $blocks[] = "    // 彻底删除（物理删除、不可恢复）：确认业务确实需要后再取消注释\n"
                . "    // public function forceDelete() { return \$this->_forceDelete(); }\n"
                . "    // public function recycleForceDelete() { return \$this->_forceDelete(true); }";
        }

        return implode("\n\n", $blocks);
    }

    /**
     * 渲染单个公开动作入口。
     *
     * @param string $action 动作名（同时是路由名）
     * @param string $label  注释里的中文说明
     * @param string $body   方法体（单条 return 语句）
     */
    protected function actionBlock(string $action, string $label, string $body): string
    {
        return sprintf(
            "    /**\n     * %s\n     */\n    public function %s()\n    {\n        %s\n    }",
            $label,
            $action,
            $body
        );
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
            'avatar'   => 'Image',
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

        $relation = (array) ($field['props']['relation'] ?? []);

        if (!empty($field['options'])) {
            $chain .= '->options([' . $this->renderOptions((array) $field['options']) . '])';
        } elseif ($relation !== []) {
            $chain .= sprintf(
                '->options([]) /* 关联 %s：%s → %s；请接入远程搜索或补 options */',
                $this->escape((string) ($relation['table'] ?? '')),
                $this->escape((string) ($relation['value'] ?? 'id')),
                $this->escape((string) ($relation['label'] ?? 'name'))
            );
        } elseif (in_array($field['type'], ['select', 'radio'], true)) {
            $chain .= '->options([]) /* TODO: 关联表未知，请补 options 或远程搜索 */';
        }

        if (!empty($field['multiple'])) {
            $chain .= '->multiple()';
        }

        if (!$field['nullable'] && $field['default'] === null && !in_array($field['type'], ['switch', 'image', 'file', 'avatar'], true)) {
            $chain .= '->required()';
        }

        $length = $this->charLength((string) ($field['column_type'] ?? ''));

        if ($length !== null) {
            $chain .= sprintf("->rules('max:%d')", $length);
        }

        if ($field['type'] === 'number' && str_contains((string) ($field['column_type'] ?? ''), 'unsigned')) {
            $chain .= '->min(0)';
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
     * 由列定义推导 `varchar(n)` / `char(n)` 的长度上限。
     *
     * 只认这两种：`text/blob/json` 的容量不是「用户可见约束」，
     * 给它们生成 `max` 只会制造噪音。
     */
    protected function charLength(string $columnType): ?int
    {
        if (preg_match('/^(?:var)?char\((\d+)\)/i', trim($columnType), $matches) !== 1) {
            return null;
        }

        $length = (int) $matches[1];

        return $length > 0 && $length < 65536 ? $length : null;
    }

    /**
     * 搜索区声明（`defineSearch()`）。
     *
     * **只声明、不写查询**：控件 name 就是 `{字段}__{操作符}` 查询参数，
     * 由 RequestQueryBuilder 直接解析，后端不需要任何字段映射，也不需要
     * 生成 `buildSearchQuery()` 覆盖（默认实现即直通）。
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed>             $plan
     * @return array{imports: string, block: string} 无搜索条件时两者均为空串
     */
    protected function renderSearchBlock(array $fields, array $plan): array
    {
        if (($plan['search'] ?? true) === false) {
            return ['imports' => '', 'block' => ''];
        }

        $lines = [];

        foreach ($fields as $field) {
            $line = $this->renderSearchLine($field);

            if ($line === null) {
                continue;
            }

            $lines[] = $line;

            if (count($lines) >= self::MAX_SEARCH_FIELDS) {
                break;
            }
        }

        if ($lines === []) {
            return ['imports' => '', 'block' => ''];
        }

        $visible = count($lines) <= 4 ? 'true' : 'false';

        $body = "        \$search\n            ->add(" . implode(")\n            ->add(", $lines) . ");\n\n"
            . '        $search->defaultVisible(' . $visible . ');';

        return [
            'imports' => "use app\common\library\crud\Search;\nuse app\common\library\crud\SearchField;",
            'block'   => "\n" . "    /**\n"
                . "     * 搜索区声明（按列定义推导，可自由增删；删掉本方法与上面的两个 use 即关闭搜索区）\n"
                . "     *\n"
                . "     * 控件 name 即查询参数 `{字段}__{操作符}`，由 RequestQueryBuilder 直接解析，\n"
                . "     * 后端无需字段映射；只产生 AND 条件，跨字段 OR 请用表格高级查询。\n"
                . "     */\n"
                . "    protected function defineSearch(Search \$search): void\n"
                . "    {\n"
                . $body . "\n"
                . "    }\n",
        ];
    }

    /**
     * 单个搜索条件的声明，返回 null 表示该字段不适合出现在搜索区。
     *
     * 判定只依赖已解析出的表单类型与列类型 —— 不猜业务语义：
     * 关联下拉（options 为空）宁可不给，也不生成一个永远空的下拉框。
     *
     * @param array<string, mixed> $field
     */
    protected function renderSearchLine(array $field): ?string
    {
        $name = (string) ($field['name'] ?? '');

        if ($name === '' || !empty($field['multiple'])) {
            return null;
        }

        $system = (string) ($field['system'] ?? '');

        if (in_array($system, ['delete_time', 'update_time'], true)
            || in_array($name, ['delete_time', 'update_time'], true)) {
            return null;
        }

        if ($system === 'create_time' || $name === 'create_time') {
            $label = $this->escape($this->label($field) === $name ? '创建时间' : $this->label($field));

            return sprintf(
                "SearchField::make('%s', 'date')->label('%s')->range()",
                $this->escape($name),
                $label
            );
        }

        if (!empty($field['skip_form'])) {
            return null;
        }

        $type        = (string) ($field['type'] ?? '');
        $columnType  = strtolower((string) ($field['column_type'] ?? ''));
        $label       = $this->escape($this->label($field));
        $quotedName  = $this->escape($name);

        if (in_array($type, ['select', 'radio', 'switch'], true)) {
            $options = (array) ($field['options'] ?? []);

            if ($options === [] && preg_match('/^tinyint\(1\)/', $columnType) === 1) {
                $options = [1 => '启用', 0 => '禁用'];
            }

            if ($options === []) {
                if (preg_match('/^(?:tiny|small|medium|big)?int\b|^decimal|^float|^double/', $columnType) === 1) {
                    return sprintf(
                        "SearchField::make('%s', 'number')->label('%s') /* 关联/枚举未知：按精确值筛 */",
                        $quotedName,
                        $label
                    );
                }

                return null;
            }

            return sprintf(
                "SearchField::make('%s', 'select')->label('%s')->options([%s])",
                $quotedName,
                $label,
                $this->renderOptions($options)
            );
        }

        if ($type === 'number') {
            return sprintf("SearchField::make('%s', 'number')->label('%s')", $quotedName, $label);
        }

        if ($type === 'date' || $type === 'datetime' || $type === 'time') {
            return sprintf("SearchField::make('%s', 'date')->label('%s')->range()", $quotedName, $label);
        }

        if ($type === 'text' || $type === 'textarea' || $type === 'email') {
            if (preg_match('/^(?:var)?char\(/', $columnType) !== 1) {
                return null;
            }

            return sprintf("SearchField::make('%s', 'text')->label('%s')", $quotedName, $label);
        }

        return null;
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
