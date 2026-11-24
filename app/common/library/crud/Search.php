<?php
declare(strict_types=1);

namespace app\common\library\crud;

use InvalidArgumentException;

/**
 * Search — 搜索条件容器＼�。
 *
 * 只做「声明」：字段集合 + 栅格配置 + 默认显隐。渲染交给 {@see SearchRenderer}，
 * 参数序列化交给 `crud-page.js` 的 `Wise.crud.filterParams()`。
 *
 * 设计边界（v1.1 决策）：
 *   - **只生成 AND 条件**，不生成 `_or` 分组；复杂查询交给表格高级搜索（`AdvancedSearch`）。
 *   - 一个字段 = 一个参数 `{name}__{op}`，不做"一个输入搜多列"的魔法。
 *
 * 用法：
 * ```php
 * protected function defineSearch(Search $search): void
 * {
 *     $search->add(SearchField::make('name', 'text')->label('名称'))
 *            ->add(SearchField::make('status', 'select')->label('状态')
 *                ->options(['1' => '启用', '0' => '禁用']));
 * }
 * ```
 *
 * @see SearchField
 * @see SearchRenderer
 */
final class Search
{
    /** @var array<string, SearchField> */
    private array $fields = [];

    /** 栅格总数（Bootstrap 12 栅格） */
    private int $gridColumns = 12;

    /** 默认显隐：null = 按字段数自动（≤4 展开），true/false = 强制 */
    private ?bool $defaultVisible = null;

    public function add(SearchField $field): self
    {
        $this->fields[$field->getName()] = $field;
        return $this;
    }

    /** 移除条件（配合预设/继承时裁剪） */
    public function remove(string $name): self
    {
        unset($this->fields[$name]);
        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    public function gridColumns(int $columns): self
    {
        if ($columns < 1 || $columns > 24) {
            throw new InvalidArgumentException('gridColumns 取值 1~24');
        }
        $this->gridColumns = $columns;
        return $this;
    }

    /**
     * 默认显隐＼�：不设置时按字段数自动（≤4 展开、>4 收起）。
     * 渲染层会写到表单的 `data-default-visible` 上，由 `crud-page.js` 读取。
     */
    public function defaultVisible(?bool $visible): self
    {
        $this->defaultVisible = $visible;
        return $this;
    }

    /** @return array<string, SearchField> */
    public function getFields(): array
    {
        return $this->fields;
    }

    /** 条件条数（含 hidden），供渲染层/工具做占位判断 */
    public function count(): int
    {
        return count($this->fields);
    }

    /**
     * 默认是否展开＼�。
     *
     * 显式设置优先；未设置时按「可渲染字段 ≤ 4 展开、> 4 收起」判定，
     * hidden 字段不占栅格，不计入。
     */
    public function isDefaultVisible(): bool
    {
        if ($this->defaultVisible !== null) {
            return $this->defaultVisible;
        }

        $visible = 0;
        foreach ($this->fields as $field) {
            if ($field->isRenderable()) {
                $visible++;
            }
        }

        return $visible <= 4;
    }

    public function isEmpty(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->isRenderable()) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{fields: array<string, array<string, mixed>>, layout: array<string, mixed>}
     */
    public function toArray(): array
    {
        $fields = [];
        foreach ($this->fields as $name => $field) {
            $fields[$name] = $field->toArray();
        }

        return [
            'fields' => $fields,
            'layout' => [
                'gridColumns'    => $this->gridColumns,
                'defaultVisible' => $this->defaultVisible,
            ],
        ];
    }
}
