<?php
/**
 * 提交三件套校验器（ops + checkpoints + claim）
 *
 * 【三层校验】
 *   L1 操作合法性  Match3OpsValidator    相邻 / 同 region / 可交换 / 步数不超限 + checkpoints 结构
 *   L2 结果粗筛    scoreSanity           分数上下界、步数、目标结构（抓极端作弊）
 *   L3 完整重放    ReplayEngineInterface 用同 schema+seed 重跑 ops，与 claim 精确比对
 *
 * 【诚实说明】
 *   L1+L2 抓不到"用 AI 外挂打出的合法高分"，只有 L3 能做到——
 *   但 L3 也无法识别"玩家本人用了辅助工具"（操作本身合法）。
 *   这是三消品类的共同难题，业界靠行为检测补充，不靠重放。
 *
 * 【降级 vs 拒单（必须分清）】
 *   · **引擎能力不足**（未部署 / 超时 / 非 down 重力 / behaviours / rainbow 道具）
 *     → 退化为 L1+L2，记录告警但不阻断玩家。**宁可放过，不可误杀。**
 *   · **提交本身非法**（引擎返回 `INVALID_KEY`：不成线的交换、越界、未知道具…）
 *     → 直接 `invalid_op` 拒单。若这条也降级，攻击者塞一条坏 op 就能让 L3 失效。
 *
 * 【Checkpoint 的边界】
 *   02 §8.2 的 checkpoint 逐回合哈希比对需要棋盘状态，只有 L3 重放引擎能做。
 *   本类在弱校验模式下只做**结构校验**（t 递增、条数不超 ops、h 是字符串），
 *   用于拦掉明显伪造的提交；精确比对在 L3 引擎内完成。
 *
 * 迁移自 docs/match3/code/Match3Validator.php —— 为避免与插件既有的
 * validator\Match3Validator（StageValidatorInterface 实现）重名，改名 SubmissionValidator。
 */

declare(strict_types=1);

namespace plugin\match3game\service\level;

use wise\support\context\ContextualLogger;

class SubmissionValidator
{
    /** 错误码（02 §9.4 / 06 §十） */
    public const ERR_INVALID_OP       = 'invalid_op';
    public const ERR_CHECKPOINT       = 'invalid_checkpoint';
    public const ERR_RESULT_MISMATCH  = 'result_mismatch';
    public const ERR_BOARD_MISMATCH   = 'board_mismatch';
    public const ERR_MOVES_EXHAUSTED  = 'moves_exhausted';
    public const ERR_INVALID_CLAIM    = 'invalid_claim';

    /** 分数粗筛系数：单步理论最高分 = 格数 × SCORE_PER_CELL × MAX_COMBO_MUL */
    public const SCORE_PER_CELL   = 60;
    public const MAX_COMBO_MUL    = 3;
    public const SANITY_TOLERANCE = 1.5; // 允许 50% 冗余，避免误杀

    /**
     * L3 比对模式
     *
     *   strict       双向精确比对（`|重放 - 上报| ≤ 1%`）—— 要求两端分数口径已对齐
     *   upper_bound  **只查上界**：`上报 ≤ 重放 × (1 + 容差)`。
     *                不改写分数、不改通关判定（`replayed` 仍为 false），
     *                因此不会因 PHP 低估而误杀通关 —— 适合口径未对齐的过渡期
     *   off          不做比对（仅保留 L1+L2）
     */
    public const COMPARE_STRICT      = 'strict';
    public const COMPARE_UPPER_BOUND = 'upper_bound';
    public const COMPARE_OFF         = 'off';

    /** upper_bound 命中时的处置 */
    public const ACTION_FLAG   = 'flag';   // 只标记可疑（写入 meta.replaySuspicious），不拦截
    public const ACTION_REJECT = 'reject'; // 直接按 result_mismatch 拒绝

    protected Match3ReplayEngineInterface $replayEngine;

    protected string $compareMode = self::COMPARE_STRICT;

    /** upper_bound 容差（0.2 = 允许上报比重放高 20%） */
    protected float $scoreTolerance = 0.2;

    protected string $upperBoundAction = self::ACTION_FLAG;

    /** 目标进度的额外容差（+N 个） */
    protected int $goalSlack = 1;

