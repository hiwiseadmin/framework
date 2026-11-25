<?php
declare(strict_types=1);

namespace app\common\library\crud;

/**
 * SearchRenderer — 把 Search 的 Schema 渲染成 `.ws-filter-bar`＼�。
 *
 * 只负责「排版 + 控件 HTML」，不做任何查询逻辑：
 *   - 控件 `name` 直接是查询参数 `{field}__{op}`，与 RequestQueryBuilder 对齐；
 *   - 多值（区间 / 多选）用 `name[]`，PHP 侧自动收成数组；
 *   - 提交、防抖、显隐记忆由 `crud-page.js` 负责（本类不输出任何脚本）。
 *
 * 输出的 HTML 契约（`crud-page.js` 与 `EnhancedTable.bindFilterToggle()` 都依赖它）：
 *   - `<form id="ws-filter" class="ws-filter-bar ws-filter-form">`
 *   - 每个条件一个 `.ws-filter-item`（`data-field` / `data-op`）
 *   - 操作区 `.ws-filter-actions`（查询 / 重置）
 *
 * @see Search
 * @see SearchField
 */
final class SearchRenderer
{
    /**
     * 单框日期区间在输入框里的显示分隔符。
     *
     * 同时是 `crud-page.js` 拆分 `field__between` 的依据（渲染层写 `data-range-separator`，
     * JS 读同一个值来拆），两边必须一致，因此集中成一个常量。
     */
    private const RANGE_SEPARATOR = ' 至 ';

    /**
     * @param array<string, mixed> $schema  Search::toArray() 的输出
     * @param array<string, mixed> $context 可选：id / class / submitText / resetText / showActions
     */
    public function render(array $schema, array $context = []): string
    {
        $fields = $schema['fields'] ?? [];
        if (!is_array($fields) || $fields === []) {
            return '';
        }

        $layout = is_array($schema['layout'] ?? null) ? $schema['layout'] : [];

        $id          = (string) ($context['id'] ?? 'ws-filter');
        $extraClass  = trim((string) ($context['class'] ?? ''));
        $showActions = $context['showActions'] ?? true;

        $hiddenInputs = '';
        $items        = '';

        $cells = (new SearchGrid())->resolve($fields);

        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }

            if (($field['type'] ?? 'text') === 'hidden') {
                $hiddenInputs .= $this->hiddenInput((string) $name, $field);
                continue;
            }

