<?php
declare(strict_types=1);

namespace app\common\library\crud;

use InvalidArgumentException;

/**
 * SearchField — 单个搜索条件声明＼�。
 *
 * 与 wise-form-schema 的 `AbstractField` 的分工：
 *   - 表单字段表达「可提交的数据」，有校验规则；
 *   - 搜索条件表达「查询意图」，有**操作符**与**栅格权重**，没有校验。
 * 因此不复用 `AbstractField`，只复用它的类型词汇（`text/select/number/date/...`）。
 *
 * 关键设计：`name` 与 `op` 拼成查询参数名 `{name}__{op}`，与
 * `app\common\library\RequestQueryBuilder::buildWhere()` 的解析规则一一对应
 * （见 `RequestQueryBuilder.php:423-440`）。因此**参数层无需任何字段映射表**。
 *
 * 用法（在控制器的 defineSearch() 里）：
 * ```php
 * $search->add(SearchField::make('status', 'select')->label('状态')
 *     ->options(['1' => '启用', '0' => '禁用'])->span(3));
 * ```
 *
 * @see Search
 * @see SearchRenderer
 */
final class SearchField
{
    /** 支持的条件类型（与前端控件一一对应） */
    public const TYPES = ['text', 'select', 'number', 'date', 'datetime', 'switch', 'hidden', 'radio', 'checkbox'];

