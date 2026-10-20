<?php

declare(strict_types=1);

namespace plugin\match3game\service\level;

/**
 * 关卡 AI 模拟器（贪心试玩，02 §7.5）
 *
 * 用途（02 §7.5）：
 *   ① 关卡发布前体检（通关率是否落在合理区间）
 *   ② 随机关卡生成时筛选 seed（LevelGenerationService 的重试依据）
 *   ③ 数值策划调参（目标难度 ↔ 实际难度对照）
 *
 * 【算法】贪心：每回合枚举所有可行相邻交换，选"消除数最多"的一个；
 *   无可消除时自动洗牌（不消耗步数）。
 *
 * 【确定性】同 (schema, seed, k) 结果一致 —— 所有随机都从 (seed, k, turn, combo, r, c) 派生。
 *
 * 【已知简化（不影响通关率量级）】
 *   · 特殊元素（条纹/炸弹/魔力鸟）按普通消除计，不模拟连锁引爆
 *   · 掉落物 / 传送带 / 行为系统不参与（Phase 3 的行为由 Match3NodeService 施加）
 *   · 目标类型只判定 score / collect / clear，其余类型视为已满足
 */
class LevelSimulator
{
    /** 单格基础分（与客户端引擎保持同一量级即可，仅用于通关率估算） */
    public const SCORE_PER_CELL = 10;

    /** 连锁倍率（02 §2.2 T9） */
    public const COMBO_MUL = [1.0, 1.2, 1.5, 2.0, 2.5, 3.0];

    /** 单次试玩的最大回合数（防御死循环） */
    public const MAX_TURNS = 300;

    /** 单回合最大连锁次数 */
    public const MAX_CASCADE = 50;

    /** 覆盖层字符 → 目标层名 */
    public const OVERLAY_LAYER = [
        'I' => 'ice', 'J' => 'ice', 'L' => 'jelly',
        'K' => 'snow', 'Q' => 'chocolate', 'W' => 'poison',
    ];

    /**
     * 跑 N 次贪心试玩
     *
     * @param array $schema 完整 Level Schema v1
     * @param int   $seed   棋盘种子
     * @param int   $tries  试玩次数
     * @return array{
     *   passRate: float, wins: int, tries: int, avgMoves: float, avgScore: int,
     *   avgCollected: array<string,float>, avgCleared: array<string,float>
     * }
     */
    public function simulate(array $schema, int $seed, int $tries = 20): array
    {
        $tries = max(1, $tries);
        $wins = 0;
        $movesSum = 0;
        $scoreSum = 0;
        $collectedSum = [];
        $clearedSum   = [];

        for ($k = 0; $k < $tries; $k++) {
            $r = $this->playOnce($schema, $seed, $k);
            if ($r['win']) {
                $wins++;
            }
            $movesSum += $r['moves'];
            $scoreSum += $r['score'];

            foreach ($r['collected'] as $key => $v) {
                $collectedSum[$key] = ($collectedSum[$key] ?? 0) + $v;
            }
            foreach ($r['cleared'] as $key => $v) {
                $clearedSum[$key] = ($clearedSum[$key] ?? 0) + $v;
            }
        }

        return [
            'passRate'     => $wins / $tries,
            'wins'         => $wins,
            'tries'        => $tries,
            'avgMoves'     => round($movesSum / $tries, 1),
            'avgScore'     => (int) round($scoreSum / $tries),
            'avgCollected' => array_map(fn ($v) => round($v / $tries, 2), $collectedSum),
            'avgCleared'   => array_map(fn ($v) => round($v / $tries, 2), $clearedSum),
        ];
    }