    /**
     * 逐回合 checkpoint 哈希比对是否**直接拒单**
     *
     * ⚠️ 默认 `false` —— 只记录不拦截。理由见 `validateSubmission()` 里的 L3b 说明：
     * 客户端版本口径一旦与本端不一致，`true` 会造成**批量误杀**。
     * 观察 `checkpointViolations` 一段时间确认无系统性偏差后，再改成 `true`。
     */
    protected bool $checkpointStrict = false;

    /** 上一次提交的 checkpoint 违规明细（供监控 / 排障读取） */
    protected array $checkpointViolations = [];

    /**
     * @param array<string,mixed> $config `config('match3game.match3.submission')` 段
     */
    public function __construct(?Match3ReplayEngineInterface $replayEngine = null, array $config = [])
    {
        $this->replayEngine = $replayEngine ?? new Match3NullReplayEngine();

        if ($config === [] && function_exists('config')) {
            $all    = config('match3game.match3', []);
            $config = is_array($all['submission'] ?? null) ? $all['submission'] : [];
        }

        $mode = (string) ($config['compare_mode'] ?? self::COMPARE_STRICT);
        if (in_array($mode, [self::COMPARE_STRICT, self::COMPARE_UPPER_BOUND, self::COMPARE_OFF], true)) {
            $this->compareMode = $mode;
        }

        $this->scoreTolerance = max(0.0, (float) ($config['score_tolerance'] ?? 0.2));

        $action = (string) ($config['upper_bound_action'] ?? self::ACTION_FLAG);
        if (in_array($action, [self::ACTION_FLAG, self::ACTION_REJECT], true)) {
            $this->upperBoundAction = $action;
        }

        $this->goalSlack = max(0, (int) ($config['goal_slack'] ?? 1));
        $this->checkpointStrict = (bool) ($config['checkpoint_strict'] ?? false);
    }

    public function getCompareMode(): string
    {
        return $this->compareMode;
    }

    /**
     * 上一次提交的**逐回合** checkpoint 比对结果（S5-3）
     *
     * 空数组 = 全部一致 / 未参与比对。监控侧按 `clientVersion` 聚合这里的条数即可。
     *
     * @return array<int,string>
     */
    public function getCheckpointViolations(): array
    {
        return $this->checkpointViolations;
    }

    public function setCompareMode(string $mode): self
    {
        if (in_array($mode, [self::COMPARE_STRICT, self::COMPARE_UPPER_BOUND, self::COMPARE_OFF], true)) {
            $this->compareMode = $mode;
        }

        return $this;
    }

    public function setUpperBoundAction(string $action): self
    {
        if (in_array($action, [self::ACTION_FLAG, self::ACTION_REJECT], true)) {
            $this->upperBoundAction = $action;
        }

        return $this;
    }

    public function setScoreTolerance(float $tolerance): self
    {
        $this->scoreTolerance = max(0.0, $tolerance);

        return $this;
    }

    public function setReplayEngine(Match3ReplayEngineInterface $engine): self
    {
        $this->replayEngine = $engine;
        return $this;
    }

    public function getReplayEngine(): Match3ReplayEngineInterface
    {
        return $this->replayEngine;
    }

    /**
     * 校验一次提交
     *
     * @param  \stdClass|array<string,mixed> $schema 节点最终 schema（存档的那份）
     * @param  array<string,mixed> $submission { playToken, seed, ops, claim, checkpoints }
     * @return Match3ValidationResult
     */
    /**
     * 看广告加步的**服务端上限**（单次提交内）
     *
     * 复活 / 中途加步都由客户端权威加步（`Match3AdRewardHandler` 对这两个场景只埋点），
     * 所以 `claim.addedMoves` 只能信一部分 —— 这里给一个硬上限。
     * 定值依据：`config.ad.scenes` 的 revive 5 步 × 每日 3 次、add_moves 3 步 × 每日 3 次，另留余量。
     * 虚报本来换不到东西（分数与目标由重放按 ops 算），上限只是防止"把允许量撑到没边"。
     */
    public const MAX_AD_ADDED_MOVES = 30;

