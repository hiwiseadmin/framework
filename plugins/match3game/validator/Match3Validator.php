<?php

declare(strict_types=1);

/**
 * 消消乐结算校验器（Level Schema v1 + 三层校验）
 *
 * 实现 wise-game-stage 的 StageValidatorInterface，是固定关卡链路的**唯一结算入口**。
 *
 * 【校验层次】
 *   0. 结构      ops 条数上限 / 道具合法 / 时长下限
 *   L1 操作合法  SubmissionValidator → Match3OpsValidator（相邻/同 region/可交换/步数）+ checkpoints 结构
 *   L2 结果粗筛  SubmissionValidator::scoreSanity（分数上下界、步数、目标结构）
 *               + ScoreGuardService（收集量/分数理论上限，插件既有）
 *   L3 完整重放  ReplayEngineInterface（同 schema+seed 重跑 ops，与 claim 精确比对）
 *
 * 【通关判定】
 *   L3 启用 → 用重放得到的 goals/score 判定（可信）
 *   L3 未启用 → 退化为信任 claim.passed（弱校验内测期，06 §6.2；上线前必须切 L3）
 *
 * 【提交协议】02 §8
 *   {
 *     play_token, seed,
 *     ops:         [{t:'swap', a:[c,r], b:[c,r]}, {t:'prop', p:'hammer', at:[c,r]}],
 *     checkpoints: [{t:0,h:'a3f2c1'}, ...],
 *     claim:       {score, movesUsed, passed, goals:{ice:30}, maxCombo},
 *     items_used:  [{item:'hammer', count:1}],   // 背包扣费用（插件既有）
 *     duration_ms
 *   }
 */
namespace plugin\match3game\validator;

use wise\game\stage\contract\StageValidatorInterface;
use wise\game\stage\contract\StageResult;
use plugin\match3game\model\Match3LevelDef;
use plugin\match3game\service\ScoreGuardService;
use plugin\match3game\service\PropUsageService;
use plugin\match3game\service\level\LevelSchemaFactory;
use plugin\match3game\service\level\ReplayEngineFactory;
use plugin\match3game\service\level\SubmissionValidator;

class Match3Validator implements StageValidatorInterface
{
    protected ScoreGuardService $guard;

    protected PropUsageService $props;

    protected SubmissionValidator $submission;

    /** 单局 ops 条数硬上限 */
    protected int $maxOps = 500;

    /** 是否强制要求 ops */
    protected bool $requireOps = true;

    public function __construct(
        ?ScoreGuardService $guard = null,
        ?PropUsageService $props = null,
        ?SubmissionValidator $submission = null
    ) {
        $config           = config('match3game.match3', []);
        $this->guard      = $guard ?? new ScoreGuardService($config['validator'] ?? []);
        $this->props      = $props ?? new PropUsageService();

        $submissionCfg    = is_array($config['submission'] ?? null) ? $config['submission'] : [];

        // L3 引擎 + 比对模式（compare_mode / score_tolerance / upper_bound_action）同源注入
        $this->submission = $submission ?? new SubmissionValidator(
            ReplayEngineFactory::make($config['replay_engine'] ?? null),
            $submissionCfg
        );

        $this->maxOps     = (int) ($submissionCfg['max_ops'] ?? 500);
        $this->requireOps = (bool) ($submissionCfg['require_ops'] ?? true);
    }