    /**
     * 单次贪心试玩
     *
     * @return array{
     *   win: bool, moves: int, score: int, goals: array<string,int>,
     *   collected: array<string,int>, cleared: array<string,int>, turns: int
     * }
     */
    public function playOnce(array $schema, int $seed, int $k = 0): array
    {
        $state   = $this->buildState($schema, Match3Random::deriveSeed($seed, $k, crc32('sim')));
        $rules   = (array) ($schema['rules'] ?? []);
        $goals   = (array) ($rules['goals'] ?? []);
        $minMatch = max(2, (int) ($rules['minMatch'] ?? 3));
        $movesLeft = (int) ($rules['moves'] ?? 30);

        $score  = 0;
        $moves  = 0;
        $turns  = 0;
        $collected = [];   // {元素码 => 消除数}
        $cleared   = [];   // {层名 => 清除数}

        while ($movesLeft > 0 && $turns < self::MAX_TURNS) {
            $turns++;

            $move = $this->bestMove($state, $minMatch);
            if ($move === null) {
                // 无可行步 → 自动洗牌（不消耗步数）
                $this->shuffleBoard($state, Match3Random::deriveSeed($seed, $k, $turns, crc32('shuffle')));
                if ($this->bestMove($state, $minMatch) === null) {
                    break; // 洗牌后仍无解 → 判负
                }
                continue;
            }

            [$r1, $c1, $r2, $c2] = $move;
            $this->swap($state, $r1, $c1, $r2, $c2);
            $moves++;
            $movesLeft--;

            $score += $this->resolveCascade($state, $minMatch, $seed, $k, $turns, $collected, $cleared);

            if ($this->goalsMet($goals, $score, $collected, $cleared)) {
                return [
                    'win'       => true,
                    'moves'     => $moves,
                    'score'     => $score,
                    'goals'     => $this->goalProgress($goals, $score, $collected, $cleared),
                    'collected' => $collected,
                    'cleared'   => $cleared,
                    'turns'     => $turns,
                ];
            }
        }

        return [
            'win'       => false,
            'moves'     => $moves,
            'score'     => $score,
            'goals'     => $this->goalProgress($goals, $score, $collected, $cleared),
            'collected' => $collected,
            'cleared'   => $cleared,
            'turns'     => $turns,
        ];
    }

    /**
     * 棋盘上是否至少存在一步有效交换（02 §7.5 autoShuffle 触发条件）
     */
    public function hasValidMove(array $schema, int $seed, int $k = 0): bool
    {
        $state = $this->buildState($schema, Match3Random::deriveSeed($seed, $k, crc32('sim')));
        $minMatch = max(2, (int) ($schema['rules']['minMatch'] ?? 3));

        return $this->bestMove($state, $minMatch) !== null;
    }

    /* ======================================================================
     *  棋盘构建
     * ====================================================================== */

    /**
     * 由 schema 构建可玩棋盘状态
     *
     * @return array{
     *   rows:int, cols:int, playable:array, blocked:array, overlay:array,
     *   color:array, colors:array, index:array
     * }
     */
    public function buildState(array $schema, int $seed): array
    {
        return Match3Board::buildState($schema, $seed);
    }

    protected function pickColor(int $seed, int $r, int $c, int $colorCount): int
    {
        return Match3Board::pickColorSeed($seed, $r, $c, $colorCount);
    }

    /* ======================================================================
     *  回合结算（简化管线，02 §2.1）
     * ====================================================================== */

    /**
     * 消除 + 掉落 + 补货 + 连锁
     *
     * @return int 本次操作累计得分
     */
    protected function resolveCascade(
        array &$state,
        int $minMatch,
        int $seed,
        int $k,
        int $turn,
        array &$collected,
        array &$cleared
    ): int {
        // 补货种子派生盐保持与原实现完全一致（含试玩序号 k）
        return Match3Board::resolveCascade(
            $state,
            $minMatch,
            fn (int $combo): int => Match3Random::deriveSeed($seed, $k, $turn, $combo, crc32('spawn')),
            $this->scoreRule(),
            $collected,
            $cleared
        );
    }

    /**
     * 模拟器专用分数口径（保持 SCORE_PER_CELL = 10 不变，避免影响通关率估算历史值）
     *
     * @return array{perCell:int, comboMul:float[]}
     */
    protected function scoreRule(): array
    {
        return ['perCell' => self::SCORE_PER_CELL, 'comboMul' => self::COMBO_MUL];
    }

    /**
     * 找出所有 ≥minMatch 的连线格
     *
     * @return array<int, array{0:int,1:int}>
     */
    protected function findMatches(array $state, int $minMatch): array
    {
        return Match3Board::findMatches($state, $minMatch);
    }

    /**
     * 移除命中格：计分 + 覆盖层破除
     *
     * @param array<int, array{0:int,1:int}> $cells
     */
    protected function removeCells(
        array &$state,
        array $cells,
        int $combo,
        array &$collected,
        array &$cleared
    ): int {
        return Match3Board::removeCells($state, $cells, $combo, $collected, $cleared, $this->scoreRule());
    }