    /**
     * 本次提交允许的**额外步数**（加步道具 op + 看广告加步）
     *
     * 【为什么需要】`rules.moves` 只是**基础步数**，而局内加步有两个来源：
     *   ① `prop:add_moves` op —— 重放能算出来，但 L1 的交换计数原先只比 `rules.moves`
     *   ② 看激励视频加步（复活 / 中途加步）—— **没有任何 op**，只能由 `claim.addedMoves` 上报
     * 不漏算这两项的话，"用过加步道具或复活过"的正常玩家提交会被拒（`11001`）。
     */
    protected function extraMovesAllowance(array $ops, array $claim): int
    {
        // 与客户端 `BattleLoop.useProp('add_moves')` 的默认值同源（config.ad.scenes.add_moves.add_moves）
        $perProp = 3;
        if (function_exists('config')) {
            $perProp = max(1, (int) config('match3game.match3.ad.scenes.add_moves.add_moves', 3));
        }

        $addMoveOps = 0;
        foreach ($ops as $op) {
            if (is_array($op) && ($op['t'] ?? '') === 'prop' && ($op['p'] ?? '') === 'add_moves') {
                $addMoveOps++;
            }
        }

        $claimed = max(0, min((int) ($claim['addedMoves'] ?? 0), self::MAX_AD_ADDED_MOVES));

        return $addMoveOps * $perProp + $claimed;
    }

