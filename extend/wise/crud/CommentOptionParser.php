<?php
declare(strict_types=1);

namespace wise\crud;

/**
 * CommentOptionParser — 从列注释**宽松**解析枚举选项。
 *
 * 设计立场见 docs/wiseadmin-crud-field-mapping.md §7：
 *   **对外文档推荐一种格式，但解析器尽量宽容**，因为开发者习惯不同。
 *
 * 推荐格式（不强求）：
 * ```
 * 标签:0=禁用,1=启用
 * ```
 *
 * 实际支持的分隔与写法：
 * | 维度 | 支持 |
 * |:---|:---|
 * | 键值分隔 | `=` **或** `:` |
 * | 项分隔 | `,` / `，` / `;` / `；` / `、` / 空格 |
 * | 标签前缀 | 可选（`状态:0=禁用,1=启用` / `状态 0:禁用,1=启用` / 直接 `0:禁用,1:启用`） |
 * | 键值顺序 | `0=禁用`（键在前）**或** `启用=1`（值在前，无标签时） |
 *
 * 必须正确解析的本项目存量写法（全部不含 `=`）：
 * ```
 * 状态 0:禁用,1:启用            → [0 => 禁用, 1 => 启用]
 * 是否默认 0:否;1:是             → [0 => 否, 1 => 是]
 * 是否收费 0:免费 1:收费          → [0 => 免费, 1 => 收费]
 * 状态 0:禁用，1:启用，2:未安装    → [0 => 禁用, 1 => 启用, 2 => 未安装]
 * ```
 *
 * 解析失败（有效键值对 < 2）返回空数组 —— **调用方必须显式回退**，不得静默当作"无枚举"。
 */
final class CommentOptionParser
{
    /** 判定为枚举注释所需的最小键值对数量 */
    private const MIN_PAIRS = 2;

    /**
     * 解析列注释为选项表。
     *
     * @param string $comment 列注释原文
     * @return array<string, string> key => label；无法解析时返回 []
     */
    public static function parse(string $comment): array
    {
        $normalized = self::normalize($comment);

        if ($normalized === '') {
            return [];
        }

        // 先按"无标签"解析；不足 MIN_PAIRS 再尝试剥离标签后解析。
        // 顺序不可颠倒：`状态:0=禁用,1=启用` 若先剥离标签会正确，
        // 但 `启用=1,禁用=0` 剥离标签反而会破坏，故以"能解析出更多项"为准。
        $best = [];

        foreach ([$normalized, self::stripLabel($normalized)] as $candidate) {
            $options = self::parseTokens($candidate);

            if (count($options) > count($best)) {
                $best = $options;
            }

            if (count($best) >= self::MIN_PAIRS) {
                break;
            }
        }

        return count($best) >= self::MIN_PAIRS ? $best : [];
    }

    /**
     * 归一化：全角标点转半角、连续空白归一。
     */
    private static function normalize(string $comment): string
    {
        $comment = str_replace(
            ['，', '；', '：', '、', '　'],
            [',', ';', ':', ' ', ' '],
            $comment
        );

        return trim(preg_replace('/\s+/u', ' ', $comment) ?? '');
    }

    /**
     * 剥离可选的前置标签。
     *
     * `状态:0=禁用,1=启用` → `0=禁用,1=启用`
     * `状态 0:禁用,1:启用` → `0:禁用,1:启用`
     * `0:禁用,1:启用`      → 不变（首字符是数字，无标签）
     */
    private static function stripLabel(string $comment): string
    {
        if (preg_match('/^([^:=\d]+)[:=\s]\s*(.+)$/u', $comment, $matches) === 1) {
            return trim($matches[2]);
        }

        return $comment;
    }

    /**
     * 按项分隔符切分并逐项解析。
     *
     * @return array<string, string>
     */
    private static function parseTokens(string $text): array
    {
        $tokens = preg_split('/[,; ]+/u', $text);

        if ($tokens === false) {
            return [];
        }

        $options = [];

        foreach ($tokens as $token) {
            [$key, $value] = self::splitPair($token);

            if ($key === null || $value === '') {
                continue;
            }

            if (is_numeric($key)) {
                // 键在前：0=禁用 / 0:禁用
                $options[(string) $key] = $value;
            } elseif (is_numeric($value)) {
                // 值在前：启用=1
                $options[(string) $value] = $key;
            } elseif (!str_contains($value, '=') && !str_contains($value, ':')) {
                // 字符串键值对（enum 的取值是字符串）：
                // 菜单类型 dir:目录,menu:菜单,button:按钮  → [dir => 目录, menu => 菜单, ...]
                //
                // 值中若仍含分隔符，说明这是「标签:首个键值对」的粘连形态
                // （如 `标签:0=禁用`），跳过以免把标签当成键；
                // 真正的解析由 stripLabel() 后的候选完成。
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * 在第一个出现的 `=` 或 `:` 处拆分。
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function splitPair(string $token): array
    {
        if (!str_contains($token, '=') && !str_contains($token, ':')) {
            return [null, null];
        }

        // 取 `=` 与 `:` 中更靠前的那个作为分隔符
        $positions = [];

        foreach (['=', ':'] as $separator) {
            $position = strpos($token, $separator);

            if ($position !== false && $position > 0) {
                $positions[] = $position;
            }
        }

        if ($positions === []) {
            return [null, null];
        }

        $position = min($positions);

        return [
            trim(substr($token, 0, $position)),
            trim(substr($token, $position + 1)),
        ];
    }
}
