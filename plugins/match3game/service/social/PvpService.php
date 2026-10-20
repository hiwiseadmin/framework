<?php

declare(strict_types=1);

namespace plugin\match3game\service\social;

use plugin\match3game\model\Match3LevelDef;
use plugin\match3game\model\Match3PvpBattle;
use plugin\match3game\model\Match3PvpPlayer;
use plugin\match3game\service\rogue\PatchEngine;
use plugin\match3game\service\rogue\RelicService;
use plugin\match3game\service\level\LevelSchemaFactory;
use wise\support\context\ContextualLogger;

/**
 * 异步对战服务（05 §A3.1 / §A3.2 / §A7.1）
 *
 * 【核心不变式】`match3_pvp_battle.seed` 创建时锁定 → 双方 start 都读它
 *   ⇒ 同一 battleId 的双方棋盘 100% 一致（05 §A8.1 第 1 条，最关键验收项）。
 *
 * 【生命周期】
 *   create   创建（friend 指定对手 / ladder 公开 / group 群擂台）
 *   start    任一参与方调 /api/match3game/battle/start（带 battle_id）
 *            → prepareStart 校验 + 认领对手位 + 绑定一次性 playToken
 *   submit   提交结算 → recordResult 记录成绩；**双方都提交后自动判定**
 *   expire   24h 未提交 → 定时任务判负（-20 分）
 *
 * 【防作弊】
 *   · playToken 一次性：提交后置 NULL，二次提交必失败（05 §A8.1 第 4 条）
 *   · sub_hash 防重复提交
 *   · 校验不通过（board_mismatch / result_mismatch）→ is_cheat=1 进复核页
 */
class PvpService
{
    /** 平局/未决的 winner_id */
    public const NO_WINNER = 0;

    protected SeasonService $season;

    protected RankService $rank;

    protected GroupBattleService $group;

    public function __construct(
        ?SeasonService $season = null,
        ?RankService $rank = null,
        ?GroupBattleService $group = null
    ) {
        $this->season = $season ?? new SeasonService();
        $this->rank   = $rank ?? new RankService();
        $this->group  = $group ?? new GroupBattleService($this->rank);
    }

    /* ======================================================================
     *  创建 / 接受
     * ====================================================================== */