    public function validateSubmission($schema, array $submission): Match3ValidationResult
    {
        $ops   = $submission['ops'] ?? [];
        $claim = is_array($submission['claim'] ?? null) ? $submission['claim'] : [];
        $seed  = (int)($submission['seed'] ?? 0);

        /**
         * 客户端自报的棋盘算法版本（灰度共存）
         *
         * 老客户端不带这个字段 → 空串 → 引擎按"最新版"处理（向后兼容）。
         */
        $fillVersion = is_string($submission['fill_version'] ?? null)
            ? (string)$submission['fill_version']
            : '';

        if (!is_array($ops)) {
            return Match3ValidationResult::fail(self::ERR_INVALID_OP, ['ops 格式非法']);
        }

        // 局内加步的允许量（加步道具 op + 客户端上报的看广告加步）
        $extraMoves = $this->extraMovesAllowance($ops, $claim);

        // ---------- L1a：操作序列合法性 ----------
        $errors = Match3OpsValidator::validate($schema, $ops, $extraMoves);
        if ($errors !== []) {
            return Match3ValidationResult::fail(self::ERR_INVALID_OP, $errors);
        }

        // ---------- L1b：checkpoints 结构 ----------
        $errors = self::validateCheckpoints($schema, $ops, $submission['checkpoints'] ?? null, $extraMoves);
        if ($errors !== []) {
            return Match3ValidationResult::fail(self::ERR_CHECKPOINT, $errors);
        }

        // ---------- L2：结果粗筛 ----------
        $errors = $this->scoreSanity($schema, $ops, $claim);
        if ($errors !== []) {
            return Match3ValidationResult::fail(self::ERR_INVALID_CLAIM, $errors);
        }

        // ---------- L3：完整重放 ----------
        if ($this->compareMode === self::COMPARE_OFF) {
            ContextualLogger::warning('[Match3] compare_mode=off，结算判定信任客户端 claim', [
                'seed' => $seed, 'ops' => count($ops),
            ]);

            return $this->weakResult($claim, count($ops));
        }

        // ⚠️ 带上客户端版本：否则审核期"服务端 v7 + 用户手机 v6"会被批量拒单
        $replayed = $this->replayEngine->replay($schema, $seed, $ops, $fillVersion);

        // L3 报告"**提交非法**"（与"引擎能力不足"是两回事）→ **拒单**
        //
        // 为什么不能降级：降级 = 信任 claim。攻击者只要塞一条非法 op，
        // 就能让 L3 整体失效，那么 strict 再严也没意义 —— 这是 P0 修的绕过口。
        if (is_array($replayed) && isset($replayed[Match3ReplayEngineInterface::INVALID_KEY])) {
            $reason = (string) $replayed[Match3ReplayEngineInterface::INVALID_KEY];

            ContextualLogger::warning('[Match3] ops 非法，L3 拒单（不降级）', [
                'seed'   => $seed,
                'ops'    => count($ops),
                'reason' => $reason,
            ]);

            return Match3ValidationResult::fail(self::ERR_INVALID_OP, [
                '重放引擎判定该操作序列非法: ' . $reason,
            ]);
        }

        if ($replayed === null) {
            // 引擎未启用 / 无法忠实重放：放行，但标记 replayed=false 供风控统计
            ContextualLogger::warning('[Match3] 弱校验模式：结算判定信任客户端 claim', [
                'seed' => $seed, 'ops' => count($ops),
            ]);

            return $this->weakResult($claim, count($ops));
        }

        // ---------- L3b：checkpoint 哈希逐回合比对（02 §8.2） ----------
        //
        // L1b 只保证「结构合法」（t 递增、h 非空），**值对不对**只有重放引擎能验。
        // ⚠️ 缺省**不拒单**（见 `checkpoint_strict`）：老客户端的 t / h 口径未必与本端一致，
        //    一上来就 reject 会**批量误杀**（P6-B01 的教训）。
        //    先算出违规明细挂到 meta，确认口径一致后再打开开关。
        //
        // ⚠️ 必须把**完整提交体**传进去：客户端把 `checkpoints` 放在**顶层**
        //    （`body.checkpoints`，见 `core/submitter.ts`），不在 `claim` 里。
        $this->checkpointViolations = $this->compareCheckpointHashes($replayed, $claim, $submission);

        // 观察期**必须留痕**：`checkpoint_strict=false` 时不拒单，若连日志都不打，
        // 分歧就永远不可见 —— 那"观察"等于没观察（S5-4 要盯的就是这个数）。
        if ($this->checkpointViolations !== []) {
            ContextualLogger::warning('[Match3] checkpoint 逐回合比对不一致（默认只记录不拦截）', [
                'seed'     => $seed,
                'ops'      => count($ops),
                'count'    => count($this->checkpointViolations),
                'strict'   => $this->checkpointStrict ? 1 : 0,
                'fill'     => $fillVersion,
                'sample'   => array_slice($this->checkpointViolations, 0, 3),
            ]);
        }

        if ($this->checkpointViolations !== [] && $this->checkpointStrict) {
            return Match3ValidationResult::fail(self::ERR_BOARD_MISMATCH, $this->checkpointViolations);
        }

        // ---------- strict：双向精确比对（分数口径已对齐时使用） ----------
        if ($this->compareMode === self::COMPARE_STRICT) {
            $errors = $this->compare($replayed, $claim);
            if ($errors !== []) {
                return Match3ValidationResult::fail(self::ERR_RESULT_MISMATCH, $errors);
            }

            return Match3ValidationResult::ok([
                'score'       => (int)($replayed['score'] ?? 0),
                'moves'       => (int)($replayed['moves'] ?? count($ops)),
                'goals'       => (array)($replayed['goals'] ?? []),
                'maxCombo'    => (int)($replayed['maxCombo'] ?? 0),
                'replayMode'  => self::COMPARE_STRICT,
                'replayScore' => (int)($replayed['score'] ?? 0),
                'claimScore'  => (int)($claim['score'] ?? 0),
                // S5-4：标量计数（供埋点聚合）—— 明细数组只留给排障
                'checkpointMismatch' => count($this->checkpointViolations),
            ] + ($this->checkpointViolations !== [] ? ['checkpointViolations' => $this->checkpointViolations] : []),
            true,
        );
        }

        // ---------- upper_bound：只查上界 ----------
        // ⚠️ 刻意**不返回 replayed=true**：口径可能未对齐，
        //    通关判定仍交给 claim，避免"PHP 低估 → 正常玩家被判负"的误杀。
        //    分数也保持 claim 原值，不用重放分覆盖（PHP 低估不该惩罚玩家）。
        $violations = $this->compareUpperBound($replayed, $claim);

        if ($violations !== [] && $this->upperBoundAction === self::ACTION_REJECT) {
            return Match3ValidationResult::fail(self::ERR_RESULT_MISMATCH, $violations);
        }

        $out = $this->weakResult($claim, count($ops));
        $out->meta['replayMode']       = self::COMPARE_UPPER_BOUND;
        $out->meta['replayScore']      = (int)($replayed['score'] ?? 0);
        $out->meta['claimScore']       = (int)($claim['score'] ?? 0);
        $out->meta['replaySuspicious'] = $violations !== [];
        if ($this->checkpointViolations !== []) {
            $out->meta['checkpointViolations'] = $this->checkpointViolations;
        }
        $out->meta['checkpointMismatch'] = count($this->checkpointViolations);

        if ($violations !== []) {
            $out->meta['replayViolations'] = $violations;

            ContextualLogger::warning('[Match3] 上报分数超过重放上界（可疑）', [
                'seed'        => $seed,
                'replayScore' => (int)($replayed['score'] ?? 0),
                'claimScore'  => (int)($claim['score'] ?? 0),
                'action'      => $this->upperBoundAction,
                'violations'  => $violations,
            ]);
        }

        return $out;
    }