    /** RequestQueryBuilder 支持的缩写操作符（`field__op=value` 里的 op） */
    public const OPS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'notin', 'between', 'null', 'notnull'];

    /** 类型 → 默认操作符 */
    private const DEFAULT_OPS = [
        'text'     => 'like',
        'select'   => 'eq',
        'radio'    => 'eq',
        'switch'   => 'eq',
        'number'   => 'eq',
        'date'     => 'eq',
        'datetime' => 'eq',
        'hidden'   => 'eq',
        'checkbox' => 'in',
    ];

    /** 类型 → 默认栅格宽度（12 栅格制；仅作 schema 提示，实际排版见 SearchGrid） */
    private const DEFAULT_SPANS = [
        'text'     => 4,
        'select'   => 3,
        'radio'    => 3,
        'switch'   => 3,
        'number'   => 3,
        'date'     => 3,
        'datetime' => 3,
        'hidden'   => 0,
        'checkbox' => 3,
    ];

    /**
     * 类型 → 控件「舒适像素带宽」[min, max]。
     *
     * 排版层（{@see SearchGrid}）只认这个带宽，不再认固定 span：
     * `contentWidth × span / 12` 必须落在带宽内，屏幕越宽 span 越小。
     * 数值口径 = 输入框内可读内容的宽度，不含列 gutter。
     */
    private const DEFAULT_WIDTHS = [
        'text'     => [180, 300],
        'select'   => [130, 220],
        'switch'   => [120, 200],
        'radio'    => [150, 260],
        'checkbox' => [160, 280],
        'number'   => [110, 190],
        'date'     => [140, 200],
        'datetime' => [170, 240],
        'hidden'   => [0, 0],
    ];

    /**
     * 区间模式在基础带宽上的增量（多一个「至」/ 第二端输入）。
     *
     * 口径刻意收紧：区间控件的文本长度是固定的（`2026-01-01 至 2026-01-31` 约 170px
     * 含图标与内边距），再宽只会变成一条横贯半屏的空白输入框。
     */
    private const RANGE_WIDTH_EXTRA = [
        'date'     => [80, 100],
        'datetime' => [130, 160],
        'number'   => [50, 80],
    ];

    /** 兼容旧 `->span()`：按 lg 断点的内容宽（约 712px）把栅格数换回像素 */
    private const SPAN_REFERENCE_WIDTH = 712;

    private string $name;

    private string $type;

    private string $label;

    private ?string $op = null;

    private ?int $span = null;

    /** 显式像素带宽 [min, max]（覆盖类型默认值与 span 换算） */
    private ?array $width = null;

    /** @var array<string|int, string> value => label */
    private array $options = [];

    private string $placeholder = '';

    private mixed $default = '';

    /** 区间模式（date / datetime / number：渲染两个输入，合成 between / gte / lte） */
    private bool $range = false;

    private bool $multiple = false;

    /** 非主表字段（关联表 / 计算列）：渲染时打标，debug 下告警 */
    private bool $virtual = false;

    private function __construct(string $name, string $type)
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('SearchField name 不能为空');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'SearchField 类型 "%s" 不支持，可选：%s',
                $type,
                implode(', ', self::TYPES)
            ));
        }

        $this->name  = $name;
        $this->type  = $type;
        $this->label = $name;
    }

    public static function make(string $name, string $type = 'text'): self
    {
        return new self($name, $type);
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    /** 覆盖默认操作符（如把 text 改成 `eq`，或把 select 改成 `in`） */
    public function op(string $op): self
    {
        $op = strtolower(trim($op));
        if (!in_array($op, self::OPS, true)) {
            throw new InvalidArgumentException(sprintf(
                'SearchField 操作符 "%s" 不支持，可选：%s',
                $op,
                implode(', ', self::OPS)
            ));
        }
        $this->op = $op;
        return $this;
    }

    /**
     * 栅格宽度（1~12）；0 表示不占栅格（配合 hidden 使用）。
     *
     * 兼容保留：排版层已改为按像素带宽计算（{@see SearchGrid}），这里只把栅格数
     * 按 lg 断点折算回带宽。新代码请用 {@see width()}。
     */
    public function span(int $span): self
    {
        if ($span < 0 || $span > 12) {
            throw new InvalidArgumentException('SearchField span 取值范围 0~12');
        }
        $this->span = $span;
        return $this;
    }

    /**
     * 控件舒适像素带宽（最小 / 最大宽度，px）。
     *
     * 例：一个带长 placeholder 的文本条件给 `->width(220, 320)`；
     * 一个只放「启用/禁用」的下拉给 `->width(120, 180)`。
     * 排版层保证各断点下的实际宽度落在该区间内（容器过窄时除外）。
     */
    public function width(int $min, int $max): self
    {
        if ($min <= 0 || $max < $min) {
            throw new InvalidArgumentException('SearchField width 需满足 0 < min ≤ max');
        }
        $this->width = [$min, $max];
        return $this;
    }

    /** @param array<string|int, string> $options value => label */
    public function options(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;
        return $this;
    }

    public function default(mixed $default): self
    {
        $this->default = $default;
        return $this;
    }

    /** 区间模式：date/datetime 渲染两个输入；number 渲染 min~max */
    public function range(bool $range = true): self
    {
        $this->range = $range;
        if ($range && $this->op === null) {
            $this->op = 'between';
        }
        return $this;
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;
        return $this;
    }

    /** 标记为非主表字段（关联表别名 / 计算列），渲染时打 data-virtual 便于排障 */
    public function virtual(bool $virtual = true): self
    {
        $this->virtual = $virtual;
        return $this;
    }


    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getOp(): string
    {
        if ($this->op !== null) {
            return $this->op;
        }
        if ($this->multiple && in_array($this->type, ['select', 'checkbox'], true)) {
            return 'in';
        }
        return self::DEFAULT_OPS[$this->type] ?? 'eq';
    }

    public function getSpan(): int
    {
        if ($this->span !== null) {
            return $this->span;
        }
        if ($this->type === 'hidden') {
            return 0;
        }
        if ($this->range) {
            return in_array($this->type, ['datetime'], true) ? 6 : 5;
        }
        return self::DEFAULT_SPANS[$this->type] ?? 3;
    }

    /** 控件最小舒适宽度（px），排版层用 */
    public function getMinWidth(): int
    {
        return $this->resolveWidth()[0];
    }

    /** 控件最大舒适宽度（px），排版层用 */
    public function getMaxWidth(): int
    {
        return $this->resolveWidth()[1];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveWidth(): array
    {
        if ($this->width !== null) {
            return $this->width;
        }

        if ($this->type === 'hidden' || $this->span === 0) {
            return [0, 0];
        }

        if ($this->span !== null) {
            $pixels = (int) round(self::SPAN_REFERENCE_WIDTH * $this->span / 12);
            return [max(120, (int) round($pixels * 0.8)), (int) round($pixels * 1.2)];
        }

        [$min, $max] = self::DEFAULT_WIDTHS[$this->type] ?? [160, 320];

        if ($this->range) {
            [$extraMin, $extraMax] = self::RANGE_WIDTH_EXTRA[$this->type] ?? [100, 140];
            $min += $extraMin;
            $max += $extraMax;
        }

        if ($this->multiple && in_array($this->type, ['select', 'checkbox'], true)) {
            $min += 40;
            $max += 60;
        }

        return [$min, $max];
    }

    /** @return array<string|int, string> */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getPlaceholder(): string
    {
        return $this->placeholder;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function isRange(): bool
    {
        return $this->range;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function isVirtual(): bool
    {
        return $this->virtual;
    }

    /** 是否渲染控件（hidden 不占栅格、不出现在筛选条里） */
    public function isRenderable(): bool
    {
        return $this->type !== 'hidden' && $this->getSpan() > 0;
    }

    /**
     * 查询参数名：`{name}__{op}`。
     * 多值（区间 / 多选）由渲染层补 `[]`，由 PHP 自动收成数组。
     */
    public function paramName(): string
    {
        return $this->name . '__' . $this->getOp();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'type'        => $this->type,
            'label'       => $this->label,
            'op'          => $this->getOp(),
            'span'        => $this->getSpan(),
            'minWidth'    => $this->getMinWidth(),
            'maxWidth'    => $this->getMaxWidth(),
            'param'       => $this->paramName(),
            'placeholder' => $this->placeholder,
            'default'     => $this->default,
            'range'       => $this->range,
            'multiple'    => $this->multiple,
            'virtual'     => $this->virtual,
            'options'     => $this->options,
        ];
    }
}
