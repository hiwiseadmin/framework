<?php

declare(strict_types=1);

namespace plugin\match3game\service;

/**
 * 对局 seed / battle_token 签发（**只签发，不生成棋盘**）
 *
 * 【职责边界】
 *   本类只负责「签发随机种子」与「签发结算幂等键」，**不含任何棋盘生成逻辑**。
 *
 * 【为什么不在这里生成棋盘】
 *   插件内曾并存两套互不相容的棋盘算法：
 *     · `BoardSeedService`（xorshift32 顺序状态 + 整数取模）—— 已废弃并删除
 *     · `level\Match3Board` + `level\Match3Random`（mulberry32 + deriveSeed）—— **唯一权威**
 *   两者对同一 seed 产出完全不同的棋盘，是跨端分歧的根源。现已统一到后者。
 *
 * 【棋盘从哪来】
 *   服务端只下发 `seed`，棋盘由「同一套确定性算法」在需要时现算：
 *     · 服务端重放：`Match3Board::buildState($schema, $seed)`（L3 三层校验）
 *     · 客户端实现：必须逐字对齐 `Match3Random` / `Match3Board`
 *   契约详见 docs/match3/11-board-algo-decision.md 与 docs/match3-seed-algorithm.md。
 *
 * 【数据一致性】
 *   数据库只存 `seed` / `play_token` / `battle_token`，从不落棋盘快照，
 *   因此算法变更无需数据迁移。
 */
class SeedIssuer
{
    /** seed 取值范围上限（config/match3.php battle.seed_max） */
    protected int $seedMax;

    public function __construct(array $config = [])
    {
        $this->seedMax = (int) ($config['battle']['seed_max'] ?? 2147483647);
    }

    /**
     * 生成 seed
     *
     * @return int [1, seed_max] 区间内的正整数
     */
    public function generateSeed(): int
    {
        return random_int(1, max(1, $this->seedMax));
    }

    /**
     * 签发 battle_token（结算幂等键）
     */
    public function generateBattleToken(int $userId, string $levelCode, int $seed): string
    {
        $raw = implode('.', [$userId, $levelCode, $seed, time(), bin2hex(random_bytes(8))]);

        return md5($raw);
    }

    /**
     * 校验 seed 是否合法
     */
    public function isValidSeed(int $seed): bool
    {
        return $seed >= 1 && $seed <= max(1, $this->seedMax);
    }
}