    /**
     * 弱校验结果：信任客户端 claim（`replayed=false`）
     *
     * @param array<string,mixed> $claim
     */
    protected function weakResult(array $claim, int $opsCount): Match3ValidationResult
    {
        return Match3ValidationResult::ok([
            'score' => (int)($claim['score'] ?? 0),
            'moves' => (int)($claim['movesUsed'] ?? $opsCount),
            'goals' => (array)($claim['goals'] ?? []),
        ], false);
    }

    /**
     * upper_bound 比对：**只查"上报是否超过重放"**（单向不等式）
     *
     * 为什么单向就够用：作弊的收益方向固定 —— 只会把分数报高。
     * 因此不需要两端精确相等，只需要证明"你报的分自己打不出来"。
     *
     * 为什么目标进度只遍历**重放侧**的键：客户端可能多上报一些服务端算不出的键
     * （例如某类特殊目标的中间态），遍历 claim 会把这些键当成"虚报"而误杀。
     *
     * 步数不做校验：`claim.movesUsed` 的语义（道具是否计步）尚未与客户端确认，
     * 且虚报步数对作弊者没有收益；L2 已用 `rules.moves` 兜住上界。
     *
     * @param  array<string,mixed> $replayed
     * @param  array<string,mixed> $claim
     * @return array<int,string> 违规说明（空 = 通过）
     */
    protected function compareUpperBound(array $replayed, array $claim): array
    {
        $violations = [];
        $tol        = $this->scoreTolerance;

        $replayScore = (int) ($replayed['score'] ?? 0);
        $claimScore  = (int) ($claim['score'] ?? 0);

        // +10 吸收取整与四舍五入差异
        $ceiling = (int) floor($replayScore * (1 + $tol)) + 10;
        if ($claimScore > $ceiling) {
            $violations[] = sprintf(
                '分数超重放上界：上报 %d > 重放 %d × (1+%d%%) = %d',
                $claimScore,
                $replayScore,
                (int) round($tol * 100),
                $ceiling
            );
        }

        $replayGoals = (array) ($replayed['goals'] ?? []);
        $claimGoals  = (array) ($claim['goals'] ?? []);

        foreach ($replayGoals as $key => $actual) {
            if ($key === 'score') {
                continue;
            }
            $reported = (int) ($claimGoals[$key] ?? 0);
            $goalCeil = (int) floor((int) $actual * (1 + $tol)) + $this->goalSlack;

            if ($reported > $goalCeil) {
                $violations[] = sprintf(
                    '目标进度超重放上界：%s 上报 %d > 重放 %d',
                    (string) $key,
                    $reported,
                    (int) $actual
                );
            }
        }

        return $violations;
    }

