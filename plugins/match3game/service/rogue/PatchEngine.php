<?php
/**
 * Match3 Patch Engine · 遗物系统核心（PHP 版）
 *
 * 与 patch-engine.ts 行为逐一对齐，两者共用 test-vectors.json 做一致性校验。
 *
 * 【运行位置】
 *   玩家进入肉鸽节点时，用本类把 base schema + 遗物 patch 合成为【最终 schema】下发给客户端。
 *
 * 【与 TS 版的差异说明（实现时必须注意）】
 *   1. PHP 数组无法区分 {} 与 []，因此推荐用 json_decode($json)（不带 assoc）
 *      让对象解析为 stdClass、数组解析为 array，零歧义。
 *      本类同时支持 stdClass / array 两种容器。
 *   2. 浮点收敛：与 TS 的 Math.round(v*1e6)/1e6 保持一致，
 *      且整数值统一转 int（否则 PHP 输出 2.0 而 TS 输出 2）。
 *   3. 移除数组元素必须用 array_splice 重排索引（对齐 TS 的 splice），
 *      不能用 unset（会留下空洞并被误判为对象）。
 *
 * 迁移自 docs/match3/code/PatchEngine.php（仅改命名空间）。
 */

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

class PatchEngine
{
    public const OP_ADD     = 'add';
    public const OP_REPLACE = 'replace';
    public const OP_REMOVE  = 'remove';
    public const OP_MUL     = 'mul';

    public const MODE_ACCUMULATE = 'accumulate';
    public const MODE_OVERRIDE   = 'override';

    /** 标记"键不存在"（区别于值为 null） */
    private const UNDEFINED = '__PATCH_UNDEFINED__';

    /**
     * 应用一组 patch（不可变，返回新文档）
     *
     * @param  \stdClass|array|scalar|null $doc
     * @param  array<int, array<string,mixed>|\stdClass> $ops
     */
    public static function applyOps($doc, array $ops): PatchResult
    {
        $skipped = [];
        $applied = [];
        $cur     = $doc;

        foreach ($ops as $op) {
            $skippedBefore = count($skipped);
            $cur = self::updateAt(
                $cur,
                self::parsePath((string)self::field($op, 'path')),
                0,
                $op,
                $skipped
            );
            // 未新增 skip 记录 → 该 op 成功生效
            if (count($skipped) === $skippedBefore) {
                $applied[] = $op;
            }
        }

        return new PatchResult($cur, $applied, $skipped);
    }

    /**
     * 按获得顺序应用多个遗物
     *
     * 顺序敏感：A→B 与 B→A 结果可能不同，顺序必须与记录一致
     *
     * @param  \stdClass|array|scalar|null $doc
     * @param  array<int, array<string, mixed>> $relics 每个含 id / patch
     */
    public static function applyRelics($doc, array $relics): PatchResult
    {
        $skipped = [];
        $applied = [];
        $cur     = $doc;

        foreach ($relics as $relic) {
            $result  = self::applyOps($cur, self::field($relic, 'patch') ?? []);
            $cur     = $result->doc;
            $applied = array_merge($applied, $result->applied);
            foreach ($result->skipped as $s) {
                $s['relicId'] = self::field($relic, 'id');
                $skipped[]    = $s;
            }
        }

        return new PatchResult($cur, $applied, $skipped);
    }

    /**
     * 解析 JSON Pointer（扩展版，支持数组选择器 [id=xxx]）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parsePath(string $path): array
    {
        if ($path === '') {
            throw new PatchException('path 不能为空');
        }
        if ($path === '/') {
            return [];
        }
        if ($path[0] !== '/') {
            throw new PatchException("path 必须以 / 开头: {$path}", $path);
        }

        $tokens = [];
        foreach (explode('/', substr($path, 1)) as $raw) {
            $part = str_replace(['~1', '~0'], ['/', '~'], $raw);

            // 数组选择器：[id=choc]
            if (preg_match('/^\[([A-Za-z_][\w-]*)=([^\]]*)\]$/', $part, $m)) {
                $tokens[] = ['type' => 'selector', 'field' => $m[1], 'value' => $m[2]];
                continue;
            }
            // 数组下标
            if (preg_match('/^(0|[1-9]\d*)$/', $part)) {
                $tokens[] = ['type' => 'index', 'index' => (int)$part];
                continue;
            }
            $tokens[] = ['type' => 'key', 'key' => $part];
        }

        return $tokens;
    }

    /* ======================================================================
     *  内部实现
     * ====================================================================== */