    /**
     * 重力：每列可玩格内的元素向下压实
     */
    protected function gravity(array &$state): void
    {
        Match3Board::gravity($state);
    }

    /**
     * 补货：把空的可玩格按派生种子填满
     */
    protected function refill(array &$state, int $seed): void
    {
        Match3Board::refill($state, $seed);
    }

    /**
     * 确定性洗牌
     */
    protected function shuffleBoard(array &$state, int $seed): void
    {
        Match3Board::shuffleBoard($state, $seed);
    }

    /* ======================================================================
     *  贪心选步
     * ====================================================================== */

    /**
     * 枚举所有可行交换，返回消除数最多的一个
     *
     * @return array{0:int,1:int,2:int,3:int}|null [r1,c1,r2,c2]
     */
    protected function bestMove(array $state, int $minMatch): ?array
    {
        $best     = null;
        $bestHits = 0;

        $rows = $state['rows'];
        $cols = $state['cols'];

        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                if (!$this->swappable($state, $r, $c)) {
                    continue;
                }

                foreach ([[0, 1], [1, 0]] as [$dr, $dc]) {
                    $r2 = $r + $dr;
                    $c2 = $c + $dc;
                    if ($r2 >= $rows || $c2 >= $cols || !$this->swappable($state, $r2, $c2)) {
                        continue;
                    }
                    if ($state['color'][$r][$c] === $state['color'][$r2][$c2]) {
                        continue;
                    }

                    // 交换只可能通过这两格成线，局部扫描即可（比全盘 findMatches 快一个量级）
                    $this->swap($state, $r, $c, $r2, $c2);
                    $hits = $this->lineCountAt($state, $r, $c, $minMatch)
                          + $this->lineCountAt($state, $r2, $c2, $minMatch);
                    $this->swap($state, $r, $c, $r2, $c2);

                    if ($hits > $bestHits) {
                        $bestHits = $hits;
                        $best     = [$r, $c, $r2, $c2];
                    }
                }
            }
        }

        return $best;
    }

    /**
     * 以 (r,c) 为中心，返回横/纵连线中达到 minMatch 的格子总数（0 = 不成线）
     */
    protected function lineCountAt(array $state, int $r, int $c, int $minMatch): int
    {
        return Match3Board::lineCountAt($state, $r, $c, $minMatch);
    }

    protected function swappable(array $state, int $r, int $c): bool
    {
        return Match3Board::swappable($state, $r, $c);
    }

    protected function swap(array &$state, int $r1, int $c1, int $r2, int $c2): void
    {
        Match3Board::swap($state, $r1, $c1, $r2, $c2);
    }

    /* ======================================================================
     *  目标判定
     * ====================================================================== */

    protected function goalsMet(array $goals, int $score, array $collected, array $cleared): bool
    {
        foreach ($goals as $g) {
            if (!is_array($g)) {
                continue;
            }
            $type  = (string) ($g['type'] ?? '');
            $count = (int) ($g['count'] ?? 0);

            if ($type === 'score') {
                if ($score < $count) {
                    return false;
                }
            } elseif ($type === 'collect') {
                if (($collected[(string) ($g['target'] ?? '')] ?? 0) < $count) {
                    return false;
                }
            } elseif ($type === 'clear') {
                if (($cleared[(string) ($g['layer'] ?? '')] ?? 0) < $count) {
                    return false;
                }
            }
            // 其余类型（boss/spread/survive/order/rescue）本期不模拟，视为已满足
        }

        return true;
    }

    /**
     * 目标进度快照（写回 claim.goals 的等价物）
     *
     * @return array<string,int>
     */
    protected function goalProgress(array $goals, int $score, array $collected, array $cleared): array
    {
        $out = [];
        foreach ($goals as $g) {
            if (!is_array($g)) {
                continue;
            }
            $type = (string) ($g['type'] ?? '');
            if ($type === 'collect') {
                $out[(string) ($g['target'] ?? '')] = (int) ($collected[(string) ($g['target'] ?? '')] ?? 0);
            } elseif ($type === 'clear') {
                $out[(string) ($g['layer'] ?? '')] = (int) ($cleared[(string) ($g['layer'] ?? '')] ?? 0);
            }
        }
        $out['score'] = $score;

        return $out;
    }
}
