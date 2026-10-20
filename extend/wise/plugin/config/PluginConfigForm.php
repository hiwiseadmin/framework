<?php

declare(strict_types=1);

namespace wise\plugin\config;

/**
 * 插件配置表单渲染器。
 *
 * 将归一化后的 schema 渲染为 Bootstrap 5 表单 HTML，
 * 供插件中心「配置」弹窗（layer.iframe）直接使用。
 *
 * 前端契约（必须与 public/statics/admin/plugin.js 保持一致）：
 *   - 模板中必须有且仅有一个 <form> 元素（plugin.js:269 用 querySelector('form')）
 *   - 字段名统一为 config[{key}]（plugin.js:272 用 $(form).serialize()）
 *   - 多选字段用 config[{key}][]，serialize() 后后端 post('config/a') 得到数组
 *
 * 安全：所有动态内容（标题、提示、值、选项）均经 htmlspecialchars() 转义。
 *
 * 注意：jQuery 的 serialize() 会跳过未勾选的 checkbox / radio，
 * 因此开关与多选字段在「未勾选」时不会出现在 POST 数据中。
 * 后端（Plugin::config()）会按 schema 补齐这些缺失键，详见该方法注释。
 */
class PluginConfigForm
{
    /**
     * 内置分组标题（group key => 标题）与展示顺序
     *
     * 这里**只保留通用性最强的几个**；插件自己的分组名请在 manifest 顶层声明
     * `config_groups`（见 {@see \wise\plugin\PluginManager::getPluginConfigMeta()}），
     * 渲染时以声明为准、本表兜底：
     *   · 未登记的分组标题回退为 key 原文，排在已登记项之后；
     *   · `default` 是「字段未声明 group」的兜底分区，固定排在最后。
     *
     * 内置顺序即默认展示顺序；需要临时覆盖时也可传 `$options['groups']`。
     */
    public const GROUP_TITLES = [
        'basic'    => '基础设置',
        'content'  => '内容与评论',
        'advanced' => '高级设置',
        'default'  => '基础配置',
    ];

    /**
     * 内置标签页标题（tab key => 标题）与展示顺序
     *
     * 字段级 `tab` 属性决定它落在哪个标签页；**同一个 tab 里可以放多个 group**（分区）。
     * 同 {@see self::GROUP_TITLES}：插件自定义标签页请在 manifest 顶层 `config_tabs` 声明，
     * 本表只兜底通用项；空 key（字段未声明 tab）在多标签页场景下显示为「其他」，固定排最后。
     */
    public const TAB_TITLES = [
        'basic'    => '基础设置',
        'advanced' => '高级设置',
        ''         => '其他',
    ];

    /**
     * 渲染完整表单。
     *
     * @param array $schema 已归一化的 schema（PluginConfigSchema::normalize 的输出）
     * @param array $values 当前生效值（键为配置键）
     * @param array $options 渲染选项 [ 'field_prefix' => 'config' ]
     * @return string 表单 HTML（不含 <form> 标签本身）
     */
    public static function render(array $schema, array $values, array $options = []): string
    {
        if ($schema === []) {
            return '';
        }

        $prefix = (string) ($options['field_prefix'] ?? 'config');

        // 1) 先按 tab 切分（字段未声明 tab 时为空串，全部落入同一个隐式标签页）
        $tabs = [];
        foreach ($schema as $key => $field) {
            $tabs[(string) ($field['tab'] ?? '')][$key] = $field;
        }

        $tabMeta = self::resolveMeta(array_keys($tabs), self::TAB_TITLES, (array) ($options['tabs'] ?? []), '');
        uksort($tabs, static function (string $a, string $b) use ($tabMeta): int {
            return ($tabMeta[$a]['sort'] <=> $tabMeta[$b]['sort']) ?: strcmp($a, $b);
        });

        // 2) 只有一个标签页（含「所有字段都没声明 tab」）→ 不渲染 tab 栏，直接按 group 平铺分区
        if (count($tabs) === 1) {
            return self::renderFields((array) reset($tabs), $values, $prefix, $options);
        }

        // 3) 多标签页 → Nav tabs，每个 pane 内仍按 group 分区
        //
        // ⚠ 所有 tab-pane 都留在**同一个 `<form>` 内**（切换只是 display 变化），
        //    保证 plugin.js 的 `querySelector('form')` + `$(form).serialize()`
        //    仍能拿到全部字段（含未激活标签页里的字段）。
        $tabPrefix = self::sanitizeId((string) ($options['tab_prefix'] ?? 'cfg'));

        $nav   = '';
        $panes = '';
        $first = true;

        foreach ($tabs as $tab => $fields) {
            $suffix   = self::sanitizeId($tab === '' ? 'other' : (string) $tab);
            $paneId   = $tabPrefix . '-pane-' . $suffix;
            $buttonId = $tabPrefix . '-tab-' . $suffix;

            $nav .= sprintf(
                '<li class="nav-item" role="presentation">'
                . '<button class="nav-link%s" id="%s" type="button" role="tab" data-bs-toggle="tab"'
                . ' data-bs-target="#%s" aria-controls="%s" aria-selected="%s">%s</button></li>',
                $first ? ' active' : '',
                self::escape($buttonId),
                self::escape($paneId),
                self::escape($paneId),
                $first ? 'true' : 'false',
                self::escape($tabMeta[$tab]['title'])
            );

            $panes .= sprintf(
                '<div class="tab-pane fade%s" id="%s" role="tabpanel" aria-labelledby="%s" tabindex="0">%s</div>',
                $first ? ' show active' : '',
                self::escape($paneId),
                self::escape($buttonId),
                self::renderFields($fields, $values, $prefix, $options)
            );

            $first = false;
        }

        return sprintf(
            '<ul class="nav nav-tabs config-tabs mb-3" id="%s-tabs" role="tablist">%s</ul>'
            . '<div class="tab-content config-tab-content" id="%s-tabs-content">%s</div>',
            self::escape($tabPrefix),
            $nav,
            self::escape($tabPrefix),
            $panes
        );
    }

