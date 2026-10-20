<?php

declare(strict_types=1);

namespace plugin\match3game\service\level;

use wise\support\context\ContextualLogger;

/**
 * PHP 重放引擎（L3 的纯 PHP 实现，无外部依赖）
 *
 * 【定位】`replay_engine.mode = 'php'`
 *   · 不需要部署 Node 服务，无网络调用，单机自查
 *   · 与 `LevelSimulator` **共用同一份棋盘内核** `Match3Board`，
 *     仓库内不存在第二套三消实现
 *
 * 【置信度：upper_bound，不是 strict】
 *   本引擎与客户端 TS 引擎的**分数口径尚未逐项对齐**（见 07 §6 的决策记录）。
 *   因此它只提供"上界"语义：
 *     `claim.score` 不得大于 `replay.score × (1 + 容差)`
 *   而**不做**双向精确比对。配合 `SubmissionValidator` 的 `upper_bound` 模式使用。
 *   对齐完成后（`test-vectors` 的 replay 向量全绿）再切 `strict`。
 *
 * 【核心原则：忠实或不重放（宁可降级，不可给错分）】
 *   只要遇到任何本引擎无法忠实模拟的要素，**整体拒绝重放**（返回 null），
 *   让上层回落到 L1+L2 弱校验 —— 而不是"猜一个近似分"。
 *   因为一个偏低的重放分会让正常玩家被上界判死（误杀），比不校验更糟。
 *
 *   当前会触发降级的要素：
 *     · 非 down 重力（`regions[].gravity` ≠ down）
 *     · 传送带 / 单向门（terrain 含 `> < ^ v P D`）
 *     · 行为系统（`behaviours` 非空 —— 巧克力蔓延、毒液等会改变棋盘）
 *     · 演员/ Boss 技能（`actors` 非空 —— lockCells 等）
 *     · 不支持的道具（rainbow / paint 等语义未定）
 *
 * 【已对齐的口径】
 *   · `Match3Random`（mulberry32 + deriveSeed）—— 与 TS 同源，见类注释
 *   · `Match3Board::colorFingerprint` 用 `StableStringify`（与 TS 同源）
 *   · 单格分/连锁倍率走 `rules.scorePerCell` / `rules.comboMul`（协议字段优于魔法数字）
 */
class Match3PhpReplayEngine implements Match3ReplayEngineInterface
{
    /** 棋盘填充算法版本（写进返回值，便于诊断两端填充是否同一版） */
    public const FILL_VERSION = Match3FillVersion::CURRENT;

    /** 单次重放的 ops 硬上限（防御超长 payload） */
    public const MAX_OPS = 2000;

    /** 支持的道具及其清格半径（0 = 单格） */
    public const SUPPORTED_PROPS = [
        'hammer'    => 0,
        'bomb'      => 2,
        'shuffle'   => -1,  // -1 = 洗牌，不清格
        'add_moves' => -2,  // -2 = 加步，不改棋盘
    ];

    /** 需要 at 坐标的道具 */
    public const NEED_POS_PROPS = ['hammer', 'bomb'];

    /** 会破坏重力假设的地形字符 */
    public const EXOTIC_TERRAIN = '><^vPD';

    protected bool $wantFingerprints;

