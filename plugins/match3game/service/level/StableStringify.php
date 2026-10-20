<?php
/**
 * 稳定序列化 / 指纹（PHP 版，与 patch-engine.ts 完全一致）
 *
 * 用途：
 *   1. TS 与 PHP 两份 Patch 引擎的结果比对（跑同一份 test-vectors.json）
 *   2. 对局校验：服务端与客户端对同一棋盘计算 fingerprint，快速发现分歧
 *   3. 关卡 schema 指纹：用于同 seed 生成结果一致性断言（02 §7 / 05 B8.1）
 *
 * 关键对齐点（否则指纹对不上）：
 *   - JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES：不转义中文与斜杠
 *   - 对象按 key 字典序排序（对齐 JS Object.keys().sort()）
 *   - 浮点整数值输出整数形式（PHP 2.0 ↔ TS 2）
 *   - fingerprint 按 UTF-16 码元遍历（对齐 JS charCodeAt，中文才不会错）
 *
 * 迁移自 docs/match3/code/StableStringify.php（仅改命名空间）。
 */

declare(strict_types=1);

namespace plugin\match3game\service\level;

class StableStringify
{
    /**
     * 稳定序列化（对象 key 排序，忽略原始顺序）
     */
    public static function run($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string)$v;
        }
        if (is_float($v)) {
            return self::floatToString($v);
        }
        if (is_string($v)) {
            return self::json($v);
        }
        if (is_array($v)) {
            // 连续数字键 = 数组（JSON array）
            if (self::isList($v)) {
                return '[' . implode(',', array_map([self::class, 'run'], array_values($v))) . ']';
            }
            $copy = $v;
            ksort($copy, SORT_STRING);
            $parts = [];
            foreach ($copy as $k => $val) {
                $parts[] = self::json((string)$k) . ':' . self::run($val);
            }
            return '{' . implode(',', $parts) . '}';
        }
        if ($v instanceof \stdClass) {
            $arr = get_object_vars($v);
            ksort($arr, SORT_STRING);
            $parts = [];
            foreach ($arr as $k => $val) {
                $parts[] = self::json((string)$k) . ':' . self::run($val);
            }
            return '{' . implode(',', $parts) . '}';
        }
        return self::json($v);
    }

    /**
     * FNV-1a 32bit 指纹（与 TS fingerprint() 一致）
     */
    public static function fingerprint($v): string
    {
        $s = self::run($v);
        $h = 0x811c9dc5;

        // 按 UTF-16 码元遍历，对齐 JS 的 charCodeAt（中文场景必须）
        foreach (self::utf16Codes($s) as $code) {
            $h = ($h ^ $code) & 0xFFFFFFFF;
            $h = self::imul32($h, 0x01000193);
        }

        return str_pad(dechex($h), 8, '0', STR_PAD_LEFT);
    }

    /**
     * 32 位整数乘法（模拟 JS Math.imul + >>> 0）
     */
    private static function imul32(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $aLo = $a & 0xFFFF;
        $aHi = $a >> 16;
        $bLo = $b & 0xFFFF;
        $bHi = $b >> 16;

        $lo  = $aLo * $bLo;
        $mid = $aHi * $bLo + $aLo * $bHi;

        return ($lo + (($mid & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }

    /**
     * 字符串 → UTF-16 码元数组
     */
    private static function utf16Codes(string $s): array
    {
        if (function_exists('mb_convert_encoding')) {
            $u16 = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
            $codes = unpack('v*', $u16);
            return $codes === false ? [] : $codes;
        }
        // fallback：仅 ASCII 安全
        $out = [];
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $out[] = ord($s[$i]);
        }
        return $out;
    }

    private static function json($v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function floatToString(float $v): string
    {
        if (!is_finite($v)) {
            return 'null';
        }
        // 整数值 → 整数形式（对齐 JS：JSON.stringify(2.0) === "2"）
        if ($v == (int)$v && abs($v) < 1e15) {
            return (string)(int)$v;
        }
        // 去掉多余精度，使用与 JS 相同的最短表示
        $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $s === false ? 'null' : $s;
    }

    /**
     * PHP 8.1 的 array_is_list 兼容实现
     */
    private static function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }
}
