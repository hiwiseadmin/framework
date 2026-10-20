<?php

declare(strict_types=1);

namespace plugin\match3game\service\social;

use plugin\match3game\contract\Match3Constants;
use plugin\match3game\contract\RewardReason;
use plugin\match3game\model\Match3Season;
use plugin\match3game\model\Match3SeasonPlayer;
use plugin\match3game\service\RewardBridgeService;
use think\facade\Db;
use wise\support\context\ContextualLogger;

/**
 * 赛季与段位服务（05 §A3.4 / §A7.1，P5-B09）
 *
 * 周期 2 周；积分规则见 `SocialRules`；负分不降段（不低于当前段位下限）。
 *
 * 【设计要点】
 *   · 赛季"懒生成"：当前周期没有赛季行时自动创建（`ensureSeason()`），
 *     不依赖定时任务也能跑通；`match3:season` 命令用于**提前开赛/结算**。
 *   · 段位奖励领取走 `RewardBridgeService`（→ inventory_log 可审计，P6-B04）。
 *     纯外观类奖励（头像框/称号/皮肤）本期只记在响应里并打日志，
 *     等 P6 的 inventory 外观体系落地再入库（见 §5.4 遗留）。
 */
class SeasonService
{
    protected RankService $rank;

    protected RewardBridgeService $bridge;

    public function __construct(?RankService $rank = null, ?RewardBridgeService $bridge = null)
    {
        $this->rank   = $rank ?? new RankService();
        $this->bridge = $bridge ?? new RewardBridgeService();
    }

    /* ======================================================================
     *  默认段位奖励（05 §A7.1，可被 match3_season.reward_conf 覆盖）
     * ====================================================================== */

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function defaultRewards(): array
    {
        return [
            'bronze'   => ['coin' => 500,   'props' => [],                'cosmetics' => []],
            'silver'   => ['coin' => 1000,  'props' => ['prop.hammer' => 2], 'cosmetics' => []],
            'gold'     => ['coin' => 2000,  'props' => ['prop.bomb' => 2],   'cosmetics' => ['avatar_frame_gold']],
            'platinum' => ['coin' => 3500,  'props' => ['prop.add_moves' => 1], 'cosmetics' => ['title_platinum']],
            'diamond'  => ['coin' => 6000,  'props' => [],                'cosmetics' => ['skin_diamond']],
            'king'     => ['coin' => 10000, 'props' => [],                'cosmetics' => ['avatar_frame_king', 'broadcast']],
        ];
    }

    /* ======================================================================
     *  赛季生命周期
     * ====================================================================== */

    /**
     * 当前进行中的赛季 ID（不存在则懒创建）
     */
    public function currentSeasonId(?int $timestamp = null): string
    {
        $row = $this->ensureSeason($timestamp);

        return (string) $row['season_id'];
    }

    /**
     * 取（或创建）当前周期赛季
     *
     * @return array<string,mixed>
     */
    public function ensureSeason(?int $timestamp = null): array
    {
        $ts       = $timestamp ?? time();
        $seasonId = SocialRules::seasonId($ts);

        $row = Match3Season::where('season_id', $seasonId)->find();
        if ($row) {
            return $row->toArray();
        }

        // 同一周期并发创建 → 唯一键冲突后回读
        $created = Match3Season::create([
            'season_id'   => $seasonId,
            'name'        => '第 ' . $seasonId . ' 赛季',
            'start_at'    => date('Y-m-d H:i:s', $ts),
            'end_at'      => date('Y-m-d H:i:s', $ts + SocialRules::SEASON_DAYS * 86400),
            'status'      => Match3Season::STATUS_ACTIVE,
            'reward_conf' => self::defaultRewards(),
            'create_time'  => date('Y-m-d H:i:s'),
        ]);

        return Match3Season::where('season_id', $seasonId)->find()?->toArray() ?? $created->toArray();
    }

