<?php

declare(strict_types=1);

namespace plugin\match3game\service\level;

/**
 * 目标判定（Level Schema v1 `rules.goals` 的统一解释器）
 *
 * 固定关卡（validator\Match3Validator）与肉鸽节点（controller\api\Rogue）
 * 共用同一份判定逻辑，避免两处实现漂移。
 *
 * 【目标进度结构】{key => count}
 *   · {"type":"collect","target":"red_gem","count":25} → progress['red_gem']
 *   · {"type":"clear","layer":"ice","count":30}        → progress['ice']
 *   · {"type":"score","count":9000}                    → 直接比较 score
 *
 * 【本期不判定】boss / spread / survive / order / rescue
 *   这些类型由前端与后续阶段（Boss 伤害结算、蔓延清除）负责，此处视为已满足。
 */
final class GoalJudge
{
    /** 本期可实现判定的目标类型 */
    public const JUDGED_TYPES = ['score', 'collect', 'clear'];

    /**
     * score 目标的分值底限（无则 0）
     *
     * @param array<int,array<string,mixed>> $goals
     */
    public static function scoreTarget(array $goals): int
    {
        foreach ($goals as $g) {
            if (is_array($g) && (($g['type'] ?? '') === 'score')) {
                return (int) ($g['count'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * 目标是否全部达成
     *
     * @param array<int,array<string,mixed>> $goals    rules.goals
     * @param array<string,int>              $progress {key => count}
     */
    public static function met(array $goals, array $progress, int $score): bool
    {
        return self::unmetReason($goals, $progress, $score) === null;
    }

    /**
     * 未达成原因（null = 全部达成）
     *
     * @param array<int,array<string,mixed>> $goals
     * @param array<string,int>              $progress
     */
    public static function unmetReason(array $goals, array $progress, int $score): ?string
    {
        foreach ($goals as $g) {
            if (!is_array($g)) {
                continue;
            }

            $type  = (string) ($g['type'] ?? '');
            $count = (int) ($g['count'] ?? 0);

            if ($type === 'score') {
                if ($count > 0 && $score < $count) {
                    return sprintf('分数未达标: %d/%d', $score, $count);
                }
                continue;
            }

            if ($type === 'collect' || $type === 'clear') {
                $key = $type === 'collect'
                    ? (string) ($g['target'] ?? '')
                    : (string) ($g['layer'] ?? '');

                if ($key === '' || $count <= 0) {
                    continue;
                }

                $got = (int) ($progress[$key] ?? 0);
                if ($got < $count) {
                    return sprintf('%s 未达标: %s=%d（需要 %d）', $type, $key, $got, $count);
                }
            }

            // 其他类型本期不判定，视为已满足
        }

        return null;
    }

    /**
     * 期望的进度键（用于校验客户端 claim.goals 结构是否覆盖目标）
     *
     * @param  array<int,array<string,mixed>> $goals
     * @return array<int,string>
     */
    public static function progressKeys(array $goals): array
    {
        $keys = [];
        foreach ($goals as $g) {
            if (!is_array($g)) {
                continue;
            }
            $type = (string) ($g['type'] ?? '');
            if ($type === 'collect' && !empty($g['target'])) {
                $keys[] = (string) $g['target'];
            } elseif ($type === 'clear' && !empty($g['layer'])) {
                $keys[] = (string) $g['layer'];
            }
        }

        return array_values(array_unique($keys));
    }
}
