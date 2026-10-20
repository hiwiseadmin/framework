<?php

declare(strict_types=1);

namespace plugin\match3game\service\social;

use wise\support\tools\RedisHelper;
use plugin\match3game\model\Match3Battle;
use plugin\match3game\model\Match3GroupBattle;
use plugin\match3game\model\Match3GroupBattleMember;
use plugin\match3game\model\Match3SeasonPlayer;
use think\facade\Db;
use wise\support\context\ContextualLogger;

/**
 * 排行榜服务（05 §A3 / §A7，P5-B07）
 *
 * 【为何用 Redis ZSet】提交后要在 **3 秒内**反映到榜单（05 §A8.1 第 6 条），
 * MySQL 聚合在 200 人好友榜上 P95 会超标。ZSet 天然有序 + O(logN) 写入。
 *
 * 【降级策略】Redis 不可用时**自动退化为 MySQL 聚合**（功能不中断，性能下降）：
 *   global → match3_player_progress（每人 MAX(best_score)）
 *   daily  → match3_battle（当天 MAX(score)）
 *   season → match3_season_player
 *   group  → match3_group_battle / match3_group_battle_member
 * 因此排行榜在 Redis 故障期间仍可读，只是延迟变高（可接受）。
 *
 * 【好友榜的好友来源】微信关系链由前端 `wx.getFriendCloudStorage` 获取，
 * 服务端只接受客户端传来的 userId 列表（上限 100）做**排序**，
 * 不落库、不缓存好友关系（避免隐私合规问题）。
 */
class RankService
{
    public const BOARD_GLOBAL = 'global';
    public const BOARD_DAILY  = 'daily';

    /** 单榜最大返回条数 */
    public const MAX_LIMIT = 100;

    protected const PREFIX = 'match3:rank:';

    /** Redis 可用性探测结果（每请求缓存一次） */
    protected ?bool $redisOk = null;

    protected ?RedisHelper $helper = null;

    /* ======================================================================
     *  写入
     * ====================================================================== */

    /**
     * 记录一局成绩 → 全服榜（个人最佳）+ 每日榜
     *
     * @param array<string,mixed> $ctx {mode, stageCode, day}
     */
    public function reportGameScore(int $userId, int $score, array $ctx = []): void
    {
        $score = max(0, $score);
        $day   = (string) ($ctx['day'] ?? date('Y-m-d'));

        $this->zAddMax(self::PREFIX . self::BOARD_GLOBAL, $userId, $score);

        $dailyKey = self::PREFIX . self::BOARD_DAILY . ':' . $day;
        $this->zAddMax($dailyKey, $userId, $score);
        $this->expire($dailyKey, 7 * 86400);

        // 好友榜复用全服榜（按好友 id 取分数），无需额外写入
    }

    /**
     * 赛季积分榜（覆盖写：积分是连续值，不是最佳值）
     */
    public function reportSeason(string $seasonId, int $userId, int $points): void
    {
        if ($seasonId === '') {
            return;
        }

        $key = self::PREFIX . 'season:' . $seasonId;
        $this->zAdd($key, $userId, $points);
        $this->expire($key, 120 * 86400);
    }

    /**
     * 群擂台：成员榜（个人最佳）
     */
    public function reportGroup(string $groupId, string $periodId, int $userId, int $score): void
    {
        $key = self::PREFIX . 'group:' . $periodId . ':' . $groupId;
        $this->zAddMax($key, $userId, max(0, $score));
        $this->expire($key, 60 * 86400);
    }

    /**
     * 群擂台：群间榜（群总分）
     */
    public function reportGroupTotal(string $groupId, string $periodId, int $totalScore): void
    {
        $key = self::PREFIX . 'grouplist:' . $periodId;
        // 群总分用 groupId 字符串作 member
        $conn = $this->conn();
        if ($conn === null) {
            return;
        }

        try {
            $this->raw()->zAdd($key, (float) max(0, $totalScore), $groupId);
            $this->raw()->expire($key, 60 * 86400);
        } catch (\Throwable $e) {
            $this->warn('reportGroupTotal', $e);
        }
    }

    /* ======================================================================
     *  读取
     * ====================================================================== */