    /**
     * @param array<string,mixed> $config replay_engine 配置段
     */
    public function __construct(array $config = [])
    {
        $this->wantFingerprints = (bool) ($config['fingerprints'] ?? false);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * 重放一局
     *
     * @param  \stdClass|array<string,mixed> $schema
     * @param  array<int, array<string,mixed>> $ops
     * @return array<string,mixed>|null null = 无法忠实重放（上层回落弱校验）
     */
    public function replay($schema, int $seed, array $ops, string $fillVersion = ''): ?array
    {
        // 本引擎只有"当前版本"一份实现（回滚/对照用），验不了别的版本 → 降级，不误判
        if ($fillVersion !== '' && $fillVersion !== self::FILL_VERSION) {
            ContextualLogger::warning('[Match3Replay] PHP 引擎不支持该客户端版本，降级为弱校验', [
                'client'    => $fillVersion,
                'supported' => self::FILL_VERSION,
            ]);

            return null;
        }

        $arr = is_object($schema)
            ? json_decode(json_encode($schema, JSON_UNESCAPED_UNICODE), true)
            : $schema;

        if (!is_array($arr) || $arr === []) {
            return null;
        }
        if (count($ops) > self::MAX_OPS) {
            $this->degrade('too_many_ops', ['ops' => count($ops)]);

            return null;
        }

        $reason = self::unsupportedReason($arr);
        if ($reason !== null) {
            $this->degrade($reason, ['code' => $arr['code'] ?? '']);

            return null;
        }

        $rules    = (array) ($arr['rules'] ?? []);
        $minMatch = max(2, (int) ($rules['minMatch'] ?? 3));
        $scoreRule = Match3Board::scoreRule($arr);

        $state     = Match3Board::buildState($arr, $seed);
        $collected = [];
        $cleared   = [];
        $turnFps   = [];

        $score    = 0;
        $swaps    = 0;
        $maxCombo = 0;
        $turn     = 0;

        foreach ($ops as $i => $op) {
            if (!is_array($op)) {
                return $this->invalid('invalid_op', ['index' => $i]);
            }

            $type = (string) ($op['t'] ?? '');

            if ($type === 'swap') {
                $coords = self::parseSwap($op);
                if ($coords === null) {
                    return $this->invalid('invalid_swap_op', ['index' => $i]);
                }
                [$r1, $c1, $r2, $c2] = $coords;

                if (!$this->inBoard($state, $r1, $c1) || !$this->inBoard($state, $r2, $c2)) {
                    return $this->invalid('swap_out_of_board', ['index' => $i]);
                }
                // 相邻性（L1 已校验，这里再挡一层，避免异常坐标把棋盘搅坏）
                if (abs($r1 - $r2) + abs($c1 - $c2) !== 1) {
                    return $this->invalid('swap_not_adjacent', ['index' => $i]);
                }

                Match3Board::swap($state, $r1, $c1, $r2, $c2);
                $swaps++;
                $turn++;

                $combo   = 0;
                $score  += Match3Board::resolveCascade(
                    $state,
                    $minMatch,
                    fn (int $k): int => Match3Random::deriveSeed($seed, $turn, $k, crc32('spawn')),
                    $scoreRule,
                    $collected,
                    $cleared,
                    $combo
                );
                $maxCombo = max($maxCombo, $combo);

                if ($this->wantFingerprints) {
                    $turnFps[$turn] = Match3Board::colorFingerprint($state);
                }

                continue;
            }

            if ($type === 'prop') {
                $propId = (string) ($op['p'] ?? '');

                if (!isset(self::SUPPORTED_PROPS[$propId])) {
                    $this->degrade('unsupported_prop', ['prop' => $propId]);

                    return null;
                }

                $radius = self::SUPPORTED_PROPS[$propId];

                if ($radius === -2) {
                    continue; // add_moves：不影响棋盘与分数
                }

                if ($radius === -1) {
                    // shuffle：确定性洗牌（用当前回合号派生，保证同 ops 同结果）
                    $turn++;
                    Match3Board::shuffleBoard($state, Match3Random::deriveSeed($seed, $turn, crc32('shuffle')));

                    // 洗牌后若直接成线，按连锁结算（与客户端 autoShuffle 语义对齐）
                    $combo   = 0;
                    $score  += Match3Board::resolveCascade(
                        $state,
                        $minMatch,
                        fn (int $k): int => Match3Random::deriveSeed($seed, $turn, $k, crc32('spawn')),
                        $scoreRule,
                        $collected,
                        $cleared,
                        $combo
                    );
                    $maxCombo = max($maxCombo, $combo);

                    continue;
                }

                $at = $op['at'] ?? null;
                if (!is_array($at) || count($at) < 2) {
                    return $this->invalid('prop_missing_at', ['prop' => $propId]);
                }
                // 协议坐标是 [c, r]
                $pc = (int) $at[0];
                $pr = (int) $at[1];
                if (!$this->inBoard($state, $pr, $pc)) {
                    return $this->invalid('prop_at_out_of_board', ['prop' => $propId]);
                }

                $cells = [];
                for ($r = $pr - $radius; $r <= $pr + $radius; $r++) {
                    for ($c = $pc - $radius; $c <= $pc + $radius; $c++) {
                        if ($this->inBoard($state, $r, $c) && $state['color'][$r][$c] !== null) {
                            $cells[] = [$r, $c];
                        }
                    }
                }

                // 道具清格算 1 连击（0 号档位倍率）
                $score += Match3Board::removeCells($state, $cells, 0, $collected, $cleared, $scoreRule);
                Match3Board::gravity($state);
                $turn++;

                $combo   = 0;
                $score  += Match3Board::resolveCascade(
                    $state,
                    $minMatch,
                    fn (int $k): int => Match3Random::deriveSeed($seed, $turn, $k, crc32('spawn')),
                    $scoreRule,
                    $collected,
                    $cleared,
                    $combo
                );
                $maxCombo = max($maxCombo, $combo);

                continue;
            }

            // 未知操作类型：不猜（且属于"提交非法"—— 合法客户端不会发出这种 op）
            return $this->invalid('unknown_op_type', ['type' => $type]);
        }

        $out = [
            'score'    => $score,
            'moves'    => $swaps,
            'goals'    => self::goalProgress((array) ($rules['goals'] ?? []), $score, $collected, $cleared),
            'maxCombo' => $maxCombo,
            // 诊断信息（不参与比对，供黄金向量对齐与分歧定位）
            'fillVersion'      => self::FILL_VERSION,
            'scorePerCell'     => $scoreRule['perCell'],
            'boardFingerprint' => Match3Board::colorFingerprint($state),
            'collected'        => $collected,
            'cleared'          => $cleared,
        ];

        if ($this->wantFingerprints) {
            $out['turnFingerprints'] = $turnFps;
        }

        return $out;
    }

    /* ======================================================================
     *  忠实性判定
     * ====================================================================== */

    /**
     * 本引擎能否忠实重放该 schema？返回 null = 可以，否则返回原因
     *
     * @param array<string,mixed> $schema
     */
    public static function unsupportedReason(array $schema): ?string
    {
        // 1. 重力方向：只实现了 down
        foreach ((array) ($schema['regions'] ?? []) as $rg) {
            $gravity = (string) ($rg['gravity'] ?? 'down');
            if ($gravity !== 'down' && $gravity !== '') {
                return 'gravity_not_down:' . $gravity;
            }
        }

        // 2. 传送带 / 单向门：地形字符会改变掉落语义
        foreach ((array) ($schema['regions'] ?? []) as $rg) {
            foreach ((array) ($rg['layers']['terrain'] ?? []) as $line) {
                foreach (str_split((string) $line) as $ch) {
                    if ($ch !== '' && str_contains(self::EXOTIC_TERRAIN, $ch)) {
                        return 'exotic_terrain:' . $ch;
                    }
                }
            }
        }

        // 3. 行为系统（巧克力蔓延 / 毒液 / 生成器节奏等会改棋盘）
        if (!empty($schema['behaviours'])) {
            return 'has_behaviours';
        }

        // 4. 演员 / Boss 技能（lockCells 等会锁格）
        if (!empty($schema['actors'])) {
            return 'has_actors';
        }

        return null;
    }

    /* ======================================================================
     *  内部
     * ====================================================================== */

    /**
     * 解析 swap 的 a/b 坐标
     *
     * ⚠️ **协议约定：ops[].a / ops[].b 是 `[c, r]`（列在前，行在后）**
     *   —— 与 `Match3OpsValidator::validateSwap()` 的 `[$ac, $ar] = [$a[0], $a[1]]` 一致。
     *   而 `Match3Board` 的数组是 `[r][c]`（行在前）。
     *   方向搞反会静默算错分，因此单独抽一个方法并加测试锁住。
     *
     * @param  array<string,mixed> $op
     * @return array{0:int,1:int,2:int,3:int}|null [r1, c1, r2, c2]
     */
    protected static function parseSwap(array $op): ?array
    {
        $a = $op['a'] ?? null;
        $b = $op['b'] ?? null;

        if (!is_array($a) || !is_array($b) || count($a) < 2 || count($b) < 2) {
            return null;
        }
        if (!is_numeric($a[0]) || !is_numeric($a[1]) || !is_numeric($b[0]) || !is_numeric($b[1])) {
            return null;
        }

        return [(int) $a[1], (int) $a[0], (int) $b[1], (int) $b[0]];
    }

    protected function inBoard(array $state, int $r, int $c): bool
    {
        return $r >= 0 && $r < $state['rows'] && $c >= 0 && $c < $state['cols'];
    }

    /**
     * 目标进度快照（与 `LevelSimulator::goalProgress()` 同构，写回 claim.goals 的等价物）
     *
     * @param  array<int,mixed> $goals
     * @param  array<string,int> $collected
     * @param  array<string,int> $cleared
     * @return array<string,int>
     */
    protected static function goalProgress(array $goals, int $score, array $collected, array $cleared): array
    {
        $out = [];
        foreach ($goals as $g) {
            if (!is_array($g)) {
                continue;
            }
            $type = (string) ($g['type'] ?? '');
            if ($type === 'collect') {
                $key = (string) ($g['target'] ?? '');
                $out[$key] = (int) ($collected[$key] ?? 0);
            } elseif ($type === 'clear') {
                $key = (string) ($g['layer'] ?? '');
                $out[$key] = (int) ($cleared[$key] ?? 0);
            }
        }
        $out['score'] = $score;

        return $out;
    }

    /**
     * 记录一次降级（这是可观测指标：降级率 = 无法重放的比例）
     *
     * @param array<string,mixed> $ctx
     */
    protected function degrade(string $reason, array $ctx = []): void
    {
        ContextualLogger::warning('[Match3Replay][php] 无法忠实重放，回落 L1+L2 弱校验: ' . $reason, $ctx);
    }

    /**
     * 记录并返回"**提交非法**"（上层据此**拒单**，不是降级）
     *
     * 与 `degrade()` 的语义差别见 `Match3ReplayEngineInterface::INVALID_KEY`：
     * 非法 op 若也降级，攻击者塞一条坏 op 就能把 L3 关掉。
     *
     * @param  array<string,mixed> $ctx
     * @return array<string,string> 直接 return 给调用方
     */
    protected function invalid(string $reason, array $ctx = []): array
    {
        ContextualLogger::warning('[Match3Replay][php] ops 非法，L3 拒单: ' . $reason, $ctx);

        return [Match3ReplayEngineInterface::INVALID_KEY => $reason];
    }
}