    /**
     * 创建对战
     *
     * @param  array<int,string> $relics 肉鸽对战遗物（可空；双方共用同一批补丁）
     * @return array<string,mixed>
     * @throws SocialException
     */
    public function create(
        int $userId,
        string $mode,
        string $stageCode,
        int $opponentId = 0,
        ?string $groupId = null,
        array $relics = []
    ): array {
        $mode = $mode !== '' ? $mode : Match3PvpBattle::MODE_FRIEND;
        if (!in_array($mode, [
            Match3PvpBattle::MODE_FRIEND,
            Match3PvpBattle::MODE_LADDER,
            Match3PvpBattle::MODE_GROUP,
            Match3PvpBattle::MODE_DAILY,
        ], true)) {
            throw new SocialException('不支持的对战模式: ' . $mode, SocialException::CODE_BAD_REQUEST);
        }

        if ($stageCode === '' || !Match3LevelDef::where('code', $stageCode)->find()) {
            throw new SocialException('关卡不存在: ' . $stageCode, SocialException::CODE_BAD_REQUEST);
        }

        if ($mode === Match3PvpBattle::MODE_FRIEND) {
            if ($opponentId <= 0) {
                throw new SocialException('好友对战必须指定对手', SocialException::CODE_BAD_REQUEST);
            }
            if ($opponentId === $userId) {
                throw new SocialException('不能挑战自己', SocialException::CODE_BAD_REQUEST);
            }
        }

        if ($mode === Match3PvpBattle::MODE_GROUP) {
            $groupId = $this->assertEncryptedGroupId((string) $groupId);
        }

        if ($mode === Match3PvpBattle::MODE_LADDER) {
            $open = Match3PvpBattle::where('challenger_id', $userId)
                ->where('mode', Match3PvpBattle::MODE_LADDER)
                ->where('status', Match3PvpBattle::STATUS_PENDING)
                ->count();
            if ($open >= SocialRules::LADDER_MAX_OPEN) {
                throw new SocialException(
                    '公开挑战最多同时存在 ' . SocialRules::LADDER_MAX_OPEN . ' 个',
                    SocialException::CODE_LIMIT
                );
            }
        }

        // 遗物校验：必须在遗物表里存在（真实应用在 BattleService::start/validate）
        $relicIds = [];
        if ($relics !== []) {
            $configs = (new RelicService())->byIds(array_values(array_filter(array_map('strval', $relics))));
            if (count($configs) !== count($relics)) {
                throw new SocialException('存在无效的遗物', SocialException::CODE_BAD_REQUEST);
            }
            $relicIds = array_column($configs, 'relic_id');
        }

        $battleId = 'bt_' . bin2hex(random_bytes(4));
        $seed     = random_int(1, 0x7FFFFFFF);
        $periodId = $mode === Match3PvpBattle::MODE_GROUP ? SocialRules::periodId() : null;

        Match3PvpBattle::create([
            'battle_id'     => $battleId,
            'mode'          => $mode,
            'stage_code'    => $stageCode,
            'seed'          => $seed,
            'relics'        => $relicIds,
            'group_id'      => $groupId,
            'period_id'     => $periodId,
            'season_id'     => $this->season->currentSeasonId(),
            'challenger_id' => $userId,
            'opponent_id'   => $mode === Match3PvpBattle::MODE_LADDER ? 0 : $opponentId,
            'status'        => Match3PvpBattle::STATUS_PENDING,
            'winner_id'     => self::NO_WINNER,
            'share_title'   => sprintf('我在第 %s 关等你，敢来吗？', $stageCode),
            'create_time'    => date('Y-m-d H:i:s'),
            'expires_at'    => date('Y-m-d H:i:s', time() + Match3PvpBattle::TTL_HOURS * 3600),
        ]);

        return [
            'battleId'  => $battleId,
            'stageCode' => $stageCode,
            'seed'      => $seed,
            'mode'      => $mode,
            'status'    => Match3PvpBattle::STATUS_PENDING,
            'expiresAt' => date('Y-m-d H:i:s', time() + Match3PvpBattle::TTL_HOURS * 3600),
            'shareCard' => $this->shareCard([
                'battle_id'  => $battleId,
                'share_title' => sprintf('我在第 %s 关等你，敢来吗？', $stageCode),
            ]),
        ];
    }

    /**
     * 接受挑战
     *
     * @return array<string,mixed>
     * @throws SocialException
     */
    public function accept(int $userId, string $battleId): array
    {
        $battle = $this->loadByBattleId($battleId);

        if ((int) $battle['status'] !== Match3PvpBattle::STATUS_PENDING) {
            throw new SocialException('该对战已不是待接受状态', SocialException::CODE_BATTLE_CLOSED);
        }
        if ($this->isExpired($battle)) {
            $this->expireBattle($battle, false);
            throw new SocialException('挑战已过期', SocialException::CODE_BATTLE_CLOSED);
        }
        if ((int) $battle['challenger_id'] === $userId) {
            throw new SocialException('不能接受自己的挑战', SocialException::CODE_BAD_REQUEST);
        }
        if ((int) $battle['opponent_id'] > 0 && (int) $battle['opponent_id'] !== $userId) {
            throw new SocialException('该挑战已有指定对手', SocialException::CODE_BATTLE_CLOSED);
        }

        Match3PvpBattle::where('battle_id', $battleId)->update([
            'opponent_id' => $userId,
            'status'      => Match3PvpBattle::STATUS_PLAYING,
        ]);

        return [
            'battleId'  => $battleId,
            'stageCode' => (string) $battle['stage_code'],
            'seed'      => (int) $battle['seed'],
            'status'    => Match3PvpBattle::STATUS_PLAYING,
        ];
    }

