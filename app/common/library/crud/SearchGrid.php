<?php
declare(strict_types=1);

namespace app\common\library\crud;

/**
 * SearchGrid — 把「控件舒适像素带宽」换算成响应式栅格类＼�。
 *
 * 为什么需要它：`col-lg-{span}` 只在 992px 定格一次，之后栅格数不变，
 * 字段像素宽度随视口线性放大 —— 2K 屏上 `col-lg-3` 会变成 570px 的长条；
 * 反过来 `col-md-6` 在 768~991 区间把所有短下拉都拉成半屏。固定 span
 * 无法同时满足「不撑太宽」和「不挤太窄」。
 *
 * 三层换算：
 *   1. **带宽**：每个字段声明 `[minWidth, maxWidth]`（px，来自 SearchField 的类型默认值
 *      或 `->width()`），这是「视觉舒适区」，与屏幕无关；
 *   2. **断点矩阵**：对 6 个断点各自的内容可用宽度求 span，使
 *      `内容宽 × span / 12` 落在带宽内 —— 于是屏幕越宽，span 越小（每行放更多字段），
 *      而不是字段被同比拉长；
 *   3. **行内均衡**：每行字段数由「内容宽 ÷ 平均最大宽」决定，行内把 12 栅格按带宽
 *      缩放补满（上限 maxSpan），避免 `4+3+3=10` 这种行尾孤儿。
 *
 * 输出只有 Bootstrap 5 栅格类 + 一个宽度档位类（`.ws-filter-w-*`，见 base.css：
 * 给 ≥1920px 这种栅格类覆盖不到的区间做 max-width 兜底），不产生内联样式。
 *
 * @see SearchRenderer
 * @see SearchField
 */
final class SearchGrid
{
    /**
     * 断点 → 内容可用宽度（px，估算值）。
     *
     * 后台是 fluid 布局：内容宽 ≈ 视口宽 − AdminLTE 侧栏(250px) − 页面 padding(32px)；
     * lg 起侧栏常驻，所以 lg 的可用宽度反而比 md 略小 —— 这是真实情况，span 会
     * 相应放大，不需要额外特判。
     */
    private const BREAKPOINTS = [
        'xs'  => 336,
        'sm'  => 552,
        'md'  => 744,
        'lg'  => 712,
        'xl'  => 920,
        'xxl' => 1120,
    ];

    /** 容器比这个宽度还窄时一律整行：分栏只会让控件更难看 */
    private const STACK_BELOW = 500;

    /** 每行最多字段数（再多就只剩标签宽度了） */
    private const MAX_PER_ROW = 6;

    /**
     * 宽度档位：`字段 maxWidth ≤ 上限` 时使用的兜底类（CSS 里的 max-width 值见 base.css）。
     *
     * 档位值刻意贴紧各自带宽上限（而不是统一留 20~40px 余量）：区间控件与文本控件
     * 并排时，档位差一档就会看出「时间框比输入框宽一截」。
     */
    private const WIDTH_CLASSES = [
        [200, 'ws-filter-w-sm'],
        [250, 'ws-filter-w-md'],
        [300, 'ws-filter-w-lg'],
        [360, 'ws-filter-w-xl'],
    ];

    private const WIDTH_CLASS_WIDE = 'ws-filter-w-xxl';

    /**
     * @param array<string, mixed> $fields  Search::toArray()['fields']
     *
     * @return array<string, array{col: string, width: string}> name => 栅格类 / 宽度档位
     */
    public function resolve(array $fields): array
    {
        $items = [];

        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }
            if (($field['type'] ?? 'text') === 'hidden') {
                continue;
            }

            $min = (int) ($field['minWidth'] ?? 160);
            $max = (int) ($field['maxWidth'] ?? 320);

            if ($max <= 0 || $min <= 0) {
                continue;
            }
            if ($max < $min) {
                $max = $min;
            }