    /**
     * 当前赛季信息 + 我的段位/积分/奖励（05 §A4.1 第 12 项）
     *
     * @return array<string,mixed>
     */
    public function current(int $userId): array
    {
        $season = $this->ensureSeason();
        $seasonId = (string) $season['season_id'];

        $row = Match3SeasonPlayer::where('season_id', $seasonId)->where('user_id', $userId)->find();

        $points = $row ? (int) $row->points : 0;
        $tier   = $row ? (string) $row->tier : SocialRules::tierOf(0);

        $rewards = is_array($season['reward_conf'] ?? null) ? $season['reward_conf'] : self::defaultRewards();
        $rankInfo = $this->rank->seasonRank($seasonId, $userId, 50);

        return [
            'seasonId'    => $seasonId,
            'name'        => (string) ($season['name'] ?? ''),
            'startAt'     => $season['start_at'] ?? null,
            'endAt'       => $season['end_at'] ?? null,
            'status'      => (int) ($season['status'] ?? 0),
            'statusText'  => Match3Season::statusText((int) ($season['status'] ?? 0)),
            'me'          => [
                'points'         => $points,
                'tier'           => $tier,
                'tierName'       => SocialRules::tierName($tier),
                'wins'           => $row ? (int) $row->wins : 0,
                'losses'         => $row ? (int) $row->losses : 0,
                'bestScore'      => $row ? (int) $row->best_score : 0,
                'rank'           => $rankInfo['myRank'],
                'rewardClaimed'  => $row ? (int) $row->reward_claimed === 1 : false,
                'nextTier'       => $this->nextTierOf($tier),
                'pointsToNext'   => $this->pointsToNextTier($points),
            ],
            'tiers'       => $this->tierTable(),
            'rank'        => $rankInfo['list'],
        ];
    }