    /**
     * 拒绝挑战
     *
     * @throws SocialException
     */
    public function refuse(int $userId, string $battleId): array
    {
        $battle = $this->loadByBattleId($battleId);

        if ((int) $battle['status'] !== Match3PvpBattle::STATUS_PENDING) {
            throw new SocialException('该对战已不是待接受状态', SocialException::CODE_BATTLE_CLOSED);
        }
        if ((int) $battle['opponent_id'] > 0 && (int) $battle['opponent_id'] !== $userId) {
            throw new SocialException('无权拒绝该挑战', SocialException::CODE_BAD_REQUEST);
        }

        Match3PvpBattle::where('battle_id', $battleId)->update([
            'status'      => Match3PvpBattle::STATUS_REFUSED,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        return ['battleId' => $battleId, 'status' => Match3PvpBattle::STATUS_REFUSED];
    }

    /* ======================================================================
     *  列表 / 详情
     * ====================================================================== */

    /**
     * 我的对战列表
     *
     * @param string $box all / incoming（别人挑战我）/ outgoing（我发起的）
     * @return array{list:array<int,array<string,mixed>>, total:int, page:int, size:int}
     */
    public function listBattles(int $userId, string $box = 'all', int $page = 1, int $size = 20): array
    {
        $page = max(1, $page);
        $size = max(1, min(50, $size));

        $query = Match3PvpBattle::where(function ($q) use ($userId, $box) {
            if ($box === 'incoming') {
                $q->where('opponent_id', $userId)->whereOr(function ($q2) use ($userId) {
                    // 公开挑战：还没人接的，人人都算"可接"
                    $q2->where('mode', Match3PvpBattle::MODE_LADDER)->where('opponent_id', 0);
                });
                return;
            }
            if ($box === 'outgoing') {
                $q->where('challenger_id', $userId);
                return;
            }
            $q->where('challenger_id', $userId)->whereOr('opponent_id', $userId);
        });

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $size)->select()->toArray();

        $list = [];
        foreach ($rows as $row) {
            $list[] = $this->presentBattle($userId, $row);
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size];
    }

    /**
     * 对战详情（盲打：我未完成时看不到对手分数）
     *
     * @return array<string,mixed>
     * @throws SocialException
     */
    public function detail(int $userId, string $battleId): array
    {
        $battle = $this->loadByBattleId($battleId);

        if ((int) $battle['challenger_id'] !== $userId
            && (int) $battle['opponent_id'] !== $userId
            && !((int) $battle['opponent_id'] === 0 && (int) $battle['status'] === Match3PvpBattle::STATUS_PENDING)
        ) {
            throw new SocialException('无权查看该对战', SocialException::CODE_FORBIDDEN);
        }

        $out = $this->presentBattle($userId, $battle);
        $out['shareCard'] = $this->shareCard($battle);

        return $out;
    }

    /* ======================================================================
     *  开局 / 提交（由 BattleService 调用 —— P5-B03 seed 锁定）
     * ====================================================================== */

    /**
     * 开局前校验并锁定 seed
     *
     * @return array{seed:int, relics:array<int,string>, mode:string, battleId:string, role:string}
     * @throws SocialException
     */
    public function prepareStart(int $userId, string $battleId, string $stageCode): array
    {
        $battle = $this->loadByBattleId($battleId);

        if ($this->isExpired($battle)) {
            $this->expireBattle($battle, false);
            throw new SocialException('挑战已过期', SocialException::CODE_BATTLE_CLOSED);
        }

        if ((string) $battle['stage_code'] !== $stageCode) {
            throw new SocialException('关卡与对战不一致', SocialException::CODE_BAD_REQUEST);
        }

        $status      = (int) $battle['status'];
        $challenger  = (int) $battle['challenger_id'];
        $opponent    = (int) $battle['opponent_id'];
        $isChallenger = $challenger === $userId;

        if (in_array($status, [Match3PvpBattle::STATUS_FINISHED, Match3PvpBattle::STATUS_REFUSED], true)) {
            throw new SocialException('该对战已结束', SocialException::CODE_BATTLE_CLOSED);
        }
        if ($status === Match3PvpBattle::STATUS_EXPIRED) {
            throw new SocialException('该对战已过期', SocialException::CODE_BATTLE_CLOSED);
        }

        // 参与方确认（ladder 的 PENDING 允许任何陌生人认领对手位）
        if (!$isChallenger && $opponent !== $userId) {
            if ($status === Match3PvpBattle::STATUS_PENDING && $opponent === 0) {
                $opponent = $userId;
                Match3PvpBattle::where('battle_id', $battleId)->update([
                    'opponent_id' => $userId,
                    'status'      => Match3PvpBattle::STATUS_PLAYING,
                ]);
                $status = Match3PvpBattle::STATUS_PLAYING;
            } else {
                throw new SocialException('你不是该对战的参与方', SocialException::CODE_FORBIDDEN);
            }
        }

        // 发起者先手开局 → 状态推进为进行中
        if ($status === Match3PvpBattle::STATUS_PENDING) {
            Match3PvpBattle::where('battle_id', $battleId)->update(['status' => Match3PvpBattle::STATUS_PLAYING]);
        }

        // 已提交过就不能再开局（一人一次）
        $row = $this->playerRow($battleId, $userId);
        if ($row && (int) $row->status === Match3PvpPlayer::STATUS_DONE) {
            throw new SocialException('你已完成本场对战', SocialException::CODE_ALREADY_DONE);
        }

        return [
            'seed'     => (int) $battle['seed'],
            'relics'   => array_values((array) ($battle['relics'] ?? [])),
            'mode'     => (string) $battle['mode'],
            'battleId' => $battleId,
            'role'     => $isChallenger ? 'challenger' : 'opponent',
        ];
    }

    /**
     * 绑定一次性 playToken（与 match3_battle.battle_token 同一个值）
     */
    public function bindPlayToken(int $userId, string $battleId, string $token): void
    {
        $row = $this->playerRow($battleId, $userId);

        if ($row === null) {
            Match3PvpPlayer::create([
                'battle_id'  => $battleId,
                'user_id'    => $userId,
                'status'     => Match3PvpPlayer::STATUS_PLAYING,
                'play_token' => $token,
                'started_at' => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        Match3PvpPlayer::where('id', (int) $row->id)->update([
            'play_token' => $token,
            'status'     => Match3PvpPlayer::STATUS_PLAYING,
            'started_at' => $row->started_at ?: date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 记录成绩（BattleService 校验通过后调用）；双方都完成时自动判定
     *
     * @param  array<string,mixed> $stats {score, moves, stars, maxCombo, replayed, subHash}
     * @return array<string,mixed> {status, myScore, opponentScore, result, pointsDelta}
     * @throws SocialException
     */
    public function recordResult(int $userId, string $battleId, array $stats): array
    {
        $row = $this->playerRow($battleId, $userId);
        if ($row === null) {
            throw new SocialException('未找到对战参与记录', SocialException::CODE_NOT_FOUND);
        }
        if ((int) $row->status === Match3PvpPlayer::STATUS_DONE) {
            throw new SocialException('本场已提交过', SocialException::CODE_ALREADY_DONE);
        }

        Match3PvpPlayer::where('id', (int) $row->id)->update([
            'score'       => (int) ($stats['score'] ?? 0),
            'moves'       => (int) ($stats['moves'] ?? 0),
            'stars'       => (int) ($stats['stars'] ?? 0),
            'max_combo'   => (int) ($stats['maxCombo'] ?? 0),
            'replayed'    => !empty($stats['replayed']) ? 1 : 0,
            'sub_hash'    => (string) ($stats['subHash'] ?? ''),
            'status'      => Match3PvpPlayer::STATUS_DONE,
            'finished_at' => date('Y-m-d H:i:s'),
            // 一次性 playToken：置 NULL 后二次提交必被拒（05 §A8.1 第 4 条）
            'play_token'  => null,
        ]);

        return $this->trySettle($battleId, $userId);
    }

    /**
     * 标记作弊（校验层发现 board_mismatch / result_mismatch 时调用）
     *
     * @param int $level 1 可疑 / 2 确认
     */
    public function markCheat(int $userId, string $battleId, string $reason, int $level = Match3PvpPlayer::CHEAT_SUSPECT): void
    {
        $row = $this->playerRow($battleId, $userId);
        if ($row === null) {
            return;
        }

        Match3PvpPlayer::where('id', (int) $row->id)->update([
            'is_cheat'     => $level,
            'cheat_reason' => substr($reason, 0, 64),
        ]);

        ContextualLogger::warning('[match3] PvP 疑似作弊', [
            'battle_id' => $battleId, 'user_id' => $userId, 'reason' => $reason,
        ]);
    }

    /* ======================================================================
     *  定时任务：24h 超时
     * ====================================================================== */

    /**
     * 处理过期对战（05 §A8.1 第 3 条）
     *
     * @return array{pendingExpired:int, playingExpired:int, penalized:int}
     */
    public function expireOverdue(int $limit = 200): array
    {
        $rows = Match3PvpBattle::whereIn('status', [
            Match3PvpBattle::STATUS_PENDING,
            Match3PvpBattle::STATUS_PLAYING,
        ])
            ->where('expires_at', '<', date('Y-m-d H:i:s'))
            ->limit($limit)
            ->select()
            ->toArray();

        $stat = ['pendingExpired' => 0, 'playingExpired' => 0, 'penalized' => 0];

        foreach ($rows as $battle) {
            if ((int) $battle['status'] === Match3PvpBattle::STATUS_PENDING) {
                $this->expireBattle($battle, false);
                $stat['pendingExpired']++;
                continue;
            }

            $stat['penalized'] += $this->expireBattle($battle, true);
            $stat['playingExpired']++;
        }

        return $stat;
    }

    /* ======================================================================
     *  内部
     * ====================================================================== */

    /**
     * 过期一个对战
     *
     * @param bool $penalize 是否对未提交方扣分（PENDING 无人开局时不扣）
     * @return int 被扣分人数
     */
    protected function expireBattle(array $battle, bool $penalize): int
    {
        $battleId  = (string) $battle['battle_id'];
        $penalized = 0;

        $rows = Match3PvpPlayer::where('battle_id', $battleId)->select();

        foreach ($rows as $row) {
            if ((int) $row->status === Match3PvpPlayer::STATUS_DONE) {
                continue;
            }

            Match3PvpPlayer::where('id', (int) $row->id)->update([
                'status'      => Match3PvpPlayer::STATUS_TIMEOUT,
                'finished_at' => date('Y-m-d H:i:s'),
                'play_token'  => null,
            ]);

            if (!$penalize) {
                continue;
            }

            $delta = SocialRules::pointsDelta('timeout', 0, 0);
            Match3PvpPlayer::where('id', (int) $row->id)->update(['points_delta' => $delta]);
            $this->season->addPoints((int) $row->user_id, $delta, 'timeout', (int) $row->score);
            $penalized++;
        }

        // 若恰有 1 人完成，则他获胜
        $done = 0;
        $lastDone = 0;
        foreach ($rows as $row) {
            if ((int) $row->status === Match3PvpPlayer::STATUS_DONE) {
                $done++;
                $lastDone = (int) $row->user_id;
            }
        }
        $winnerId = $done === 1 ? $lastDone : self::NO_WINNER;

        Match3PvpBattle::where('battle_id', $battleId)->update([
            'status'      => Match3PvpBattle::STATUS_EXPIRED,
            'winner_id'   => $winnerId,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        return $penalized;
    }

    /**
     * 双方都完成时结算
     *
     * @return array<string,mixed>
     */
    protected function trySettle(string $battleId, int $viewerId): array
    {
        $battle = $this->loadByBattleId($battleId);
        $rows   = Match3PvpPlayer::where('battle_id', $battleId)
            ->where('status', Match3PvpPlayer::STATUS_DONE)
            ->select()
            ->toArray();

        $my = null;
        foreach ($rows as $r) {
            if ((int) $r['user_id'] === $viewerId) {
                $my = $r;
            }
        }

        if (count($rows) < 2) {
            // 还没打完：对手分数不可见（盲打）
            return [
                'status'        => 'waiting',
                'myScore'       => (int) ($my['score'] ?? 0),
                'opponentScore' => null,
                'result'        => null,
                'pointsDelta'   => 0,
            ];
        }

        // 以 challenger 为 a 侧，保证两端判定一致（判定必须与"谁在看"无关）
        $challengerId = (int) $battle['challenger_id'];
        $sideA = null;
        $sideB = null;
        foreach ($rows as $r) {
            if ((int) $r['user_id'] === $challengerId) {
                $sideA = $r;
            } else {
                $sideB = $r;
            }
        }
        if ($sideA === null || $sideB === null) {
            return [
                'status'        => 'waiting',
                'myScore'       => (int) ($my['score'] ?? 0),
                'opponentScore' => null,
                'result'        => null,
                'pointsDelta'   => 0,
            ];
        }

        $verdict = SocialRules::judge(
            ['score' => $sideA['score'], 'moves' => $sideA['moves'], 'finishedAt' => strtotime((string) ($sideA['finished_at'] ?? '')) ?: 0],
            ['score' => $sideB['score'], 'moves' => $sideB['moves'], 'finishedAt' => strtotime((string) ($sideB['finished_at'] ?? '')) ?: 0]
        );

        $winnerId = $verdict['side'] === 'a'
            ? (int) $sideA['user_id']
            : ($verdict['side'] === 'b' ? (int) $sideB['user_id'] : self::NO_WINNER);

        $out = [];
        foreach ($rows as $r) {
            $uid    = (int) $r['user_id'];
            $isChal = $uid === $challengerId;
            $mine   = (int) $r['score'];
            $theirs = $isChal ? (int) $sideB['score'] : (int) $sideA['score'];

            $result = $verdict['result'] === 'draw'
                ? 'draw'
                : (($verdict['side'] === ($isChal ? 'a' : 'b')) ? 'win' : 'lose');

            $firstWin = $result === 'win' && $this->season->markFirstWinOfDay($uid);
            $delta    = SocialRules::pointsDelta($result, $mine, $theirs, $firstWin);

            Match3PvpPlayer::where('battle_id', $battleId)->where('user_id', $uid)->update(['points_delta' => $delta]);
            $this->season->addPoints($uid, $delta, $result, $mine);

            // 榜单：好友榜 / 每日榜 / 群榜
            $this->rank->reportGameScore($uid, $mine, [
                'mode'      => (string) $battle['mode'],
                'stageCode' => (string) $battle['stage_code'],
                'day'       => date('Y-m-d'),
            ]);

            if ($uid === $viewerId) {
                $out = [
                    'status'        => 'finished',
                    'myScore'       => $mine,
                    'opponentScore' => $theirs,
                    'result'        => ['win' => $result === 'win', 'draw' => $result === 'draw', 'reason' => $verdict['reason']],
                    'pointsDelta'   => $delta,
                ];
            }
        }

        Match3PvpBattle::where('battle_id', $battleId)->update([
            'status'      => Match3PvpBattle::STATUS_FINISHED,
            'winner_id'   => $winnerId,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        // 群擂台：本轮成绩入库（Top5 累加由 GroupBattleService 重算）
        if ((string) $battle['mode'] === Match3PvpBattle::MODE_GROUP && !empty($battle['group_id'])) {
            foreach ($rows as $r) {
                $this->group->recordScore(
                    (string) $battle['group_id'],
                    (string) ($battle['period_id'] ?: SocialRules::periodId()),
                    (int) $r['user_id'],
                    (int) $r['score']
                );
            }
        }

        return $out !== [] ? $out : [
            'status'        => 'finished',
            'myScore'       => 0,
            'opponentScore' => null,
            'result'        => null,
            'pointsDelta'   => 0,
        ];
    }

    /**
     * 对战展示结构（05 §A8：盲打 —— 我未完成时隐藏对手分数）
     *
     * @param array<string,mixed> $battle
     * @return array<string,mixed>
     */
    protected function presentBattle(int $userId, array $battle): array
    {
        $battleId = (string) $battle['battle_id'];
        $mine     = $this->playerRow($battleId, $userId);
        $status   = (int) $battle['status'];

        $myDone = $mine !== null && (int) $mine->status === Match3PvpPlayer::STATUS_DONE;
        $reveal = $myDone || in_array($status, [Match3PvpBattle::STATUS_FINISHED, Match3PvpBattle::STATUS_EXPIRED], true);

        $opponentScore = null;
        if ($reveal) {
            $opp = $this->otherPlayer($battleId, (int) $battle['challenger_id'], $userId);
            $opponentScore = $opp !== null ? (int) $opp->score : null;
        }

        return [
            'battleId'      => $battleId,
            'mode'          => (string) $battle['mode'],
            'stageCode'     => (string) $battle['stage_code'],
            'status'        => $status,
            'statusText'    => Match3PvpBattle::statusText($status),
            'challengerId'  => (int) $battle['challenger_id'],
            'opponentId'    => (int) $battle['opponent_id'],
            'winnerId'      => (int) $battle['winner_id'],
            'seed'          => (int) $battle['seed'],
            'myScore'       => $mine !== null ? (int) $mine->score : null,
            'myStatus'      => $mine !== null ? (int) $mine->status : Match3PvpPlayer::STATUS_IDLE,
            'myStatusText'  => $mine !== null ? Match3PvpPlayer::statusText((int) $mine->status) : '未开始',
            'myPointsDelta' => $mine !== null ? (int) $mine->points_delta : 0,
            'opponentScore' => $opponentScore,
            'isCheat'       => $mine !== null ? (int) $mine->is_cheat : 0,
            'expiresAt'     => $battle['expires_at'] ?? null,
            'createdAt'     => $battle['create_time'] ?? null,
            'finishedAt'    => $battle['finished_at'] ?? null,
        ];
    }

    /**
     * 分享卡片数据（**只给数据，图片由前端离屏 Canvas 渲染**，05 §A7.4 / P5-F03）
     *
     * @param array<string,mixed> $battle
     * @return array<string,mixed>
     */
    protected function shareCard(array $battle): array
    {
        $battleId = (string) $battle['battle_id'];
        $row      = Match3PvpPlayer::where('battle_id', $battleId)
            ->order('score', 'desc')
            ->find();

        $best = $row ? (int) $row->score : 0;

        return [
            'title'    => $best > 0
                ? sprintf('我 %d 分，你敢来吗？', $best)
                : (string) ($battle['share_title'] ?? '来一局？'),
            'imageUrl' => '/statics/plugins/match3game/card_bg_1.png',
            'query'    => 'battleId=' . $battleId,
            'path'     => '/pages/battle/detail?battleId=' . $battleId,
        ];
    }

    /**
     * 群标识必须为**加密 ID**（05 §A8.1 第 8 条：DB 不得存明文群名）
     *
     * 校验策略（启发式 + 可配置）：
     *   · 不得含中日韩文字（明文群名几乎必然含中文）
     *   · 长度 ≥ 8
     *   · 只允许 [A-Za-z0-9_\-] （base64/urlsafe 或 hex）
     *
     * @throws SocialException
     */
    protected function assertEncryptedGroupId(string $groupId): string
    {
        $groupId  = trim($groupId);
        $required = (bool) (config('match3game.match3.social.require_encrypted_group_id') ?? true);

        if ($groupId === '') {
            throw new SocialException('群标识不能为空', SocialException::CODE_BAD_REQUEST);
        }

        if ($required && !SocialRules::isEncryptedGroupId($groupId)) {
            throw new SocialException('群标识必须是加密 ID，不能是明文群名（合规要求）', SocialException::CODE_GROUP_ID_INVALID);
        }

        return substr($groupId, 0, 64);
    }

    protected function playerRow(string $battleId, int $userId): ?Match3PvpPlayer
    {
        return Match3PvpPlayer::where('battle_id', $battleId)->where('user_id', $userId)->find();
    }

    protected function otherPlayer(string $battleId, int $challengerId, int $userId): ?Match3PvpPlayer
    {
        return Match3PvpPlayer::where('battle_id', $battleId)
            ->where('user_id', '<>', $userId)
            ->find();
    }

    /**
     * @return array<string,mixed>
     * @throws SocialException
     */
    public function loadByBattleId(string $battleId): array
    {
        if ($battleId === '') {
            throw new SocialException('缺少 battleId', SocialException::CODE_BAD_REQUEST);
        }

        $row = Match3PvpBattle::where('battle_id', $battleId)->find();
        if (!$row) {
            throw new SocialException('对战不存在: ' . $battleId, SocialException::CODE_NOT_FOUND);
        }

        return $row->toArray();
    }

    protected function isExpired(array $battle): bool
    {
        $expires = (string) ($battle['expires_at'] ?? '');
        return $expires !== '' && strtotime($expires) < time();
    }

    /**
     * 对战场次对应的"可校验关卡"（带遗物时返回打过补丁的关卡行）
     *
     * start 与 submit 必须用**同一份** schema，否则重放必然 result_mismatch。
     *
     * @return array<string,mixed>|null null = 无需覆盖（用原始关卡）
     */
    public function levelForValidation(string $battleId): ?array
    {
        $battle = Match3PvpBattle::where('battle_id', $battleId)->find();
        if (!$battle) {
            return null;
        }

        $relicIds = array_values((array) ($battle->relics ?? []));
        if ($relicIds === []) {
            return null;
        }

        $level = Match3LevelDef::where('code', $battle->stage_code)->find();
        if (!$level) {
            return null;
        }

        $schema  = LevelSchemaFactory::fromRow($level->toArray());
        $relics  = (new RelicService())->byIds($relicIds);
        $result  = PatchEngine::applyRelics($schema, $relics);
        $patched = is_array($result->doc) ? $result->doc : $schema;

        // 回填到行结构（validator 读的是行数组 → 内部再 fromRow 一次）
        return LevelSchemaFactory::toRow($patched, $level->toArray());
    }
}
