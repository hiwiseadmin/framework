<?php

declare(strict_types=1);

namespace plugin\match3game\service;

use think\facade\Db;
use plugin\match3game\contract\Match3Constants;
use plugin\match3game\contract\RewardReason;
use plugin\match3game\exception\Match3ErrorCode;
use plugin\match3game\exception\Match3Exception;
use plugin\match3game\model\Match3LevelDef;
use plugin\match3game\model\Match3Battle;
use plugin\match3game\validator\Match3Validator;
use plugin\match3game\service\level\LevelSchemaFactory;
use plugin\match3game\service\level\Match3FillVersion;
use plugin\match3game\service\level\SubmissionValidator;
use plugin\match3game\service\social\FriendService;
use plugin\match3game\service\social\PvpService;
use plugin\match3game\service\social\SocialException;
use wise\support\context\ContextualLogger;

/**
 * 对局编排服务（文档 §7.1 / §7.2）
 *
 * 开始对局：验解锁 → 扣体力 → 预扣道具 → 签发 battle_token + seed → 写会话
 * 提交结算：幂等 CAS → 结算校验 → 发奖/进度/排行/道具扣减/任务事件/埋点
 *
 * 【Phase 5 扩展】`start($battleId)` 支持对战：seed 取 `match3_pvp_battle.seed`（双方一致），
 * `submit` 里 `battle_id` 存在时自动记录对战成绩并触发（双方完成后的）判定与赛季积分。
 */
class BattleService
{
    protected string $gameId;

    protected array $config;

    /** seed / battle_token 签发（棋盘生成见 level\Match3Board，不在此处） */
    protected SeedIssuer $seed;

    protected LevelProgressService $progress;

    protected PropUsageService $props;

    protected RewardBridgeService $bridge;

    protected Match3Validator $validator;

    protected PvpService $pvp;

    protected FriendService $friends;

    /** 社交配置（social.pvp_cost_energy 等） */
    protected array $socialCfg;

    public function __construct(
        ?SeedIssuer $seed = null,
        ?LevelProgressService $progress = null,
        ?PropUsageService $props = null,
        ?RewardBridgeService $bridge = null,
        ?Match3Validator $validator = null,
        ?PvpService $pvp = null,
        ?FriendService $friends = null
    ) {
        $config          = config('match3game.match3', []);
        $this->gameId    = Match3Constants::GAME_ID;
        $this->config    = $config;
        $this->seed      = $seed ?? new SeedIssuer($config);
        $this->progress  = $progress ?? new LevelProgressService();
        $this->props     = $props ?? new PropUsageService();
        $this->bridge    = $bridge ?? new RewardBridgeService($config);
        $this->validator = $validator ?? new Match3Validator();
        $this->pvp       = $pvp ?? new PvpService();
        $this->friends   = $friends ?? new FriendService();
        $this->socialCfg = (array) ($config['social'] ?? []);
    }

    // ================================================================
    //  开始对局（§7.1）
    // ================================================================