    /**
     * @param array|null $levelArr 已打好补丁的关卡行（PvP 带遗物时由 BattleService 传入，
     *                             与下发 payload 用**同一份** schema，否则重放必然 result_mismatch）
     */
    public function validate(int $userId, string $stageCode, $submission, ?array $levelArr = null): StageResult
    {
        // 0. 读取关卡定义 → Level Schema v1
        if ($levelArr === null) {
            $level = Match3LevelDef::where('code', $stageCode)->find();
            if (!$level) {
                return StageResult::fail('level_not_found', "关卡不存在: {$stageCode}");
            }
            $levelArr = $level->toArray();
        }

        if ((int) ($levelArr['status'] ?? 1) !== 1) {
            return StageResult::fail('level_disabled', "关卡已停用: {$stageCode}");
        }

        $schema = LevelSchemaFactory::fromRow($levelArr);
        $rules  = (array) ($schema['rules'] ?? []);
        $board  = (array) ($schema['board'] ?? []);
        $goals  = (array) ($rules['goals'] ?? []);

        $sub   = is_array($submission) ? $submission : [];
        $ops   = is_array($sub['ops'] ?? null) ? $sub['ops'] : [];
        $claim = is_array($sub['claim'] ?? null) ? $sub['claim'] : [];

        // 1. ops 条数硬上限（防御超长 payload）
        if (count($ops) > $this->maxOps) {
            return StageResult::fail('too_many_ops', "ops 条数 " . count($ops) . " 超过上限 {$this->maxOps}");
        }

        // 2. 道具合法 + 加步
        try {
            $normalized = $this->props->validateAndNormalize((array) ($sub['items_used'] ?? []));
            $addedMoves = $this->props->calcAddedMoves($normalized);
        } catch (\Throwable $e) {
            return StageResult::fail('invalid_prop', $e->getMessage());
        }

        // 无 ops 且未宣称通关 → 按"放弃/失败"处理，跳过三层校验
        $claimPassed = (bool) ($claim['passed'] ?? false);
        $needOps     = $this->requireOps || $ops !== [] || $claimPassed;

        $meta     = ['score' => 0, 'moves' => 0, 'goals' => [], 'maxCombo' => 0];
        $replayed = false;

        if ($needOps) {
            // 3. 三层校验（L1 + L2 + L3）
            $res = $this->submission->validateSubmission($schema, $sub);
            if (!$res->passed) {
                return StageResult::fail($res->errorCode ?? 'settle_invalid', implode('; ', $res->errors));
            }
            $meta     = array_merge($meta, $res->meta);
            $replayed = $res->replayed;
        } else {
            $meta['score'] = (int) ($claim['score'] ?? 0);
            $meta['moves'] = (int) ($claim['movesUsed'] ?? 0);
            $meta['goals'] = (array) ($claim['goals'] ?? []);
        }

        $score     = (int) $meta['score'];
        $movesUsed = (int) $meta['moves'];
        // ⚠️ `goals` 里可能带 `score` 汇总键（重放引擎的输出形状，见 `ScoreGuardService::SCALAR_KEYS`）：
        // 它不是"收集个数"（`checkCollect` 会跳过）也不需要在这里剔除 ——
        // `checkGoalMet` 只读 collect/clear 的元素键，score 型目标另有 `scoreTargetOf()` 走 `$score` 判定。
        $collect   = (array) ($meta['goals'] ?? []);
        $maxMoves  = (int) ($rules['moves'] ?? 0);

        // 4. 时长下限（防秒结算；放弃场景不校验）
        //
        // 两个参数都不是"客户端说多少就是多少"：
        //   · `$movesUsed`：下限按**步数**折算（8 步的下限是 2.4s，而非恒定的 30s）——
        //     否则"连锁自动消除速通"会被误杀（2026-09-27 真机 14709ms，见 13 §三十五）；
        //   · `server_elapsed_ms`：服务端自测时长（`battle/start` → 现在），由 BattleService 注入，
        //     客户端上报偏小时用它兜底 —— 只放宽、不收紧。
        if ($needOps) {
            $err = $this->guard->checkDuration(
                (int) ($sub['duration_ms'] ?? 0),
                $movesUsed,
                (int) ($sub['server_elapsed_ms'] ?? 0)
            );
            if ($err !== null) {
                return StageResult::fail('duration_too_short', $err);
            }
        }

        // 5. 理论上限（ScoreGuard，L2 加强）
        $err = $this->guard->checkMoves($movesUsed, $maxMoves, $addedMoves);
        if ($err !== null) {
            return StageResult::fail('moves_exceeded', $err);
        }

        $rows = (int) ($board['rows'] ?? 9);
        $cols = (int) ($board['cols'] ?? 9);

        $err = $this->guard->checkCollect($collect, $rows, $cols, $maxMoves + $addedMoves);
        if ($err !== null) {
            return StageResult::fail('collect_limit', $err);
        }

        $err = $this->guard->checkScore($score, $rows, $cols, $maxMoves + $addedMoves);
        if ($err !== null) {
            return StageResult::fail('score_limit', $err);
        }

        // 6. 通关判定（06 §6.2）
        $passed = $this->decidePass($replayed, $claimPassed, $collect, $score, $goals);

        // 7. 星级（score 维度）
        $stars = $this->calcStars($score, (array) ($rules['starScores'] ?? []));
        $stars = $passed ? max(1, $stars) : 0;

        return StageResult::pass([
            'stars'      => $stars,
            'score'      => $score,
            'moves_used' => $movesUsed,
            'max_combo'  => (int) ($meta['maxCombo'] ?? ($claim['maxCombo'] ?? 0)),
            'outcome'    => $passed ? 'win' : 'lose',
            'replayed'   => $replayed,
            // 透传给 BattleService：判定链路据此决定是否标记可疑（如 PvP is_cheat）
            'replay_mode'       => (string) ($meta['replayMode'] ?? 'off'),
            'replay_suspicious' => (bool) ($meta['replaySuspicious'] ?? false),
            'replay_score'      => $meta['replayScore'] ?? null,
            'claim_score'       => $meta['claimScore'] ?? null,
            // S5-4：逐回合 checkpoint 不一致的**条数**（标量，供埋点按版本聚合）
            'checkpoint_mismatch' => (int) ($meta['checkpointMismatch'] ?? 0),
            // 分桶维度：客户端包版本（可选，客户端未上报时为空）与算法版本（已有）
            'client_version' => (string) ($sub['client_version'] ?? ''),
            'fill_version'   => (string) ($sub['fill_version'] ?? ''),
        ]);
    }

