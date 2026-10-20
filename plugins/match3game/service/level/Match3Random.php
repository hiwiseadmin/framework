<?php
/**
 * 确定性伪随机（PHP 版，与 02 文档 §8.5 的 mulberry32 完全一致）
 *
 * 为什么不用 mt_rand / random_int：
 *   关卡生成必须【可复现】——同一 seed 必须永远产出同一关卡，
 *   否则断点续玩、重放校验、AI 平衡模拟全部失效。
 *
 * 为什么用"派生"而不是"顺序调用"：
 *   若依赖调用顺序，某端少调一次就会全盘错位。
 *   改为 deriveSeed(base, scope, ...salt) 后，任意一处的随机都是独立确定的，
 *   天然抗时序漂移。
 *
 * 迁移自 docs/match3/code/Match3Random.php（仅改命名空间）。
 */

declare(strict_types=1);

namespace plugin\match3game\service\level;

class Match3Random
{
    /**
     * 派生子种子
     *
     * 对应 TS: deriveSeed(base, ...salt)
     */
    public static function deriveSeed(int $base, int ...$salt): int
    {
        $h = $base & 0xFFFFFFFF;
        foreach ($salt as $s) {
            $h = (self::imul32($h ^ (($s + 0x9E3779B9) & 0xFFFFFFFF), 0x85EBCA6B)) & 0xFFFFFFFF;
        }
        return $h & 0xFFFFFFFF;
    }

    /**
     * 由 base 与 salt 直接取一个 [0,1) 随机数（无需手工管理 PRNG 实例）
     */
    public static function next(int $base, int ...$salt): float
    {
        return self::mulberry32(self::deriveSeed($base, ...$salt))();
    }

    /**
     * 取 [min,max] 闭区间整数
     */
    public static function intBetween(int $base, int $min, int $max, int ...$salt): int
    {
        if ($max <= $min) {
            return $min;
        }
        $r = self::next($base, ...$salt);
        return $min + (int)floor($r * ($max - $min + 1));
    }

    /**
     * 按权重挑选：weights = [['v'=>'a','w'=>60], ...] 或 ['a'=>60, 'b'=>30]
     */
    public static function weightedPick(int $base, array $weights, int ...$salt)
    {
        $items = [];
        $total = 0;
        foreach ($weights as $k => $w) {
            $items[] = ['k' => $k, 'w' => (int)$w];
            $total  += (int)$w;
        }
        if ($total <= 0) {
            return array_key_first($weights);
        }

        $r = self::next($base, ...$salt) * $total;
        $acc = 0;
        foreach ($items as $it) {
            $acc += $it['w'];
            if ($r < $acc) {
                return $it['k'];
            }
        }
        return $items[count($items) - 1]['k'];
    }

    /**
     * 洗牌（Fisher-Yates，确定性）
     */
    public static function shuffle(int $base, array $arr, int ...$salt): array
    {
        $a = array_values($arr);
        $n = count($a);
        for ($i = $n - 1; $i > 0; $i--) {
            // PHP 不允许 unpack 之后跟位置参数，需先拼数组
            $j = self::intBetween($base, 0, $i, ...[...$salt, $i]);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }
        return $a;
    }

    /**
     * mulberry32
     *
     * @return \Closure(): float
     */
    public static function mulberry32(int $seed): \Closure
    {
        $s = $seed & 0xFFFFFFFF;

        return function () use (&$s): float {
            $s = ($s + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = self::imul32($s ^ ($s >> 15), 1 | $s);
            $t = ($t + self::imul32($t ^ ($t >> 7), 61 | $t)) ^ $t;
            $t = $t & 0xFFFFFFFF;
            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
        };
    }

    /**
     * 32 位整数乘法（对齐 JS Math.imul + >>> 0）
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
}