    /**
     * 开始对局
     *
     * @param string|null $pvpBattleId 对战 ID（P5-B03：seed 锁定为对战双方一致的种子）
     * @return array {play_token, seed, level, energy, props_left, server_ts}[, is_battle, battle_id, ...]
     * @throws Match3Exception 关卡不存在/停用/未解锁/体力不足/道具不足
     */
    public function start(int $userId, string $levelCode, array $useProps = [], ?string $pvpBattleId = null): array
    {
        // 0. 对战模式：校验参与方 + **锁定 seed**（P5-B03 最关键改造）
        $pvp = null;
        if ($pvpBattleId !== null && $pvpBattleId !== '') {
            try {
                $pvp = $this->pvp->prepareStart($userId, $pvpBattleId, $levelCode);
            } catch (SocialException $e) {
                // 对战不可用（过期/非参与方/已提交）→ BATTLE_INVALID（10006）
                throw Match3Exception::from(Match3ErrorCode::BATTLE_INVALID, $e->getMessage());
            }
        }

        // 1. 关卡存在且启用
        $level = Match3LevelDef::where('code', $levelCode)->find();
        if (!$level) {
            throw Match3Exception::from(Match3ErrorCode::LEVEL_NOT_FOUND);
        }
        if ((int) $level->status !== 1) {
            throw Match3Exception::from(Match3ErrorCode::LEVEL_DISABLED);
        }

        // 2. 解锁校验（对战局豁免：被挑战的关卡不应要求挑战者先自己解锁）
        if ($pvp === null && !$this->progress->checkUnlocked($userId, $levelCode)) {
            throw Match3Exception::from(Match3ErrorCode::LEVEL_LOCKED);
        }

        // 3. 预扣开局道具（失败整单不扣体力）
        $normalized = $this->props->validateAndNormalize($useProps);
        try {
            if (!empty($normalized)) {
                $this->bridge->consumeProps($userId, $normalized, RewardReason::BATTLE_START);
            }
        } catch (\Throwable $e) {
            throw Match3Exception::from(Match3ErrorCode::PROP_NOT_ENOUGH, $e->getMessage());
        }

        // 4. 扣体力（对战局默认免费：挑战赛不该被体力卡住，可由配置打开）
        $energy = ($pvp === null || !empty($this->socialCfg['pvp_cost_energy']))
            ? $this->costEnergy($userId, $levelCode)
            : null;

        // 5. 签发 seed + battle_token（对战局用锁定的 seed → 双方棋盘一致）
        $seed  = (int) ($pvp['seed'] ?? $this->seed->generateSeed());
        $token = $this->seed->generateBattleToken($userId, $levelCode, $seed);

        // 6. 写会话（playing）
        $battle = new Match3Battle();
        $battle->user_id      = $userId;
        $battle->level_code   = $levelCode;
        $battle->battle_token = $token;
        $battle->seed         = $seed;
        $battle->status       = Match3Battle::STATUS_PLAYING;
        $battle->started_at   = date('Y-m-d H:i:s');
        $battle->client_ip    = $this->clientIp();
        $battle->save();

        // 7. 更新玩家聚合（play_count +1）
        $this->progress->recordStart($userId, $levelCode);

        // 8. 下发关卡（对战带遗物时，下发**打过补丁的** schema，与校验用同一份）
        $levelArr = $level->toArray();
        if ($pvp !== null && !empty($pvp['relics'])) {
            $patched = $this->pvp->levelForValidation((string) $pvp['battleId']);
            if ($patched !== null) {
                $levelArr = $patched;
            }
        }
        $stage = $this->levelPayload($levelArr);
        if ($pvp !== null && !empty($pvp['relics'])) {
            $stage['relics'] = $pvp['relics'];
        }

        $result = [
            // 提交时用 play_token 回传（02 §8），即本局的 battle_token，一次性
            'play_token'   => $token,
            'seed'         => $seed,
            'level'        => $stage,
            'energy'       => $energy,
            'props_left'   => $normalized,
            'server_ts'    => (int) (microtime(true) * 1000),
            // 算法版本握手（strict 的保险丝）：客户端 mismatch 时应提示强更、拒绝开局
            'fill_version' => Match3FillVersion::CURRENT,
        ];

        // 9. 对战附加信息（盲打：对手分数未完成前不可见）
        if ($pvp !== null) {
            $result['is_battle']     = true;
            $result['battle_id']     = (string) $pvp['battleId'];
            $result['battle_mode']   = (string) $pvp['mode'];
            $result['battle_role']   = (string) $pvp['role'];
            $result['opponent_score'] = null;
        }

        return $result;
    }

    // ================================================================
    //  提交结算（§7.2）
    // ================================================================