            $items[] = [
                'name'  => (string) $name,
                'min'   => $min,
                'max'   => $max,
                'ideal' => (int) round(($min + $max) / 2),
            ];
        }

        if ($items === []) {
            return [];
        }

        $spans = [];
        foreach (self::BREAKPOINTS as $breakpoint => $width) {
            foreach ($this->solve($items, $width) as $index => $span) {
                $spans[$index][$breakpoint] = $span;
            }
        }

        $out = [];
        foreach ($items as $index => $item) {
            $classes = [];
            foreach (array_keys(self::BREAKPOINTS) as $breakpoint) {
                $prefix    = $breakpoint === 'xs' ? 'col-' : 'col-' . $breakpoint . '-';
                $classes[] = $prefix . $spans[$index][$breakpoint];
            }

            $out[$item['name']] = [
                'col'   => implode(' ', $classes),
                'width' => $this->widthClass($item['max']),
            ];
        }

        return $out;
    }

    /**
     * 单个断点下求每个字段的 span。
     *
     * @param array<int, array{name: string, min: int, max: int, ideal: int}> $items
     *
     * @return array<int, int> 字段下标 => 栅格数
     */
    private function solve(array $items, int $width): array
    {
        $count = count($items);

        if ($width < self::STACK_BELOW) {
            return array_fill(0, $count, 12);
        }

        $minSpan = [];
        $maxSpan = [];
        $span    = [];
        $maxSum  = 0;

        foreach ($items as $index => $item) {
            $minSpan[$index] = $this->clamp((int) floor(12 * $item['min'] / $width), 12, 1);
            $maxSpan[$index] = $this->clamp((int) floor(12 * $item['max'] / $width), 12, $minSpan[$index]);
            $span[$index]    = $this->clamp(
                (int) round(12 * $item['ideal'] / $width),
                $maxSpan[$index],
                $minSpan[$index]
            );

            $maxSum += $maxSpan[$index];
        }

        $avgMax = max(1, $maxSum / $count);
        $perRow = (int) max(1, min(self::MAX_PER_ROW, floor($width / $avgMax)));

        $rows = [];
        $row  = [];
        $sum  = 0;

        foreach ($span as $index => $value) {
            if ($row !== [] && ($sum + $value > 12 || count($row) >= $perRow)) {
                $rows[] = [$row, $sum];
                $row    = [];
                $sum    = 0;
            }

            $row[$index] = $value;
            $sum        += $value;
        }

        if ($row !== []) {
            $rows[] = [$row, $sum];
        }

        $out = [];
        foreach ($rows as [$rowSpans, $rowSum]) {
            foreach ($this->balance($rowSpans, $rowSum, $minSpan, $maxSpan) as $index => $value) {
                $out[$index] = $value;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * 行内均衡：先补满 12（不超各自 maxSpan），补不满就保留（右侧留给操作按钮）。
     *
     * @param array<int, int> $row     字段下标 => span
     * @param array<int, int> $minSpan
     * @param array<int, int> $maxSpan
     *
     * @return array<int, int>
     */
    private function balance(array $row, int $sum, array $minSpan, array $maxSpan): array
    {
        while ($sum < 12) {
            $target = null;
            foreach ($row as $index => $value) {
                if ($value >= $maxSpan[$index]) {
                    continue;
                }
                if ($target === null || $value < $row[$target]) {
                    $target = $index;
                }
            }

            if ($target === null) {
                break;
            }

            $row[$target]++;
            $sum++;
        }

        while ($sum > 12) {
            $target = null;
            foreach ($row as $index => $value) {
                if ($value <= $minSpan[$index]) {
                    continue;
                }
                if ($target === null || $value > $row[$target]) {
                    $target = $index;
                }
            }

            if ($target === null) {
                break;
            }

            $row[$target]--;
            $sum--;
        }

        return $row;
    }

    /** 字段最大宽度 → 兜底档位类 */
    private function widthClass(int $maxWidth): string
    {
        foreach (self::WIDTH_CLASSES as [$limit, $class]) {
            if ($maxWidth <= $limit) {
                return $class;
            }
        }

        return self::WIDTH_CLASS_WIDE;
    }

    private function clamp(int $value, int $max, int $min = 1): int
    {
        return max($min, min($max, $value));
    }
}