    /**
     * L1b：checkpoints 结构校验（02 §8.2）
     *
     * 只校验结构，不校验哈希值（哈希需要棋盘状态，仅 L3 引擎可做）。
     *
     * @param  array<int,array<string,mixed>> $ops
     * @param  mixed $checkpoints
     * @return array<int, string>
     */
    public static function validateCheckpoints($schema, array $ops, $checkpoints, int $extraMoves = 0): array
    {
        if ($checkpoints === null || $checkpoints === '') {
            return []; // 未上报时跳过（弱校验期允许）
        }

        if (!is_array($checkpoints)) {
            return ['checkpoints 格式非法'];
        }

        $errors  = [];
        $lastT   = -1;
        $maxMoves = self::readInt($schema, 'rules.moves');
        // 加步来源会多出回合，条数上限同口径放宽（见 `extraMovesAllowance`）
        $limit    = ($maxMoves ?? count($ops)) + max(0, $extraMoves);

        if (count($checkpoints) > $limit) {
            $errors[] = 'checkpoints 条数 ' . count($checkpoints) . " 超过上限 {$limit}";
        }

        foreach ($checkpoints as $i => $cp) {
            if (!is_array($cp)) {
                $errors[] = "checkpoints[{$i}] 不是对象";
                continue;
            }
            if (!isset($cp['t']) || !is_numeric($cp['t'])) {
                $errors[] = "checkpoints[{$i}] 缺少回合号 t";
                continue;
            }
            $t = (int)$cp['t'];
            if ($t < 0) {
                $errors[] = "checkpoints[{$i}].t 不能为负";
            }
            if ($t <= $lastT) {
                $errors[] = "checkpoints[{$i}].t={$t} 未严格递增（上一项 {$lastT}）";
            }
            $lastT = $t;

            if (!isset($cp['h']) || !is_string($cp['h']) || $cp['h'] === '') {
                $errors[] = "checkpoints[{$i}] 缺少棋盘摘要 h";
            }
        }

        return $errors;
    }