    /**
     * 提交结算
     *
     * @return array {passed, stars, score, rewards, unlock_next, level_star_total}
     * @throws Match3Exception 对局无效/过期/结算校验不通过
     */
    public function submit(int $userId, array $submission): array
    {
        // 提交协议（02 §8）：play_token 即开局签发的 battle_token，一次性
        $token = (string) ($submission['play_token'] ?? '');

        // 全程同一事务：FOR UPDATE 行锁 + CAS 状态转换 + 结算 + 发奖，
        // 确保并发重复结算只会成功一次。
        return Db::transaction(function () use ($userId, $submission, $token) {
            // 1. 幂等 CAS：行锁查询会话（FOR UPDATE 仅在事务内生效）
            $battle = Match3Battle::where('battle_token', $token)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();

            if (!$battle) {
                throw Match3Exception::from(Match3ErrorCode::BATTLE_INVALID);
            }

            // ⚠️ 顺序有意为之：`STATUS_DONE` 必须**放行**到下面的幂等分支，
            //    只有"放弃 / 过期"等非 playing 态才在这里拒单。
            if ((int) $battle->status !== Match3Battle::STATUS_PLAYING
                && (int) $battle->status !== Match3Battle::STATUS_DONE) {
                throw Match3Exception::from(Match3ErrorCode::BATTLE_INVALID, '对局已结算或已失效');
            }

            if ((int) $battle->status === Match3Battle::STATUS_DONE) {
                // 【B7 幂等】已结算的局再提交（断线重提 / 客户端重试）→ **原样返回首次结算结果**
                //
                // ⚠️ 关键：只把快照读出来返回，**绝不**再走一遍发奖 / 进度 / 榜单 ——
                //    幂等的要害不在"返回什么"，而在"不再产生任何副作用"。
                $snapshot = $battle->settle_result;

                if (is_array($snapshot) && $snapshot !== []) {
                    ContextualLogger::info('[match3] 重复提交 → 幂等返回首次结算结果', [
                        'user_id'    => $userId,
                        'level_code' => (string) $battle->level_code,
                    ]);

                    return $snapshot;
                }

                // 迁移前就已结算的老局没有快照 → 没有"首次结果"可返回，维持拒单
                throw Match3Exception::from(Match3ErrorCode::BATTLE_INVALID, '对局已结算且无结算快照');
            }

            // 会话过期检查（config battle.session_ttl）
            if ($this->isExpired($battle->started_at)) {
                $battle->status = Match3Battle::STATUS_EXPIRED;
                $battle->save();
                throw Match3Exception::from(Match3ErrorCode::BATTLE_EXPIRED);
            }

            // 2. 结算校验（§3.3 第 2~8 项）
            //    对战带遗物时用"打过同一批补丁的 schema"，否则重放必然 result_mismatch
            $pvpBattleId = (string) ($submission['battle_id'] ?? '');
            $pvpLevelArr = $pvpBattleId !== '' ? $this->pvp->levelForValidation($pvpBattleId) : null;

            // 时长校验的**可信下界**：服务端自己测的 `battle/start → now`。
            // 客户端上报的 `duration_ms` 可能偏小（续局只算本进程、切后台、连锁自动消除速通），
            // 而它偏小时不该被判成秒结算 —— 2026-09-27 真机 14709ms 就是这么被误杀的（13 §三十五）。
            // 注入到 submission 是**插件内部约定**（消费点唯一：Match3Validator 第 4 步）。
            $submission['server_elapsed_ms'] = $this->elapsedMs((string) $battle->started_at);

            $result = $this->validator->validate($userId, $battle->level_code, $submission, $pvpLevelArr);
            $meta   = $result->getMeta();

            $passed = $result->isPassed();
            $stars  = (int) ($meta['stars'] ?? 0);
            $score  = (int) ($meta['score'] ?? 0);

            if (!$passed) {
                // 对战局：篡改 claim → 重放比对失败 → 标 is_cheat（05 §A8.1 第 5 条）
                $errCode = (string) ($result->getErrorCode() ?? '');
                if ($pvpBattleId !== ''
                    && in_array($errCode, [SubmissionValidator::ERR_RESULT_MISMATCH, SubmissionValidator::ERR_BOARD_MISMATCH], true)
                ) {
                    $this->pvp->markCheat($userId, $pvpBattleId, $errCode, 1);
                }

                // 校验失败：标记 done + 记录失败原因，不发奖。
                // 错误码细分到 API.md §10.2（11001 非法操作 / 11002 分数离谱 / 11003 重放不符 /
                // 11004 棋盘分歧 / 11005 checkpoint 非法），客户端据此决定"提示"还是"静默重提"；
                // 未识别的 reason（如 duration_too_short）回落到 10008。
                $this->finalizeBattle($battle, Match3Battle::OUTCOME_QUIT, $score, [], $errCode);

                throw Match3Exception::from(
                    Match3ErrorCode::fromSubmitError($errCode),
                    $result->getErrorMessage() ?? '结算校验不通过'
                );
            }

            $outcome = (string) ($meta['outcome'] ?? 'win');

            // 3b. L3 重放上界命中 → 标记可疑（**不拦成绩**，交人工复核）
            //     upper_bound 模式下这是"上报分数超过自己重放得出的分"，
            //     因为口径可能未完全对齐，先观察不误杀（见 07 §6 决策记录）。
            if (!empty($meta['replay_suspicious'])) {
                ContextualLogger::warning('[match3] 上报分数超过 L3 重放上界（可疑）', [
                    'user_id'      => $userId,
                    'level_code'   => $battle->level_code,
                    'battle_id'    => $pvpBattleId !== '' ? $pvpBattleId : null,
                    'replay_mode'  => (string) ($meta['replay_mode'] ?? 'off'),
                    'replay_score' => $meta['replay_score'] ?? null,
                    'claim_score'  => $meta['claim_score'] ?? null,
                ]);

                if ($pvpBattleId !== '') {
                    $this->pvp->markCheat($userId, $pvpBattleId, 'score_over_replay', 1);
                }
            }

            // 3. 计算奖励
            $levelArr = Match3LevelDef::where('code', $battle->level_code)->find()->toArray();
            $rewards  = $this->buildRewards($levelArr, $stars, $outcome);

            // 4a. 写 stage 进度（wise-game-stage，class_exists 守卫）
            $this->markStagePassed($userId, $battle->level_code, $stars, $score);

            // 4b. 写插件聚合进度
            $this->progress->recordPass($userId, $battle->level_code, $stars, $score);

            // 4c. 发关卡奖励（coins）
            if ($outcome === 'win') {
                $this->bridge->grantRewards($userId, $rewards, RewardReason::LEVEL_PASS, [
                    'level_code' => $battle->level_code,
                    'stars'      => $stars,
                ]);
            }

            // 4d. 扣减局内道具（items_used），按关卡 props.costOnUse 换算实际扣费量（02 §5.2）
            $itemsUsed = (array) ($submission['items_used'] ?? []);
            $normalized = $this->props->validateAndNormalize($itemsUsed);
            $cost       = $this->props->costOf($normalized, $levelArr['props'] ?? null);
            if (!empty($cost)) {
                $this->bridge->consumeProps($userId, $cost, RewardReason::PROP_USE);
            }

            // 4e. 排行榜
            $this->bridge->submitScore($userId, $score, $stars, [
                'level_code' => $battle->level_code,
            ]);

            // 4f. 任务/成就事件 + 埋点
            $this->reportSettleEvents($userId, $battle->level_code, $outcome, $stars, $score);

            // 4g. 更新会话状态
            $this->finalizeBattle($battle, $outcome, $score, $rewards, null, $submission);

            // 4h. 解锁推进
            $unlockNext = $this->resolveUnlockNext($userId, $battle->level_code, $stars);

            // 4i. 对战：记录成绩；双方都完成时自动判定 + 赛季积分 + 榜单（P5-B02/B04/B07）
            $battleState = null;
            if ($pvpBattleId !== '') {
                $claim = (array) ($submission['claim'] ?? []);
                try {
                    $battleState = $this->pvp->recordResult($userId, $pvpBattleId, [
                        'score'    => $score,
                        'moves'    => (int) ($claim['movesUsed'] ?? 0),
                        'stars'    => $stars,
                        'maxCombo' => (int) ($claim['maxCombo'] ?? 0),
                        'replayed' => (bool) ($meta['replayed'] ?? false),
                        'subHash'  => hash('sha256', json_encode($submission)),
                    ]);
                } catch (SocialException $e) {
                    // 对战记录失败不应回滚已完成的关卡结算，只记账
                    ContextualLogger::warning('[match3] 对战成绩记录失败: ' . $e->getMessage(), [
                        'user_id' => $userId, 'battle_id' => $pvpBattleId,
                    ]);
                }
            }

            // 4j. 卡关求助兑现：好友打通了这一关 → 求助方直接过关（保留星级）
            if ($outcome === 'win') {
                try {
                    $this->friends->resolveRescue($userId, $battle->level_code, $stars, $score);
                } catch (\Throwable $e) {
                    ContextualLogger::warning('[match3] 求助兑现失败: ' . $e->getMessage(), [
                        'user_id' => $userId, 'level_code' => $battle->level_code,
                    ]);
                }
            }

            $payload = [
                'passed'           => $outcome === 'win',
                'stars'            => $stars,
                'score'            => $score,
                // L3 是否真的跑了重放（false = 弱校验，风控据此统计）
                'replayed'         => (bool) ($meta['replayed'] ?? false),
                'replay_mode'      => (string) ($meta['replay_mode'] ?? 'off'),
                'replay_suspect'   => (bool) ($meta['replay_suspicious'] ?? false),
                'replay_score'     => $meta['replay_score'] ?? null,
                'claim_score'      => $meta['claim_score'] ?? null,
                // S5-4：逐回合 checkpoint 不一致条数 + 分桶维度（客户端包版本 / 算法版本）
                // 这两项**只用于埋点与风控**，不参与通关判定；客户端可忽略。
                'checkpoint_mismatch' => (int) ($meta['checkpoint_mismatch'] ?? 0),
                'client_version'      => (string) ($meta['client_version'] ?? ''),
                'fill_version'        => (string) ($meta['fill_version'] ?? ''),
                'rewards'          => $rewards,
                'unlock_next'      => $unlockNext,
                'level_star_total' => $this->countStarTotal($userId),
                // 货币快照（补齐「结算后需额外调 home/index」缺口）
                'coins'            => $this->bridge->getBalance($userId, Match3Constants::ITEM_COINS),
                'diamond'          => $this->bridge->getBalance($userId, Match3Constants::ITEM_DIAMOND),
                'battle'           => $battleState,
            ];

            // 4k. 存结算快照（B7 幂等）：之后重复提交把它原样读出来返回，
            //     **不再**执行上面的发奖 / 进度 / 榜单。
            $battle->settle_result = $payload;
            $battle->save();

            return $payload;
        });
    }

