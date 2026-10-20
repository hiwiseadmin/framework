<?php

declare(strict_types=1);

namespace plugin\match3game\service\level;

/**
 * 难度曲线 D（05 §B7.1 / 02 §7.3）
 *
 * 难度系数 D 驱动一切：步数、目标数量、障碍密度、颜色数、Boss 强度。
 * 所有公式在这里集中定义，三端（生成器 / 模拟器 / 服务端校验）共用同一份。
 *
 *   D(layer, nodeIdx) = 1.0 + 0.25×(layer-1) + 0.04×nodeIdx + 0.03×relicPower
 *   moves             = round(25 / D^0.6)
 *   gemCount          = clamp(7 - floor(D), 4, 6)     // D 越高颜色越少，越难配消除
 *   overlayDensity    = clamp(base × D, 0, 0.6)
 */
final class DifficultyCurve
{
    /** 基准步数（D=1 时） */
    public const BASE_MOVES = 25;

    /** 步数衰减指数 */
    public const MOVES_EXP = 0.6;

    /** 步数下限（再难也不能低于此值，否则不可玩） */
    public const MIN_MOVES = 5;

    /**
     * 节点难度系数
     *
     * @param int   $layer       层号（1 起）
     * @param int   $nodeIdx     层内节点序号（0 起）
     * @param float $relicPower  已获遗物的强度（Phase 3 传入，Phase 2 传 0）
     */
    public static function D(int $layer, int $nodeIdx, float $relicPower = 0.0): float
    {
        return 1.0
            + 0.25 * max(0, $layer - 1)
            + 0.04 * max(0, $nodeIdx)
            + 0.03 * max(0.0, $relicPower);
    }

    /**
     * D → 步数（05 §B7.1：moves = round(25 / D^0.6)）
     */
    public static function moves(float $D): int
    {
        if ($D <= 0) {
            return self::BASE_MOVES;
        }

        return max(self::MIN_MOVES, (int) round(self::BASE_MOVES / ($D ** self::MOVES_EXP)));
    }

    /**
     * D → 颜色数：clamp(7 - floor(D), 4, 6)
     */
    public static function gemCount(float $D): int
    {
        return (int) max(4, min(6, 7 - (int) floor($D)));
    }

    /**
     * D → 覆盖层密度（比例），并夹到 [min, max]
     *
     * @param float $base 基准密度（D=1 时）
     */
    public static function overlayDensity(float $D, float $base = 0.15, float $min = 0.0, float $max = 0.6): float
    {
        return max($min, min($max, $base * max(0.0, $D)));
    }

    /**
     * D → 障碍数量（线性增长）
     */
    public static function blockerCount(float $D, float $base = 1.0, float $perD = 1.0): int
    {
        return (int) max(0, round($base + $perD * (max(0.0, $D) - 1)));
    }

    /**
     * D → 目标数量缩放
     */
    public static function goalScale(float $D): float
    {
        return max(0.2, $D);
    }

    /**
     * 三星阈值：以 base 为二星基准，取 [0.6×, 1.0×, 1.8×]
     *
     * @return array<int,int>
     */
    public static function stars(int $base, int $floor = 1000): array
    {
        return [
            max($floor, (int) round($base * 0.6)),
            max($floor, (int) round($base * 1.0)),
            max($floor, (int) round($base * 1.8)),
        ];
    }

    public static function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }
}
