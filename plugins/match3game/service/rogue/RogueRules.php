<?php

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

/**
 * 肉鸽规则常量与纯逻辑（无框架依赖，可离线单测）
 *
 * 从 Match3RunService 中抽出，原因：
 *   ① `calcMetaReward` / `isFinalNode` 是纯函数，不应因为服务依赖 Model 而无法单测
 *   ② 数值表（灵石产出 / 解锁成本 / 奖励区间）是策划最常调整的部分，集中一处便于查阅
 *
 * 依据：05 §1.6 / §B7.3 / §B7.4
 */
final class RogueRules
{
    /* ---------------- 局 ---------------- */
    public const BASE_LIVES     = 3;
    public const DEFAULT_LAYERS = 3;

    /** run 状态 */
    public const STATUS_PLAYING = 0;
    public const STATUS_CLEARED = 1;
    public const STATUS_FAILED  = 2;
    public const STATUS_ABANDON = 3;

    /* ---------------- 节点灵玉奖励区间（05 §B7.3） ---------------- */
    public const REWARD_BATTLE = [20, 35];
    public const REWARD_ELITE  = [45, 70];
    public const REWARD_BOSS   = [90, 140];

    /* ---------------- 灵石（meta 货币）产出（05 §B7.4） ---------------- */
    public const STONE_PER_LAYER   = 100;
    public const STONE_SCORE_DIV   = 10000;
    public const STONE_CLEAR_BONUS = 500;

    /* ---------------- 局外解锁（05 §B7.4） ---------------- */
    public const UNLOCK_COSTS = [
        'start_move_1' => 500,
        'start_move_2' => 1000,
        'start_move_3' => 2000,
        'character'    => 1500,
        'relic_slot'   => 2500,
    ];

    /** 单个遗物解锁键前缀：`relic:r_xxx`，成本取 match3_relic.unlock_cost */
    public const UNLOCK_RELIC_PREFIX = 'relic:';

    /** 遗物解锁兜底成本（配置缺省时） */
    public const UNLOCK_RELIC_COST = 300;

    /** 起点选项（05 §1.3） */
    public const START_OPTIONS = [
        'default' => ['lives' => 3, 'relic' => null,             'cost' => 0],
        'monk'    => ['lives' => 2, 'relic' => 'r_stripe_start', 'cost' => 1500],
    ];

    /**
     * 灵石产出
     *
     * stone = 100 × 层数 × (1 + score/10000)，通关额外 +500
     */
    public static function metaReward(int $layers, int $score, bool $cleared): int
    {
        $stone = (int) round(self::STONE_PER_LAYER * max(1, $layers) * (1 + max(0, $score) / self::STONE_SCORE_DIV));
        if ($cleared) {
            $stone += self::STONE_CLEAR_BONUS;
        }

        return $stone;
    }

    /**
     * 节点灵玉奖励区间
     *
     * @return array{0:int,1:int}
     */
    public static function rewardRange(string $nodeType): array
    {
        return match ($nodeType) {
            'elite' => self::REWARD_ELITE,
            'boss'  => self::REWARD_BOSS,
            default => self::REWARD_BATTLE,
        };
    }

    /**
     * 是否为最后一个节点（最后一层 Boss，即终局节点）
     *
     * @param array<string, mixed> $mapData
     */
    public static function isFinalNode(array $mapData, string $nodeId): bool
    {
        $node = Match3MapGenerator::findNode($mapData, $nodeId);

        return $node !== null && ($node['next'] ?? []) === [];
    }
}