    /**
     * 好友榜（05 §A4.1 第 5 项）
     *
     * @param  array<int,int> $friendIds 前端从微信关系链拿到的 userId 列表（上限 100）
     * @param  int            $limit
     * @return array{list:array<int,array<string,mixed>>, myRank:int|null, myScore:int}
     */
    public function friendRank(int $userId, array $friendIds = [], int $limit = 50): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', array_merge([$userId], $friendIds)),
            static fn ($v) => $v > 0
        )));
        $ids   = array_slice($ids, 0, 100);
        $limit = $this->clampLimit($limit);

        $key   = self::PREFIX . self::BOARD_GLOBAL;
        $scores = $this->multiScore($key, $ids);

        if ($scores === []) {
            // Redis 不可用 → MySQL 降级
            $scores = $this->dbGlobalScores($ids);
        }

        // 按分数倒序（同分按 userId 升序，保证稳定）
        uksort($scores, static function ($a, $b) use ($scores) {
            return $scores[$b] <=> $scores[$a] ?: $a <=> $b;
        });

        $list = [];
        $rank = 0;
        foreach ($scores as $id => $score) {
            $rank++;
            if ($rank > $limit && $id !== $userId) {
                continue;
            }
            $list[] = [
                'rank'   => $rank,
                'userId' => (int) $id,
                'score'  => (int) $score,
                'isMe'   => (int) $id === $userId,
            ];
        }

        $myRank  = null;
        $myScore = (int) ($scores[$userId] ?? 0);
        foreach ($list as $row) {
            if ($row['isMe']) {
                $myRank = $row['rank'];
            }
        }

        return ['list' => $list, 'myRank' => $myRank, 'myScore' => $myScore];
    }

    /**
     * 每日挑战榜（05 §A4.1 第 7 项）
     *
     * @return array{list:array<int,array<string,mixed>>, myRank:int|null, myScore:int, day:string}
     */
    public function dailyRank(int $userId, ?string $day = null, int $limit = 50): array
    {
        $day   = $day ?: date('Y-m-d');
        $limit = $this->clampLimit($limit);

        $key    = self::PREFIX . self::BOARD_DAILY . ':' . $day;
        $list   = $this->topWithScores($key, $limit);
        $myScore = (int) $this->scoreOf($key, $userId);
        $myRank  = $this->rankOf($key, $userId);

        if ($list === []) {
            // 降级：当天完成的对局（match3_battle）取每人最高分
            $rows = Db::name('match3_battle')
                ->field('user_id, MAX(score) AS score')
                ->where('status', Match3Battle::STATUS_DONE)
                ->where('started_at', 'between', [$day . ' 00:00:00', $day . ' 23:59:59'])
                ->group('user_id')
                ->order('score', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();

            $list = [];
            foreach ($rows as $i => $r) {
                $list[] = ['rank' => $i + 1, 'userId' => (int) $r['user_id'], 'score' => (int) $r['score'], 'isMe' => (int) $r['user_id'] === $userId];
                if ((int) $r['user_id'] === $userId) {
                    $myScore = (int) $r['score'];
                    $myRank  = $i + 1;
                }
            }
        }

        return ['list' => $list, 'myRank' => $myRank, 'myScore' => $myScore, 'day' => $day];
    }

    /**
     * 赛季榜（05 §A4.1 第 12 项附带）
     *
     * @return array{list:array<int,array<string,mixed>>, myRank:int|null, myPoints:int}
     */
    public function seasonRank(string $seasonId, int $userId, int $limit = 50): array
    {
        $limit = $this->clampLimit($limit);
        $key   = self::PREFIX . 'season:' . $seasonId;

        $list    = $this->topWithScores($key, $limit);
        $myPoints = (int) $this->scoreOf($key, $userId);
        $myRank   = $this->rankOf($key, $userId);

        if ($list === []) {
            $rows = Match3SeasonPlayer::where('season_id', $seasonId)
                ->order('points', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();

            $list = [];
            foreach ($rows as $i => $r) {
                $list[] = [
                    'rank'   => $i + 1,
                    'userId' => (int) $r['user_id'],
                    'score'  => (int) $r['points'],
                    'tier'   => (string) $r['tier'],
                    'isMe'   => (int) $r['user_id'] === $userId,
                ];
                if ((int) $r['user_id'] === $userId) {
                    $myPoints = (int) $r['points'];
                    $myRank   = $i + 1;
                }
            }
        }

        return ['list' => $list, 'myRank' => $myRank, 'myPoints' => $myPoints];
    }

    /**
     * 群内成员榜
     *
     * @return array<int,array<string,mixed>>
     */
    public function groupMemberRank(string $groupId, string $periodId, int $userId, int $limit = 50): array
    {
        $limit = $this->clampLimit($limit);
        $key   = self::PREFIX . 'group:' . $periodId . ':' . $groupId;

        $list = $this->topWithScores($key, $limit);

        if ($list === []) {
            $rows = Match3GroupBattleMember::where('group_id', $groupId)
                ->where('period_id', $periodId)
                ->order('score', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();

            $list = [];
            foreach ($rows as $i => $r) {
                $list[] = ['rank' => $i + 1, 'userId' => (int) $r['user_id'], 'score' => (int) $r['score'], 'isMe' => (int) $r['user_id'] === $userId];
            }
        }

        foreach ($list as $i => $row) {
            $list[$i]['isMe'] = ((int) $row['userId']) === $userId;
        }

        return $list;
    }

    /**
     * 群间排名榜（05 §A4.1 第 14 项）
     *
     * @return array<int,array<string,mixed>>
     */
    public function groupBoardRank(string $periodId, int $limit = 20): array
    {
        $limit = $this->clampLimit($limit);
        $key   = self::PREFIX . 'grouplist:' . $periodId;

        $list = [];
        foreach ($this->topWithScores($key, $limit) as $i => $row) {
            $list[] = [
                'rank'       => $i + 1,
                'groupId'    => (string) $row['userId'],   // 这里 member 是 groupId
                'totalScore' => (int) $row['score'],
            ];
        }

        if ($list !== []) {
            return $list;
        }

        // 降级：读库（rank 字段由定时任务每日 02:00 重算并写回）
        $rows = Match3GroupBattle::where('period_id', $periodId)
            ->order('total_score', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        foreach ($rows as $i => $r) {
            $list[] = [
                'rank'       => (int) ($r['rank'] ?: $i + 1),
                'groupId'    => (string) $r['group_id'],
                'totalScore' => (int) $r['total_score'],
                'memberCnt'  => (int) $r['member_cnt'],
            ];
        }

        return $list;
    }

    /* ======================================================================
     *  Redis 底层（带降级）
     * ====================================================================== */

    /**
     * 是否可用 Redis（探测结果按请求缓存）
     */
    public function available(): bool
    {
        return $this->conn() !== null;
    }

    protected function conn(): ?RedisHelper
    {
        if ($this->redisOk === false) {
            return null;
        }
        if ($this->helper !== null) {
            return $this->helper;
        }

        try {
            $helper = RedisHelper::getInstance('', config('cache.stores.redis'))->withoutPrefix();
            // 探活（phpredis / Predis 都支持 ping）
            $helper->raw()->ping();
            $this->helper  = $helper;
            $this->redisOk = true;

            return $helper;
        } catch (\Throwable $e) {
            $this->redisOk = false;
            $this->warn('redis unavailable, fallback to mysql', $e);

            return null;
        }
    }

    /** @return object|null 原生客户端 */
    protected function raw()
    {
        $conn = $this->conn();

        return $conn?->raw();
    }

    protected function driver(): string
    {
        return $this->helper?->getDriver() ?? 'phpredis';
    }

    /**
     * 只保留最大值的写入（个人最佳分）
     */
    protected function zAddMax(string $key, int $userId, int $score): void
    {
        $raw = $this->raw();
        if ($raw === null) {
            return;
        }

        try {
            $method = $this->driver() === 'phpredis' ? 'zScore' : 'zscore';
            $cur    = $raw->{$method}($key, (string) $userId);

            if ($cur === false || $cur === null || (float) $score > (float) $cur) {
                $raw->zAdd($key, (float) $score, (string) $userId);
            }
        } catch (\Throwable $e) {
            $this->warn('zAddMax', $e);
        }
    }

    protected function zAdd(string $key, int $userId, int $score): void
    {
        $raw = $this->raw();
        if ($raw === null) {
            return;
        }

        try {
            $raw->zAdd($key, (float) $score, (string) $userId);
        } catch (\Throwable $e) {
            $this->warn('zAdd', $e);
        }
    }

    protected function expire(string $key, int $ttl): void
    {
        $raw = $this->raw();
        if ($raw === null) {
            return;
        }

        try {
            $raw->expire($key, $ttl);
        } catch (\Throwable $e) {
            $this->warn('expire', $e);
        }
    }

    protected function scoreOf(string $key, int $userId): float|null
    {
        $raw = $this->raw();
        if ($raw === null) {
            return null;
        }

        try {
            $method = $this->driver() === 'phpredis' ? 'zScore' : 'zscore';
            $score  = $raw->{$method}($key, (string) $userId);

            return ($score === false || $score === null) ? null : (float) $score;
        } catch (\Throwable $e) {
            $this->warn('scoreOf', $e);

            return null;
        }
    }

    /**
     * 名次（1-based，Redis ZREVRANK 是 0-based）
     */
    protected function rankOf(string $key, int $userId): ?int
    {
        $raw = $this->raw();
        if ($raw === null) {
            return null;
        }

        try {
            $method = $this->driver() === 'phpredis' ? 'zRevRank' : 'zrevrank';
            $rank   = $raw->{$method}($key, (string) $userId);

            return ($rank === false || $rank === null) ? null : ((int) $rank + 1);
        } catch (\Throwable $e) {
            $this->warn('rankOf', $e);

            return null;
        }
    }

    /**
     * 批量取分（好友榜用；一次 pipeline 太复杂，这里按 n 次 ZSCORE，上限 100 次）
     *
     * @param  array<int,int> $userIds
     * @return array<int,int> [userId => score]
     */
    protected function multiScore(string $key, array $userIds): array
    {
        $raw = $this->raw();
        if ($raw === null) {
            return [];
        }

        $out    = [];
        $method = $this->driver() === 'phpredis' ? 'zScore' : 'zscore';

        foreach ($userIds as $id) {
            try {
                $score = $raw->{$method}($key, (string) $id);
                if ($score !== false && $score !== null) {
                    $out[(int) $id] = (int) $score;
                }
            } catch (\Throwable $e) {
                $this->warn('multiScore', $e);

                return [];
            }
        }

        return $out;
    }

    /**
     * Top N（分数倒序）
     *
     * @return array<int,array{userId:string, score:int}>
     */
    protected function topWithScores(string $key, int $limit): array
    {
        $raw = $this->raw();
        if ($raw === null) {
            return [];
        }

        try {
            if ($this->driver() === 'phpredis') {
                $data = $raw->zRevRange($key, 0, $limit - 1, true);
            } else {
                $data = $raw->zrevrange($key, 0, $limit - 1, ['withscores' => true]);
            }
        } catch (\Throwable $e) {
            $this->warn('topWithScores', $e);

            return [];
        }

        if (!is_array($data)) {
            return [];
        }

        $list = [];
        foreach ($data as $member => $score) {
            // phpredis 可能返回数字下标 + 交替值（旧版本），此处统一成关联数组
            if (is_int($member) && is_array($score)) {
                $list[] = ['userId' => (string) $score[0], 'score' => (int) $score[1]];
                continue;
            }
            $list[] = ['userId' => (string) $member, 'score' => (int) $score];
        }

        return $list;
    }

    /**
     * MySQL 降级：全服个人最佳分
     *
     * @param  array<int,int> $userIds
     * @return array<int,int>
     */
    protected function dbGlobalScores(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        try {
            $rows = Db::name('match3_player_progress')
                ->field('user_id, MAX(best_score) AS score')
                ->whereIn('user_id', $userIds)
                ->group('user_id')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            $this->warn('dbGlobalScores', $e);

            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = (int) $r['score'];
        }

        return $out;
    }

    protected function clampLimit(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
    }

    protected function warn(string $method, \Throwable $e): void
    {
        ContextualLogger::warning('[match3][rank] ' . $method . ' 失败，已降级: ' . $e->getMessage());
    }
}