    /**
     * 积分变更（判定后调用）
     *
     * @return array{points:int, tier:string, tierName:string, delta:int, tierUp:bool}
     */
    public function addPoints(int $userId, int $delta, string $result = '', int $score = 0): array
    {
        $seasonId = $this->currentSeasonId();

        return Db::transaction(function () use ($seasonId, $userId, $delta, $result, $score) {
            $row = Match3SeasonPlayer::where('season_id', $seasonId)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();

            if (!$row) {
                Match3SeasonPlayer::create([
                    'season_id'  => $seasonId,
                    'user_id'    => $userId,
                    'points'     => 0,
                    'tier'       => 'bronze',
                    'wins'       => 0,
                    'losses'     => 0,
                    'best_score' => 0,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
                $row = Match3SeasonPlayer::where('season_id', $seasonId)->where('user_id', $userId)->find();
            }

            $tierBefore = (string) $row->tier;
            $points     = SocialRules::applyFloor((int) $row->points + $delta, $tierBefore);
            $tierAfter  = SocialRules::tierOf($points);

            $data = [
                'points'     => $points,
                'tier'       => $tierAfter,
                'best_score' => max((int) $row->best_score, $score),
                'update_time' => date('Y-m-d H:i:s'),
            ];
            if ($result === 'win') {
                $data['wins'] = (int) $row->wins + 1;
            } elseif (in_array($result, ['lose', 'timeout'], true)) {
                $data['losses'] = (int) $row->losses + 1;
            }

            Match3SeasonPlayer::where('id', (int) $row->id)->update($data);
            $this->rank->reportSeason($seasonId, $userId, $points);

            return [
                'points'   => $points,
                'tier'     => $tierAfter,
                'tierName' => SocialRules::tierName($tierAfter),
                'delta'    => $delta,
                'tierUp'   => $tierAfter !== $tierBefore,
            ];
        });
    }

    /**
     * 当日首胜去重（原子：单条带条件的 UPDATE）
     *
     * @return bool true = 今天第一次胜，可给 +10
     */
    public function markFirstWinOfDay(int $userId, ?string $day = null): bool
    {
        // 用"当前赛季"（而非玩家最后一条记录）——赛季行由 addPoints 保证已存在
        $seasonId = $this->currentSeasonId();
        $day      = $day ?: date('Y-m-d');

        $affected = Db::name('match3_season_player')
            ->where('season_id', $seasonId)
            ->where('user_id', $userId)
            ->where(function ($q) use ($day) {
                $q->whereNull('last_first_win_day')->whereOr('last_first_win_day', '<>', $day);
            })
            ->update(['last_first_win_day' => $day]);

        return $affected > 0;
    }

    /**
     * 领取赛季段位奖励（05 §A4.1 第 13 项）
     *
     * @return array<string,mixed>
     * @throws SocialException
     */
    public function claim(int $userId): array
    {
        $season   = $this->ensureSeason();
        $seasonId = (string) $season['season_id'];

        $row = Match3SeasonPlayer::where('season_id', $seasonId)->where('user_id', $userId)->find();
        if (!$row) {
            throw new SocialException('本赛季还没有战绩，无法领取', SocialException::CODE_SEASON_CLOSED);
        }
        if ((int) $row->reward_claimed === 1) {
            throw new SocialException('本赛季奖励已领取', SocialException::CODE_ALREADY_DONE);
        }

        $tier    = (string) $row->tier;
        $rewards = (is_array($season['reward_conf'] ?? null) ? $season['reward_conf'] : self::defaultRewards());
        $conf    = (array) ($rewards[$tier] ?? []);

        $items = [];
        $coin  = (int) ($conf['coin'] ?? 0);
        if ($coin > 0) {
            $items[] = ['item_id' => Match3Constants::ITEM_COINS, 'count' => $coin];
        }
        foreach ((array) ($conf['props'] ?? []) as $code => $count) {
            $items[] = ['item_id' => (string) $code, 'count' => (int) $count];
        }

        $balances = [];
        if ($items !== []) {
            $balances = $this->bridge->grantRewards($userId, $items, RewardReason::SEASON_REWARD, [
                'season_id' => $seasonId, 'tier' => $tier,
            ]);
        }

        $cosmetics = array_values((array) ($conf['cosmetics'] ?? []));
        if ($cosmetics !== []) {
            // 外观奖励暂不入库（P6 inventory 外观体系），先留痕便于补发
            ContextualLogger::info('[match3] 赛季外观奖励待接入', [
                'user_id' => $userId, 'season_id' => $seasonId, 'cosmetics' => $cosmetics,
            ]);
        }

        Match3SeasonPlayer::where('id', (int) $row->id)->update(['reward_claimed' => 1]);

        return [
            'seasonId'  => $seasonId,
            'tier'      => $tier,
            'tierName'  => SocialRules::tierName($tier),
            'items'     => $items,
            'balances'  => $balances,
            'cosmetics' => $cosmetics,
        ];
    }

    /**
     * 结算并切换到下一赛季（`match3:season` 命令）
     *
     * @return array{settled:string|null, opened:string}
     */
    public function switchSeason(string $fromSeasonId = ''): array
    {
        $settled = null;

        if ($fromSeasonId !== '') {
            Match3Season::where('season_id', $fromSeasonId)->update(['status' => Match3Season::STATUS_SETTLED]);
            $settled = $fromSeasonId;
        } else {
            // 把所有已过期的进行中赛季标记结算
            $expired = Match3Season::where('status', Match3Season::STATUS_ACTIVE)
                ->where('end_at', '<', date('Y-m-d H:i:s'))
                ->select()
                ->toArray();
            foreach ($expired as $s) {
                Match3Season::where('season_id', $s['season_id'])->update(['status' => Match3Season::STATUS_SETTLED]);
                $settled = (string) $s['season_id'];
            }
        }

        $opened = $this->ensureSeason();
        Match3Season::where('season_id', $opened['season_id'])->update(['status' => Match3Season::STATUS_ACTIVE]);

        return ['settled' => $settled, 'opened' => (string) $opened['season_id']];
    }

    /* ======================================================================
     *  段位表
     * ====================================================================== */

    /**
     * 全段位表（前端解锁树/进度条用）
     *
     * @return array<int,array<string,mixed>>
     */
    protected function tierTable(): array
    {
        $out = [];
        foreach (SocialRules::TIERS as $tier => [$min, $max]) {
            $out[] = [
                'tier'     => $tier,
                'name'     => SocialRules::tierName($tier),
                'min'      => $min,
                'max'      => $max,
                'rewards'  => self::defaultRewards()[$tier] ?? [],
            ];
        }

        return $out;
    }

    protected function nextTierOf(string $tier): ?string
    {
        $keys = array_keys(SocialRules::TIERS);
        $i    = array_search($tier, $keys, true);

        return $i === false ? null : ($keys[$i + 1] ?? null);
    }

    protected function pointsToNextTier(int $points): int
    {
        foreach (SocialRules::TIERS as $tier => [$min, $_max]) {
            if ($points < $min) {
                return $min - $points;
            }
        }

        return 0;
    }
}