    /**
     * 渲染一个容器（某个标签页，或不分标签页时的整页）内的字段：按 group 输出分区
     *
     * @param array  $fields  该容器内的字段（保持 schema 顺序）
     * @param array  $values  当前生效值
     * @param string $prefix  字段名前缀
     * @param array  $options 渲染选项（groups 可覆盖分区标题/顺序）
     * @return string
     */
    protected static function renderFields(array $fields, array $values, string $prefix, array $options = []): string
    {
        $groups = [];
        foreach ($fields as $key => $field) {
            $groups[(string) $field['group']][$key] = $field;
        }

        // 分区标题与顺序：$options['groups'] 声明 > 内置 GROUP_TITLES > 未登记组（靠后，标题取原文）
        $meta = self::resolveMeta(array_keys($groups), self::GROUP_TITLES, (array) ($options['groups'] ?? []), 'default');
        uksort($groups, static function (string $a, string $b) use ($meta): int {
            return ($meta[$a]['sort'] <=> $meta[$b]['sort']) ?: strcmp($a, $b);
        });

        $html  = '';
        $first = true;

        foreach ($groups as $group => $items) {
            // default 分区只在「本容器内还有别的分区」时才显示标题（单分区时标题冗余）
            $showTitle = $group !== 'default' || count($groups) > 1;

            if ($showTitle) {
                $html .= sprintf(
                    '<div class="config-group %s"><h6 class="config-group-title">%s</h6>',
                    $first ? '' : 'mt-4',
                    self::escape($meta[$group]['title'])
                );
            }

            foreach ($items as $key => $field) {
                $value = array_key_exists($key, $values) ? $values[$key] : $field['value'];
                $html .= self::renderRow($field, $value, $prefix);
            }

            if ($showTitle) {
                $html .= '</div>';
            }

            $first = false;
        }

        return $html;
    }