    // ================================================================
    //  通关判定
    // ================================================================

    /**
     * 通关判定（06 §6.2）
     *
     * L3 已启用时用重放得到的 goals 判定（绝对可信）；
     * 未启用时只能信任 claim.passed —— 弱校验内测期的折衷，上线前必须切 L3。
     *
     * @param array $collect 目标进度 {key => count}（L3 下来自重放，否则来自 claim）
     * @param array $goals   rules.goals
     */
    protected function decidePass(
        bool $replayed,
        bool $claimPassed,
        array $collect,
        int $score,
        array $goals
    ): bool {
        if (!$replayed) {
            return $claimPassed;
        }

        if ($this->checkGoalMet($collect, $goals) !== null) {
            return false;
        }

        $scoreTarget = $this->scoreTargetOf($goals);

        return $scoreTarget <= 0 || $score >= $scoreTarget;
    }

    // ================================================================
    //  辅助
    // ================================================================

    /**
     * 校验 collect / clear 目标是否达标
     *
     * 协议（01 §4.1 / 02 §6），目标进度是 {key => count}：
     *   · {"type":"collect","target":"red_gem","count":25} → goals['red_gem']
     *   · {"type":"clear","layer":"ice","count":30}        → goals['ice']
     * 其余目标类型（score/boss/spread/survive）由分数或后续阶段判定，此处跳过。
     *
     * @param array $collect 目标进度 {key => count}
     * @param array $goals   rules.goals
     * @return string|null 未达标原因；null 表示达标
     */
    protected function checkGoalMet(array $collect, array $goals): ?string
    {
        foreach ($goals as $cond) {
            if (!is_array($cond)) {
                continue;
            }

            $type = (string) ($cond['type'] ?? '');

            if ($type === 'collect') {
                $key = (string) ($cond['target'] ?? '');
            } elseif ($type === 'clear') {
                $key = (string) ($cond['layer'] ?? '');
            } else {
                continue;
            }

            $target = (int) ($cond['count'] ?? 0);
            if ($key === '' || $target <= 0) {
                continue;
            }

            $got = (int) ($collect[$key] ?? 0);
            if ($got < $target) {
                return sprintf('收集未达标: %s=%d（需要 %d）', $key, $got, $target);
            }
        }

        return null;
    }

    /**
     * 取出 score 类型目标的分值底限
     *
     * @param array $goals rules.goals
     */
    protected function scoreTargetOf(array $goals): int
    {
        foreach ($goals as $cond) {
            if (is_array($cond) && (($cond['type'] ?? '') === 'score')) {
                return (int) ($cond['count'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * 星级评定（score 维度，rules.starScores = [一星, 二星, 三星]）
     *
     * @param int   $score      最终得分
     * @param array $starScores 三个阈值，递增
     * @return int 0-3 星
     */
    protected function calcStars(int $score, array $starScores): int
    {
        $three = (int) ($starScores[2] ?? 0);
        $two   = (int) ($starScores[1] ?? 0);
        $one   = (int) ($starScores[0] ?? 0);

        if ($three > 0 && $score >= $three) {
            return 3;
        }
        if ($two > 0 && $score >= $two) {
            return 2;
        }
        if ($one > 0 && $score >= $one) {
            return 1;
        }

        return 0;
    }
}
