<?php

declare(strict_types=1);

namespace wise\plugin\extract;

/**
 * 命名空间改写器（`plugin:extract` 专用，**token 级、零依赖**）。
 *
 * ## 为什么不上 php-parser
 *
 * 方桼�理由是「需要改写命名空间」。但实测这里
 * 需要的改写只有两类，**都在文件头部**：
 *  1. `namespace app\admin\controller;` 这一行；
 *  2. 顶部 `use app\common\model\Order as OrderModel;` 这类导入。
 *
 * 用 `token_get_all()` 就够了 —— 它是 PHP 自带的词法器，**不执行代码、不解析语义**，
 * 而且能保证字符串/注释里的 `app\...` 一个字都不动。引入 php-parser 的代价是给
 * **所有站点**多加一个 composer 依赖，只为少数搬运代码的作者，与「插件工具链不得给全体
 * 用户加成本」的原则冲突。
 *
 * ## 明确的边界（不假装能做 AST 重写）
 *
 * - 只改 `namespace` 声明与顶部 `use` 导入的**前缀**；
 * - 函数体里的完全限定引用（`\app\common\model\Order::find()`）**不改**，只作为
 *   `body_refs` 返回，由调用方提示人工确认；
 * - 别名（`as X`）、分组 use 的 `{...}`、注释与缩进原样保留；
 * - 映射表命不中的 `app\...` 名字进入 `unmapped`（不静默保留成"看起来对、实际错"）。
 */
final class NamespaceRewriter
{
    /**
     * 前缀映射（`from\` => `to\`），已按 from 长度降序排列（最长前缀优先）。
     *
     * @var array<string, string>
     */
    protected array $map = [];

    /**
     * 需要"报告未映射"的命名空间前缀（默认 app\ 下的类）。
     *
     * @var array<int, string>
     */
    protected array $watchPrefixes;

    /**
     * @param array<string, string> $map           命名空间前缀映射，如 `['app\common\model' => 'plugin\demo\model']`
     * @param array<int, string>    $watchPrefixes 未被映射时需要提示的前缀
     */
    public function __construct(array $map, array $watchPrefixes = ['app\\'])
    {
        $normalized = [];

        foreach ($map as $from => $to) {
            $from = trim((string) $from, '\\');
            $to   = trim((string) $to, '\\');

            if ($from !== '' && $to !== '') {
                $normalized[$from] = $to;
            }
        }

        uksort($normalized, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $this->map = $normalized;

        $this->watchPrefixes = array_values(array_filter(array_map(
            static fn ($prefix): string => trim((string) $prefix, '\\') . '\\',
            $watchPrefixes
        )));
    }

    /**
     * 改写一段 PHP 源码。
     *
     * @return array{
     *     code: string,
     *     replaced: int,
     *     unmapped: array<int, string>,
     *     body_refs: array<int, string>
     * }
     */
    public function rewrite(string $code): array
    {
        $tokens   = token_get_all($code);
        $replaced = 0;
        $unmapped = [];
        $bodyRefs = [];
        $header   = true;
        $count    = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            $id = $token[0];

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION], true)) {
                $header = false;

                continue;
            }

            if (!$header) {
                if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $name = ltrim($token[1], '\\');

                    if ($this->isWatched($name)) {
                        $bodyRefs[] = $name;
                    }
                }

                continue;
            }

            if ($id === T_NAMESPACE) {
                $i = $this->rewriteUntil($tokens, $i + 1, [';', '{'], $replaced, $unmapped);

                continue;
            }

            if ($id === T_USE) {
                $i = $this->rewriteUntil($tokens, $i + 1, [';'], $replaced, $unmapped);
            }
        }

        return [
            'code'      => $this->stringify($tokens),
            'replaced'  => $replaced,
            'unmapped'  => array_values(array_unique($unmapped)),
            'body_refs' => array_values(array_unique($bodyRefs)),
        ];
    }

    /**
     * 从 `$start` 起扫描一段「名字上下文」，逐段替换命中的前缀。
     *
     * 遇到终止符（`;` / `{`）立即返回其下标，调用方的 for 循环会从下一个 token 继续 ——
     * 这样 namespace 的 `{` 与 use 的 `;` 都不会被"吃掉"。
     *
     * @param array<int, mixed> $tokens
     * @param array<int, string> $terminators
     *
     * @return int 终止符下标（未找到时返回 token 总数）
     */
    protected function rewriteUntil(
        array &$tokens,
        int $start,
        array $terminators,
        int &$replaced,
        array &$unmapped
    ): int {
        $count  = count($tokens);
        $buffer = [];
        $name   = '';

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                $this->flushName($tokens, $buffer, $name, $replaced, $unmapped);

                if (in_array($token, $terminators, true)) {
                    return $i;
                }

                continue;
            }

            $id = $token[0];

            if (in_array($id, [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $buffer[] = $i;
                $name    .= $token[1];

                continue;
            }

            $this->flushName($tokens, $buffer, $name, $replaced, $unmapped);

            if ($id === T_CLASS) {
                return $i;
            }
        }

        return $count;
    }

    /**
     * 刷新当前名字段：命中前缀则改写 token，未命中则记录 watched 名字。
     *
     * @param array<int, mixed> $tokens
     * @param array<int, int>   $buffer
     * @param array<int, string> $unmapped
     */
    protected function flushName(
        array &$tokens,
        array &$buffer,
        string &$name,
        int &$replaced,
        array &$unmapped
    ): void {
        if ($buffer === [] || $name === '') {
            $buffer = [];
            $name   = '';

            return;
        }

        $mapped = $this->mapName($name);

        if ($mapped === null) {
            if ($this->isWatched($name)) {
                $unmapped[] = $name;
            }
        } elseif ($mapped !== $name) {
            $first = array_shift($buffer);

            if ($first !== null) {
                $tokens[$first][1] = $mapped;

                foreach ($buffer as $index) {
                    if (is_array($tokens[$index])) {
                        $tokens[$index][1] = '';
                    }
                }

                $replaced++;
            }
        }

        $buffer = [];
        $name   = '';
    }

    /**
     * 按最长前缀替换名字；未命中返回 null。
     */
    protected function mapName(string $name): ?string
    {
        $normalized = ltrim($name, '\\');

        foreach ($this->map as $from => $to) {
            if (strcasecmp($normalized, $from) === 0) {
                return $to;
            }

            if (stripos($normalized, $from . '\\') === 0) {
                return $to . '\\' . substr($normalized, strlen($from) + 1);
            }
        }

        return null;
    }

    protected function isWatched(string $name): bool
    {
        $normalized = ltrim($name, '\\') . '\\';

        foreach ($this->watchPrefixes as $prefix) {
            if (stripos($normalized, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * token 数组 → 源码（字符串 token 原样拼接）。
     *
     * @param array<int, mixed> $tokens
     */
    protected function stringify(array $tokens): string
    {
        $code = '';

        foreach ($tokens as $token) {
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