    /**
     * @param array<int, array<string, mixed>> $tokens
     * @param array<string, mixed>|\stdClass $op  （支持 assoc 数组与 stdClass 两种形态）
     * @param array<int, array<string, mixed>> $skipped
     */
    private static function updateAt($node, array $tokens, int $i, $op, array &$skipped)
    {
        $t      = $tokens[$i];
        $isLast = ($i === count($tokens) - 1);
        $path   = (string)self::field($op, 'path');

        if ($t['type'] === 'key') {
            $key = $t['key'];
        } elseif ($t['type'] === 'index') {
            $key = $t['index'];
        } else {
            // 数组选择器：按字段定位
            if (!is_array($node)) {
                throw new PatchException(
                    "选择器 [{$t['field']}={$t['value']}] 只能作用于数组: {$path}",
                    $path
                );
            }
            $idx = null;
            foreach ($node as $i2 => $el) {
                $v = self::getChild($el, $t['field']);
                if ($v !== self::UNDEFINED && (string)$v === (string)$t['value']) {
                    $idx = $i2;
                    break;
                }
            }
            if ($idx === null) {
                $skipped[] = [
                    'op'     => $op,
                    'reason' => "选择器 [{$t['field']}={$t['value']}] 未匹配，跳过",
                ];
                return $node;
            }
            $key = $idx;
        }

        if ($isLast) {
            return self::applyLeaf($node, $key, $op, $skipped);
        }

        // 中间节点
        $child = self::getChild($node, $key);

        if ($child === self::UNDEFINED) {
            if (self::field($op, 'op') !== self::OP_ADD) {
                $skipped[] = ['op' => $op, 'reason' => "路径不存在: {$path}"];
                return $node;
            }
            // add 时自动创建中间容器
            $next  = $tokens[$i + 1];
            $child = in_array($next['type'], ['index', 'selector'], true) ? [] : new \stdClass();
        }

        $newChild = self::updateAt($child, $tokens, $i + 1, $op, $skipped);
        return self::setChild($node, $key, $newChild);
    }

    /**
     * @param array<string, mixed>|\stdClass $op
     * @param array<int, array<string, mixed>> $skipped
     */
    private static function applyLeaf($node, $key, $op, array &$skipped)
    {
        $cur    = self::getChild($node, $key);
        $path   = (string)self::field($op, 'path');
        $opName = (string)self::field($op, 'op');
        $value  = self::field($op, 'value');

        switch ($opName) {
            case self::OP_REMOVE:
                if ($cur === self::UNDEFINED) {
                    $skipped[] = ['op' => $op, 'reason' => 'remove 目标不存在（幂等跳过）'];
                    return $node;
                }
                return self::removeChild($node, $key);

            case self::OP_REPLACE:
                if ($cur === self::UNDEFINED) {
                    throw new PatchException("replace 目标不存在: {$path}", $path);
                }
                return self::setChild($node, $key, $value);

            case self::OP_MUL:
                if (!self::isNumber($value)) {
                    throw new PatchException("mul 的 value 必须是数字: {$path}", $path);
                }
                // 目标字段不存在时以 1 为基准（乘法单位元）。
                // 原因：关卡模板未必预置 scoreMul / coinMul 等可选字段，
                //       若直接抛异常，玩家拿到对应遗物就进不去关卡。
                $curNum = ($cur === self::UNDEFINED) ? 1 : $cur;
                if (!self::isNumber($curNum)) {
                    throw new PatchException("mul 要求目标为数字: {$path}", $path);
                }
                return self::setChild($node, $key, self::roundNumber($curNum * $value));

            case self::OP_ADD:
                $mode = self::field($op, 'mode') ?? self::MODE_OVERRIDE;

                if ($mode === self::MODE_ACCUMULATE) {
                    // 数字累加
                    if (self::isNumber($cur) && self::isNumber($value)) {
                        return self::setChild($node, $key, self::roundNumber($cur + $value));
                    }
                    // 数组拼接
                    if (is_array($cur) && self::has($op, 'value')) {
                        $add = is_array($value) ? array_values($value) : [$value];
                        return self::setChild($node, $key, array_merge($cur, $add));
                    }
                    // 其余情况退化为覆盖
                }
                return self::setChild($node, $key, $value);

            default:
                throw new PatchException("未知操作: {$opName}", $path);
        }
    }