            $items .= $this->item((string) $name, $field, $cells[(string) $name] ?? null);
        }

        if ($items === '') {
            return '';
        }

        $attrs = [
            'id'    => $id,
            'class' => trim('ws-filter-bar ws-filter-form row g-2 align-items-end ' . $extraClass),
        ];

        if (isset($layout['defaultVisible']) && $layout['defaultVisible'] !== null) {
            $attrs['data-default-visible'] = $layout['defaultVisible'] ? '1' : '0';
        }

        $html = '<form' . $this->attrString($attrs) . ' novalidate autocomplete="off">';
        $html .= $hiddenInputs;
        $html .= $items;

        if ($showActions) {
            $html .= $this->actions($context);
        }

        return $html . '</form>';
    }


    /**
     * @param array<string, mixed>      $field
     * @param array{col: string, width: string}|null $cell  SearchGrid 预算的栅格类
     */
    protected function item(string $name, array $field, ?array $cell = null): string
    {
        $label = (string) ($field['label'] ?? $name);
        $param = (string) ($field['param'] ?? ($name . '__eq'));
        $range = !empty($field['range']);

        $id = 'wsf-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name);

        $cell ??= ['col' => 'col-12 col-sm-6 col-lg-3', 'width' => 'ws-filter-w-md'];

        $attrs = [
            'class'      => trim($cell['col'] . ' ws-filter-item ' . $cell['width']),
            'data-field' => $name,
            'data-op'    => (string) ($field['op'] ?? 'eq'),
        ];
        if (!empty($field['virtual'])) {
            $attrs['data-virtual'] = '1';
        }

        $control = $range
            ? $this->rangeControl($id, $param, $field)
            : $this->control($id, $param, $field);

        $html  = '<div' . $this->attrString($attrs) . '>';
        $html .= sprintf('<label class="form-label" for="%s">%s</label>', $this->escape($id), $this->escape($label));
        $html .= $control;
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $field
     */
    protected function control(string $id, string $param, array $field): string
    {
        $type        = (string) ($field['type'] ?? 'text');
        $value       = $field['default'] ?? '';
        $placeholder = (string) ($field['placeholder'] ?? '');
        $multiple    = !empty($field['multiple']);

        switch ($type) {
            case 'select':
            case 'switch':
                return $this->selectControl($id, $param, $field, $multiple);

            case 'radio':
                return $this->choicesControl($id, $param, $field, 'radio');

            case 'checkbox':
                return $this->choicesControl($id, $param, $field, 'checkbox');

            case 'number':
                return sprintf(
                    '<input type="number" class="form-control form-control-sm" id="%s" name="%s" value="%s"%s step="any">',
                    $this->escape($id),
                    $this->escape($param),
                    $this->escape($this->stringify($value)),
                    $placeholder !== '' ? ' placeholder="' . $this->escape($placeholder) . '"' : ''
                );

            case 'date':
                return $this->dateControl('date', $id, $param, $value, $placeholder);

            case 'datetime':
                return $this->dateControl('datetime', $id, $param, $value, $placeholder);

            case 'time':
                return $this->dateControl('time', $id, $param, $value, $placeholder);

            case 'text':
            default:
                return $this->inputControl('text', $id, $param, $value, $placeholder);
        }
    }

    /**
     * 区间控件。
     *
     * - `date`：ws-date-picker 的**单框 range 模式**（双面板，选完两端自动确认并回写
     *   `起始 至 结束` 整串）。一个 input 承载两端，交互上比"两个输入 + 文字 至"更紧凑。
     * - `datetime` / `time`：仍是两端独立输入 —— range 面板只选日期，时分秒会被
     *   格式化成 `00:00:00`，反而丢精度。
     * - `number`：两个数字输入。
     *
     * @param array<string, mixed> $field
     */
    protected function rangeControl(string $id, string $param, array $field): string
    {
        $type = (string) ($field['type'] ?? 'date');

        if ($type === 'date') {
            $value = $field['default'] ?? '';
            if (is_array($value)) {
                $value = implode(self::RANGE_SEPARATOR, array_map(
                    static fn ($v): string => (string) $v,
                    $value
                ));
            }

            $extra = ' data-range="true"'
                . ' data-range-separator="' . $this->escape(self::RANGE_SEPARATOR) . '"'
                . ' data-end-of-day="1"'
                . ' aria-label="起始至结束"';

            return $this->dateControl('date', $id, $param, $value, '', $extra);
        }

        $params = $this->escape($param) . '[]';

        if (in_array($type, ['datetime', 'time'], true)) {
            $from = $this->dateControl($type, $id . '-from', $params, '', '', ' aria-label="起始"');
            $to = $this->dateControl($type, $id . '-to', $params, '', '', ' aria-label="结束"');

            return '<div class="input-group input-group-sm">'
                . $from
                . '<span class="input-group-text">至</span>'
                . $to
                . '</div>';
        }

        $htmlType = $type === 'number' ? 'number' : 'date';

        $from = sprintf(
            '<input type="%s" class="form-control" id="%s-from" name="%s" aria-label="起始">',
            $htmlType,
            $this->escape($id),
            $params
        );

        $endExtra = '';
        $to = sprintf(
            '<input type="%s" class="form-control" id="%s-to" name="%s" aria-label="结束"%s>',
            $htmlType,
            $this->escape($id),
            $params,
            $endExtra
        );

        return '<div class="input-group input-group-sm">'
            . $from
            . '<span class="input-group-text">至</span>'
            . $to
            . '</div>';
    }

    /**
     * @param array<string, mixed> $field
     */
    protected function selectControl(string $id, string $param, array $field, bool $multiple): string
    {
        $type    = (string) ($field['type'] ?? 'select');
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];

        if ($type === 'switch') {
            $options = ['1' => '是', '0' => '否'];
        }

        $name = $multiple
            ? $this->escape($param) . '[]'
            : $this->escape($param);

        $upgrade = $type === 'select' ? ' data-role="wsselect" data-size="small"' : '';

        $attrs = sprintf(
            'class="form-select form-select-sm" id="%s" name="%s"%s%s',
            $this->escape($id),
            $name,
            $multiple ? ' multiple' : '',
            $upgrade
        );

        $html = '<select ' . $attrs . '>';

        if (!$multiple) {
            $empty = (string) ($field['placeholder'] ?? '');
            if ($empty === '') {
                $empty = '全部';
            }
            $html .= '<option value="">' . $this->escape($empty) . '</option>';
        }

        foreach ($options as $optionValue => $optionLabel) {
            $html .= sprintf(
                '<option value="%s"%s>%s</option>',
                $this->escape((string) $optionValue),
                $this->isSelected($field['default'] ?? '', $optionValue) ? ' selected' : '',
                $this->escape((string) $optionLabel)
            );
        }

        return $html . '</select>';
    }

    /**
     * @param array<string, mixed> $field
     */
    protected function choicesControl(string $id, string $param, array $field, string $type): string
    {
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $name    = $this->escape($param) . '[]';

        $html = '<div class="d-flex flex-wrap gap-3 pt-1">';
        foreach ($options as $optionValue => $optionLabel) {
            $optionId = $id . '-' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $optionValue);
            $html .= sprintf(
                '<div class="form-check form-check-inline mb-0">'
                . '<input class="form-check-input" type="%s" id="%s" name="%s" value="%s"%s>'
                . '<label class="form-check-label" for="%s">%s</label></div>',
                $type,
                $this->escape($optionId),
                $name,
                $this->escape((string) $optionValue),
                $this->isSelected($field['default'] ?? '', $optionValue) ? ' checked' : '',
                $this->escape($optionId),
                $this->escape((string) $optionLabel)
            );
        }

        return $html . '</div>';
    }

    /**
     * 日期 / 时间：ws-date-picker。
     *
     * 搜索区是「选完就该查」的场景，因此 date 关掉确定按钮
     * （`data-show-confirm="false"` 时组件选完即确认、关闭并派发 change）；
     * datetime 是两段式（先选日期再选时间）、time 需要选完时分，两者仍需确定按钮。
     *
     * 宿主保持原生 `input` + 原 `name`，所以 `filterParams()` 那套按 name 收集
     * 查询参数的逻辑完全不用改。
     */
    protected function dateControl(
        string $type,
        string $id,
        string $param,
        mixed $value,
        string $placeholder,
        string $extra = ''
    ): string {
        $config = match ($type) {
            'time'     => ' data-type="time" data-format="HH:mm"',
            'datetime' => ' data-type="datetime" data-format="yyyy-MM-dd HH:mm:ss"',
            default    => ' data-format="yyyy-MM-dd" data-show-confirm="false"',
        };

        return sprintf(
            '<input type="text" class="form-control form-control-sm" id="%s" name="%s" value="%s" data-role="wsdatepicker"%s%s%s>',
            $this->escape($id),
            $this->escape($param),
            $this->escape($this->stringify($value)),
            $config,
            $placeholder !== '' ? ' placeholder="' . $this->escape($placeholder) . '"' : '',
            $extra
        );
    }

    protected function inputControl(string $htmlType, string $id, string $param, mixed $value, string $placeholder): string
    {
        return sprintf(
            '<input type="%s" class="form-control form-control-sm" id="%s" name="%s" value="%s"%s>',
            $htmlType,
            $this->escape($id),
            $this->escape($param),
            $this->escape($this->stringify($value)),
            $placeholder !== '' ? ' placeholder="' . $this->escape($placeholder) . '"' : ''
        );
    }

    /**
     * @param array<string, mixed> $field
     */
    protected function hiddenInput(string $name, array $field): string
    {
        $value = $this->stringify($field['default'] ?? '');
        if ($value === '') {
            return '';
        }

        $param = (string) ($field['param'] ?? ($name . '__eq'));

        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            $this->escape($param),
            $this->escape($value)
        );
    }

    /**
     * 操作区：查询 + 重置。按钮一律 type="button"，避免整页跳转＼�。
     *
     * @param array<string, mixed> $context
     */
    protected function actions(array $context): string
    {
        $submitText = (string) ($context['submitText'] ?? '查询');
        $resetText  = (string) ($context['resetText'] ?? '重置');

        return '<div class="col-12 col-sm-auto ws-filter-actions ms-auto">'
            . '<button type="button" class="btn btn-sm btn-primary ws-filter-submit">'
            . '<i class="bi bi-search me-1"></i>' . $this->escape($submitText) . '</button>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary ws-filter-reset">'
            . $this->escape($resetText) . '</button>'
            . '</div>';
    }


    /**
     * @param array<string, string> $attrs
     */
    protected function attrString(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $key => $value) {
            $out .= ' ' . $key . '="' . $this->escape((string) $value) . '"';
        }
        return $out;
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    protected function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return implode(',', array_map(static fn ($v): string => (string) $v, $value));
        }

        return $value === null ? '' : (string) $value;
    }

    protected function isSelected(mixed $current, mixed $candidate): bool
    {
        if (is_array($current)) {
            return in_array((string) $candidate, array_map('strval', $current), true);
        }

        return $current !== '' && $current !== null && (string) $current === (string) $candidate;
    }
}