    /**
     * L2：分数合理性粗筛
     *
     * 只能抓"离谱"的作弊（如上报 100 万），抓不了精细作弊。
     *
     * @param  array<int, array<string,mixed>> $ops
     * @param  array<string,mixed> $claim
     * @return array<int, string>
     */
    public function scoreSanity($schema, array $ops, array $claim): array
    {
        $errors = [];

        if ($claim === []) {
            return []; // 未上报 claim 时跳过（部分客户端可能不传）
        }

        $score = $claim['score'] ?? null;
        if ($score !== null && !is_numeric($score)) {
            return ['claim.score 不是数字'];
        }
        $score = (int)$score;

        if ($score < 0) {
            return ['claim.score 为负数'];
        }

        // 步数（含局内加步的允许量，口径与 L1 一致）
        $movesUsed = (int)($claim['movesUsed'] ?? 0);
        $maxMoves  = $this->readInt($schema, 'rules.moves');
        $movesCap  = $maxMoves === null ? null : $maxMoves + $this->extraMovesAllowance($ops, $claim);
        if ($movesCap !== null && $movesUsed > $movesCap) {
            $errors[] = "已用步数 {$movesUsed} 超过上限 {$movesCap}";
        }

        // 分数上界
        $cols = $this->readInt($schema, 'board.cols') ?? 9;
        $rows = $this->readInt($schema, 'board.rows') ?? 9;
        $effectiveMoves = min($movesUsed > 0 ? $movesUsed : count($ops), $movesCap ?? count($ops));

        $perMoveMax = $cols * $rows * self::SCORE_PER_CELL * self::MAX_COMBO_MUL;
        $upperBound = (int)($perMoveMax * max(1, $effectiveMoves) * self::SANITY_TOLERANCE);

        if ($score > $upperBound) {
            $errors[] = "claim.score {$score} 超过理论上限 {$upperBound}（疑似篡改）";
        }

        // 目标进度结构
        $goals = $claim['goals'] ?? null;
        if ($goals !== null) {
            if (!is_array($goals)) {
                $errors[] = 'claim.goals 格式非法';
            } else {
                foreach ($goals as $k => $v) {
                    if (!is_numeric($v) || $v < 0) {
                        $errors[] = "claim.goals[{$k}] 非法: " . var_export($v, true);
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * 重放结果 vs 客户端 claim 比对
     *
     * @param array<string,mixed> $replayed
     * @param array<string,mixed> $claim
     * @return array<int, string>
     */
    protected function compare(array $replayed, array $claim): array
    {
        $errors = [];

        $replayScore = (int)($replayed['score'] ?? 0);
        $claimScore  = (int)($claim['score'] ?? -1);

        // 允许 1% 浮点/取整误差
        $diff = abs($replayScore - $claimScore);
        if ($claimScore >= 0 && $diff > max(10, $replayScore * 0.01)) {
            $errors[] = "分数不一致：重放 {$replayScore} vs 上报 {$claimScore}";
        }

        $replayMoves = (int)($replayed['moves'] ?? 0);
        $claimMoves  = (int)($claim['movesUsed'] ?? -1);
        if ($claimMoves >= 0 && $replayMoves > 0 && abs($replayMoves - $claimMoves) > 1) {
            $errors[] = "步数不一致：重放 {$replayMoves} vs 上报 {$claimMoves}";
        }

        // 目标进度：重放值应 >= 上报值（上报不能超过实际达成）
        $replayGoals = (array)($replayed['goals'] ?? []);
        $claimGoals  = (array)($claim['goals'] ?? []);
        foreach ($claimGoals as $k => $v) {
            $actual = (int)($replayGoals[$k] ?? 0);
            if ((int)$v > $actual + 1) {
                $errors[] = "目标进度虚报：{$k} 上报 {$v} > 实际 {$actual}";
            }
        }

        return $errors;
    }

    /**
     * @param  \stdClass|array<string,mixed> $schema
     */
    private static function readInt($schema, string $path): ?int
    {
        $arr = is_object($schema)
            ? json_decode(json_encode($schema, JSON_UNESCAPED_UNICODE), true)
            : $schema;

        $cur = $arr;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return null;
            }
            $cur = $cur[$seg];
        }
        return is_numeric($cur) ? (int)$cur : null;
    }

    /**
     * L3b：把客户端上报的 checkpoints 与重放产出的 `turnFingerprints` **逐回合**比对
     *
     * 【为什么不放在 L1b】checkpoint 的 `h` 是**棋盘指纹**，只有跑完重放才拿得到；
     *   L1b 发生在 replay 之前 —— 所以值比对只能挂在 L3 之后。
     *
     * 【为什么不默认拒单】`t` 从 0 还是 1 起、"看广告加步"算不算一个回合，两端口径
     *   还没书面确认；一旦不一致就拦截，等于把**所有老客户端**挡在门外
     *   （P6-B01 的教训：宁可放过，不可误杀）。所以这里只产出明细，
     *   由 `checkpoint_strict` 决定是否升级成 `11004`。
     *
     * 【口径】
     *   · 只比对**双方都有**的回合 —— 任一侧缺失就跳过，不计违规；
     *   · `t` = 已用步数（`movesUsed`）：客户端 `BattleLoop.afterTurn` 与 TS 重放器
     *     用的是**同一份** `recordCheckpoints` 逻辑，且都"同 t 只记首次"，口径同源；
     *   · `h` = `colorFingerprint(棋盘颜色层)`（不含覆盖层），两端同一个算法。
     *
     * 【checkpoints 在哪】客户端放在**提交体顶层**（`body.checkpoints`，见
     *   `core/submitter.ts`）；`claim.checkpoints` 只是兼容老测试与"塞在 claim 里"的写法。
     *
     * @param  array<string,mixed> $replayed   L3 重放产物（含 `turnFingerprints`）
     * @param  array<string,mixed> $claim     客户端上报的结算摘要
     * @param  array<string,mixed> $submission 完整提交体（checkpoints 的权威位置）
     * @return array<int,string> 违规说明（空 = 通过）
     */
    protected function compareCheckpointHashes(array $replayed, array $claim, array $submission = []): array
    {
        $checkpoints = $submission['checkpoints'] ?? ($claim['checkpoints'] ?? null);
        if (!is_array($checkpoints) || $checkpoints === []) {
            return []; // 客户端没上报 → 不比对（由 `require_checkpoints` 另管）
        }

        $fingerprints = $replayed['turnFingerprints'] ?? null;
        if (!is_array($fingerprints) || $fingerprints === []) {
            return []; // 引擎不支持指纹 → 无从比对，放行
        }

        $violations = [];
        foreach ($checkpoints as $cp) {
            if (!is_array($cp) || !isset($cp['t']) || !is_numeric($cp['t'])) {
                continue; // 结构问题归 L1b，这里不重复报
            }

            $turn = (int)$cp['t'];
            $got  = $fingerprints[$turn] ?? null;
            if ($got === null) {
                continue; // 引擎没跑到这一回合 → 不比对
            }

            $want = (string)($cp['h'] ?? '');
            if ($want === '') {
                continue; // 空摘要归 L1b
            }

            if ($want !== (string)$got) {
                $violations[] = sprintf(
                    '第 %d 回合棋盘摘要不一致：上报 %s ≠ 重放 %s',
                    $turn,
                    $want,
                    (string)$got
                );
            }
        }

        return $violations;
    }
}