    /**
     * 生成安全的 HTML id 片段
     *
     * 分组名与配置键可能是中文或含点号等字符，直接进 id/选择器会有隐患，统一归一为 `[A-Za-z0-9_-]`。
     */
    protected static function sanitizeId(string $value): string
    {
        $id = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $value);
        return $id === '' ? 'g' : $id;
    }

    /**
     * 计算 tab / group 的标题与排序值（两者共用同一套口径）
     *
     * 排序口径：内置表按声明顺序给 10/20/30…（`$lastKey` 固定排最后）；`$declared` 可覆盖标题与排序；
     * 未登记的新名称排在已登记项之后，标题回退为名称原文（插件自定义 tab/分组无需改框架）。
     *
     * @param array  $keys     实际出现的名称集合
     * @param array  $builtin  内置标题表（数组顺序即默认展示顺序）
     * @param array  $declared 覆盖声明：name => ['title' => string, 'sort' => int] 或 name => '标题'
     * @param string $lastKey  固定排在最后的名称（group 用 'default'，tab 用 ''）
     * @return array<string, array{title: string, sort: int}>
     */
    protected static function resolveMeta(array $keys, array $builtin, array $declared = [], string $lastKey = ''): array
    {
        $meta = [];
        $i    = 0;

        foreach ($builtin as $key => $title) {
            $meta[$key] = [
                'title' => $title,
                'sort'  => $key === $lastKey ? PHP_INT_MAX : (++$i * 10),
            ];
        }

        foreach ($declared as $key => $def) {
            $key = (string) $key;
            if (is_string($def)) {
                $def = ['title' => $def];
            }
            $def = is_array($def) ? $def : [];

            $meta[$key] = [
                'title' => (string) ($def['title'] ?? ($meta[$key]['title'] ?? $key)),
                'sort'  => array_key_exists('sort', $def)
                    ? (int) $def['sort']
                    : ($meta[$key]['sort'] ?? (9000 + count($meta))),
            ];
        }

        foreach ($keys as $key) {
            $key = (string) $key;
            if (isset($meta[$key])) {
                continue;
            }
            $meta[$key] = ['title' => $key, 'sort' => 9000 + count($meta)];
        }

        return $meta;
    }

    /**
     * 渲染单个字段行（label + 控件 + 提示）。
     *
     * @param array  $field 归一化字段定义
     * @param mixed  $value 当前值
     * @param string $prefix 字段名前缀
     * @return string
     */
    protected static function renderRow(array $field, mixed $value, string $prefix): string
    {
        $key   = (string) $field['key'];
        $id    = 'cfg-' . self::sanitizeId($key);
        $name  = $prefix . '[' . $key . ']';
        $multi = in_array($field['type'], ['checkbox'], true);

        // 多选字段使用数组语法，serialize() 后后端可收到数组
        $fieldName = $multi ? $name . '[]' : $name;

        $html = '<div class="mb-3 row align-items-center">';

        // 行标签**不设 for**：避免点标签文字误触控件 —— switch/checkbox 的 label 一点就会切换开关，
        // 在「标题很长、控件在右侧」的布局里极易误操作。控件自身保留 id（供 JS/校验定位）。
        $html .= sprintf(
            '<label class="col-sm-3 col-form-label">%s%s</label>',
            self::escape((string) $field['title']),
            !empty($field['required']) ? ' <span class="text-danger">*</span>' : ''
        );

        $html .= '<div class="col-sm-9">';
        $html .= self::renderControl($field, $value, $id, $fieldName);

        if (!empty($field['tips'])) {
            $html .= sprintf(
                '<div class="form-text">%s</div>',
                self::escape((string) $field['tips'])
            );
        }

        $html .= '</div></div>';

        return $html;
    }

    /**
     * 按类型渲染表单控件。
     *
     * @param array  $field 归一化字段定义
     * @param mixed  $value 当前值
     * @param string $id    元素 id
     * @param string $name  元素 name
     * @return string
     */
    protected static function renderControl(array $field, mixed $value, string $id, string $name): string
    {
        $options = is_array($field['options']) ? $field['options'] : [];

        switch ($field['type']) {
            case 'textarea':
            case 'editor':
            case 'array':
                return sprintf(
                    '<textarea class="form-control" id="%s" name="%s" rows="4">%s</textarea>',
                    self::escape($id),
                    self::escape($name),
                    self::escape(self::stringify($value))
                );

            case 'number':
                return sprintf(
                    '<input type="number" class="form-control" id="%s" name="%s" value="%s">',
                    self::escape($id),
                    self::escape($name),
                    self::escape(self::stringify($value))
                );

            case 'select':
                $html = sprintf('<select class="form-select" id="%s" name="%s">', self::escape($id), self::escape($name));
                foreach ($options as $optValue => $optLabel) {
                    $selected = (string) $value === (string) $optValue ? ' selected' : '';
                    $html .= sprintf(
                        '<option value="%s"%s>%s</option>',
                        self::escape((string) $optValue),
                        $selected,
                        self::escape((string) $optLabel)
                    );
                }
                return $html . '</select>';

            case 'radio':
                $html = '<div class="d-flex flex-wrap gap-3 pt-1">';
                foreach ($options as $optValue => $optLabel) {
                    $checked = (string) $value === (string) $optValue ? ' checked' : '';
                    $html .= sprintf(
                        '<div class="form-check"><input class="form-check-input" type="radio" name="%s" id="%s_%s" value="%s"%s>'
                        . '<label class="form-check-label" for="%s_%s">%s</label></div>',
                        self::escape($name),
                        self::escape($id),
                        self::escape((string) $optValue),
                        self::escape((string) $optValue),
                        $checked,
                        self::escape($id),
                        self::escape((string) $optValue),
                        self::escape((string) $optLabel)
                    );
                }
                return $html . '</div>';

            case 'checkbox':
                $selected = is_array($value) ? array_map('strval', $value) : [];
                $html     = '<div class="d-flex flex-wrap gap-3 pt-1">';
                foreach ($options as $optValue => $optLabel) {
                    $checked = in_array((string) $optValue, $selected, true) ? ' checked' : '';
                    // $name 已由 renderRow() 追加了 '[]'（config[key][]），此处不可重复追加，
                    // 否则会生成 config[key][][]，导致后端收到三层嵌套数组。
                    $html .= sprintf(
                        '<div class="form-check"><input class="form-check-input" type="checkbox" name="%s" id="%s_%s" value="%s"%s>'
                        . '<label class="form-check-label" for="%s_%s">%s</label></div>',
                        self::escape($name),
                        self::escape($id),
                        self::escape((string) $optValue),
                        self::escape((string) $optValue),
                        $checked,
                        self::escape($id),
                        self::escape((string) $optValue),
                        self::escape((string) $optLabel)
                    );
                }
                return $html . '</div>';

            case 'switch':
            case 'boolean':
                // 渲染为 Bootstrap 开关。注意未勾选时 serialize() 不会提交该字段，
                // 后端会按 schema 补齐为 false。
                // label 是 Bootstrap 开关的视觉滑块容器，**故意不写 for**：
                // 写了 for 会让「点滑块旁边的空白」也切换开关，容易误操作。
                return sprintf(
                    '<div class="form-check form-switch">'
                    . '<input class="form-check-input" type="checkbox" role="switch" id="%s" name="%s" value="1"%s>'
                    . '<label class="form-check-label"></label></div>',
                    self::escape($id),
                    self::escape($name),
                    filter_var($value, FILTER_VALIDATE_BOOLEAN) ? ' checked' : ''
                );

            case 'color':
                return sprintf(
                    '<input type="color" class="form-control form-control-color" id="%s" name="%s" value="%s">',
                    self::escape($id),
                    self::escape($name),
                    self::escape(self::stringify($value))
                );

            case 'date':
                return sprintf(
                    '<input type="date" class="form-control" id="%s" name="%s" value="%s">',
                    self::escape($id),
                    self::escape($name),
                    self::escape(self::stringify($value))
                );

            case 'image':
                // 图片/文件选择器：先提供文本输入（可直接填 URL 或路径），
                // 后续可接入附件选择器增强。
                return sprintf(
                    '<div class="input-group">'
                    . '<input type="text" class="form-control" id="%s" name="%s" value="%s" placeholder="图片地址">'
                    . '<button class="btn btn-outline-secondary" type="button" disabled title="附件选择器待接入">'
                    . '<i class="bi bi-image"></i></button></div>',
                    self::escape($id),
                    self::escape($name),
                    self::escape(self::stringify($value))
                );

            case 'text':
            case 'string':
            default:
                // 密码类敏感字段不在 schema 中声明类型，统一按普通文本处理；
                // 若 manifest 将 key 命名为 *key/*secret/*password，改用密码框以避免明文回显。
                $isSecret = (bool) preg_match('/(key|secret|password|token)/i', (string) $field['key']);

                return sprintf(
                    '<input type="%s" class="form-control" id="%s" name="%s" value="%s" autocomplete="off">',
                    $isSecret ? 'password' : 'text',
                    self::escape($id),
                    self::escape($name),
                    self::escape(self::stringify($value))
                );
        }
    }

    /**
     * 将值转为可写入表单控件的字符串。
     *
     * 数组（array 类型）以换行分隔展示，便于人工编辑。
     *
     * @param mixed $value
     * @return string
     */
    protected static function stringify(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value === true) {
            return '1';
        }

        if (is_array($value)) {
            return implode("\n", array_map('strval', $value));
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '' : $json;
    }

    /**
     * HTML 转义。
     *
     * @param string $value
     * @return string
     */
    protected static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