    // ================================================================
    //  断线恢复（§7.3）
    // ================================================================

    /**
     * 查询最近 playing 对局
     */
    public function latest(int $userId): array
    {
        $battle = Match3Battle::where('user_id', $userId)
            ->where('status', Match3Battle::STATUS_PLAYING)
            ->order('id', 'desc')
            ->find();

        if (!$battle) {
            return ['battle' => null, 'recoverable' => false];
        }

        $expired = $this->isExpired($battle->started_at);
        if ($expired) {
            $battle->status = Match3Battle::STATUS_EXPIRED;
            $battle->save();
            return ['battle' => null, 'recoverable' => false];
        }

        $remaining = $this->remainingSeconds($battle->started_at);

        return [
            'battle' => [
                'play_token'        => $battle->battle_token,
                'level_code'        => $battle->level_code,
                'seed'              => (int) $battle->seed,
                'started_at'        => $battle->started_at,
                'remaining_timeout' => $remaining,
            ],
            'recoverable' => true,
            // 断线恢复同样要握手：客户端算法若已升级，续局成绩在 strict 下必被拒
            'fill_version' => Match3FillVersion::CURRENT,
        ];
    }

    // ================================================================
    //  内部辅助
    // ================================================================

    /**
     * 扣体力（class_exists 守卫，未接入时跳过）
     *
     * @return array {cost, balance, max}|null
     */
    protected function costEnergy(int $userId, string $levelCode): ?array
    {
        $class = \wise\game\energy\service\EnergyService::class;
        if (!class_exists($class)) {
            ContextualLogger::warning('[match3] EnergyService 未接入，跳过体力扣减', ['user_id' => $userId]);
            return null;
        }

        try {
            /** @var \wise\game\energy\service\EnergyService $energy */
            $energy = app()->make($class);
            $result = $energy->cost($userId, $this->gameId, $levelCode);
            return [
                'current' => (int) $result['balance'],
                'max'     => (int) $result['max'],
            ];
        } catch (\Throwable $e) {
            if ($e instanceof \wise\game\energy\exception\GameEnergyException) {
                throw Match3Exception::from(Match3ErrorCode::ENERGY_NOT_ENOUGH, $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * 写 stage 进度（wise-game-stage ProgressTracker，class_exists 守卫）
     */
    protected function markStagePassed(int $userId, string $levelCode, int $stars, int $score): void
    {
        $class = \wise\game\stage\service\ProgressTracker::class;
        if (!class_exists($class)) {
            return;
        }

        try {
            /** @var \wise\game\stage\service\ProgressTracker $tracker */
            $tracker = app()->make($class);
            $tracker->markPassed($userId, $this->gameId, $levelCode, [
                'stars' => $stars,
                'score' => $score,
            ]);
        } catch (\Throwable $e) {
            ContextualLogger::warning('[match3] stage 进度写入失败: ' . $e->getMessage(), [
                'user_id' => $userId, 'level_code' => $levelCode,
            ]);
        }
    }

    /**
     * 构建关卡奖励（§3.2 reward_conf.coins）
     *
     * @return array [{item_id, count}]
     */
    protected function buildRewards(array $levelArr, int $stars, string $outcome): array
    {
        if ($outcome !== 'win' || $stars <= 0) {
            return [];
        }

        $rewardConf = $levelArr['reward_conf'] ?? null;
        if (!is_array($rewardConf)) {
            return [];
        }

        $coins = (array) ($rewardConf['coins'] ?? []);
        // coins: [首通, 二星, 三星]，按星级取档（索引 = stars - 1）
        $count = (int) ($coins[$stars - 1] ?? ($coins[0] ?? 0));

        return $count > 0
            ? [['item_id' => Match3Constants::ITEM_COINS, 'count' => $count]]
            : [];
    }

    /**
     * 上报结算事件（任务 + 埋点）
     */
    protected function reportSettleEvents(int $userId, string $levelCode, string $outcome, int $stars, int $score): void
    {
        $events = [
            ['event' => 'level_finish', 'data' => ['level_code' => $levelCode, 'outcome' => $outcome, 'stars' => $stars, 'score' => $score]],
        ];

        if ($outcome === 'win') {
            $events[] = ['event' => Match3Constants::EVENT_LEVEL_PASS, 'data' => [
                'level_code' => $levelCode, 'stars' => $stars,
            ]];
            $events[] = ['event' => Match3Constants::EVENT_TOTAL_SCORE, 'data' => [
                'count' => $score, 'level_code' => $levelCode,
            ]];
        }

        if ($stars >= 3) {
            $events[] = ['event' => Match3Constants::EVENT_THREE_STAR, 'data' => [
                'level_code' => $levelCode,
            ]];
        }

        $this->bridge->reportEvents($userId, $events);
    }

    /**
     * 结算后解锁推进
     *
     * @return array|null {code, unlocked}
     */
    protected function resolveUnlockNext(int $userId, string $levelCode, int $stars): ?array
    {
        $next = Match3LevelDef::where('pre_unlock', $levelCode)
            ->order('sort_order', 'asc')
            ->find();

        if (!$next) {
            return null;
        }

        $unlocked = $this->progress->checkUnlocked($userId, $next->code);

        // ⚠️ `unlocked=false` 有两种**完全不同**的含义，客户端必须能区分：
        //   ① 星数没达标（下一关存在，只是前置关星级不够）→ 应提示"再拿 N 星"；
        //   ② 客户端若不区分，就会把 ① 显示成"已是最后一关"。
        //   2026-10-02 真机：第 7 关（M3-0203）通关后提示"已是最后一关"——
        //   其实第 8 关 M3-0204 存在，只是当时它 `unlock_stars = 2`，玩家本关只拿了 1 星。
        //   （前 7 关的后继只要 0~1 星，第 8 关是第一个要 2 星的，问题正好在这里暴露。）
        //   2026-10-03 起 `unlock_stars` 默认 1（通关即解锁），这类误报已消除；
        //   将来若某关要卡星，只改那一行数据即可，客户端三态文案不用动。
        return [
            'code'       => $next->code,
            'unlocked'   => $unlocked,
            'need_stars' => (int) ($next->unlock_stars ?? LevelProgressService::DEFAULT_UNLOCK_STARS),
        ];
    }

    /**
     * 统计玩家总星数
     */
    protected function countStarTotal(int $userId): int
    {
        $map   = $this->progress->loadProgressMap($userId);
        $total = 0;
        foreach ($map as $item) {
            $total += (int) ($item['best_stars'] ?? 0);
        }
        return $total;
    }

    /**
     * 更新对局会话为终态
     */
    protected function finalizeBattle(
        Match3Battle $battle,
        string $outcome,
        int $score,
        array $rewards,
        ?string $errorCode = null,
        ?array $submission = null
    ): void {
        $battle->status      = Match3Battle::STATUS_DONE;
        $battle->outcome     = $outcome;
        $battle->score       = $score;

        // 提交协议（02 §8）：步数与目标进度都从 claim 读取
        $claim = is_array($submission) ? (array) ($submission['claim'] ?? []) : [];
        $battle->moves_used  = (int) ($claim['movesUsed'] ?? 0);
        $battle->collect_result = json_encode((array) ($claim['goals'] ?? []), JSON_UNESCAPED_UNICODE);
        $battle->reward_given = !empty($rewards) ? json_encode($rewards, JSON_UNESCAPED_UNICODE) : null;
        $battle->finished_at = date('Y-m-d H:i:s');
        $battle->save();
    }

    /**
     * 会话是否过期
     */
    protected function isExpired(string $startedAt): bool
    {
        $ttl = (int) ($this->config['battle']['session_ttl'] ?? 1800);
        return time() - strtotime($startedAt) > $ttl;
    }

    /**
     * 开局到现在的毫秒数（服务端时钟）
     *
     * 【用途】时长校验的可信下界：客户端上报的 `duration_ms` 可能偏小（续局、切后台、连锁速通），
     * 服务端自己算的这段时间只会 ≥ 真实游玩时间，用它兜底**只放宽、不收紧**。
     * `started_at` 是秒精度（见 battle 表迁移），所以这里用 `microtime` 补足小数部分。
     *
     * @param string $startedAt `Y-m-d H:i:s`
     * @return int 解析失败回 0（等于"没有这个信息"）
     */
    public function elapsedMs(string $startedAt): int
    {
        $ts = strtotime($startedAt);
        if ($ts === false) {
            return 0;
        }

        return max(0, (int) round((microtime(true) - (float) $ts) * 1000));
    }

    /**
     * 剩余可续秒数
     */
    protected function remainingSeconds(string $startedAt): int
    {
        $ttl = (int) ($this->config['battle']['session_ttl'] ?? 1800);
        return max(0, $ttl - (time() - strtotime($startedAt)));
    }

    /**
     * 客户端 IP
     */
    protected function clientIp(): ?string
    {
        try {
            $ip = request()->ip() ?: null;
            return $ip !== null ? substr($ip, 0, 45) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 组装关卡下发载荷（§7.1 响应 level）
     *
     * 直接下发完整 Level Schema v1（01 §4），前端据此选择解析器；
     * 插件自有的关卡奖励（reward_conf）单独挂在 reward 上，不污染协议。
     */
    protected function levelPayload(array $levelArr): array
    {
        return array_merge(
            LevelSchemaFactory::fromRow($levelArr),
            ['reward' => $levelArr['reward_conf'] ?? null]
        );
    }
}