    /* ---------------- 容器读写（全部返回新值，不修改入参） ---------------- */

    private static function getChild($node, $key)
    {
        if ($node instanceof \stdClass) {
            $k = (string)$key;
            return property_exists($node, $k) ? $node->$k : self::UNDEFINED;
        }
        if (is_array($node)) {
            return array_key_exists($key, $node) ? $node[$key] : self::UNDEFINED;
        }
        return self::UNDEFINED;
    }

    private static function setChild($node, $key, $value)
    {
        if ($node instanceof \stdClass) {
            $clone = clone $node;
            $clone->{(string)$key} = $value;
            return $clone;
        }
        if (is_array($node)) {
            $copy = $node;
            if (is_int($key) && $key === count($copy)) {
                $copy[] = $value;
            } elseif (is_int($key) && $key >= 0 && $key < count($copy)) {
                $copy[$key] = $value;
            } elseif (is_string($key)) {
                $copy[$key] = $value;
            } else {
                throw new PatchException('无法设置数组下标: ' . (string)$key);
            }
            return $copy;
        }
        throw new PatchException('无法对 ' . get_debug_type($node) . ' 设置子字段');
    }

    private static function removeChild($node, $key)
    {
        if ($node instanceof \stdClass) {
            $clone = clone $node;
            unset($clone->{(string)$key});
            return $clone;
        }
        if (is_array($node)) {
            $copy = $node;
            if (is_int($key) && $key >= 0 && $key < count($copy)) {
                // 必须用 splice 重排索引，对齐 TS 的 splice 语义
                array_splice($copy, $key, 1);
            } elseif (is_string($key)) {
                $copy2 = $copy;
                unset($copy2[$key]);
                $copy = $copy2;
            }
            return $copy;
        }
        return $node;
    }

    /* ---------------- 工具 ---------------- */

    private static function isNumber($v): bool
    {
        return is_int($v) || is_float($v);
    }

    /**
     * 浮点收敛（对齐 TS 的 Math.round(v * 1e6) / 1e6）
     * 整数值统一转 int，避免 PHP 输出 2.0 而 TS 输出 2
     */
    private static function roundNumber($v)
    {
        $r = round($v * 1e6) / 1e6;
        if (is_finite($r) && $r == (int)$r && abs($r) < 1e15) {
            return (int)$r;
        }
        return $r;
    }

    /**
     * 取字段：同时支持 array（assoc 模式）与 stdClass（对象模式）
     *
     * 推荐用 json_decode($json) 的对象模式，可零歧义区分 {} 与 []
     */
    private static function field($x, string $key)
    {
        if (is_array($x)) {
            return $x[$key] ?? null;
        }
        if ($x instanceof \stdClass) {
            return $x->$key ?? null;
        }
        return null;
    }

    /**
     * 判断字段是否存在（区别于"存在但值为 null"）
     */
    private static function has($x, string $key): bool
    {
        if (is_array($x)) {
            return array_key_exists($key, $x);
        }
        if ($x instanceof \stdClass) {
            return property_exists($x, $key);
        }
        return false;
    }
}
