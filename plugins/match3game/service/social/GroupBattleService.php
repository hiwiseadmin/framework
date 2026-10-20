<?php

declare(strict_types=1);

namespace plugin\match3game\service\social;

use plugin\match3game\model\Match3GroupBattle;
use plugin\match3game\model\Match3GroupBattleMember;
use plugin\match3game\model\Match3LevelDef;
use plugin\match3game\service\level\Match3Random;
use wise\support\context\ContextualLogger;

/**
 * 群擂台服务（05 §A3.5 / §A7.3，P5-B10）
 *
 * 【合规红线】`group_id` **只存加密群标识**（openGId），
 * 严禁明文群名/群号 —— 见 `PvpService::assertEncryptedGroupId()`（05 §A8.1 第 8 条）。
 *
 * 【周期与计分】
 *   周期      每周一 00:00 刷新（`SocialRules::periodId()` → `2026-W37`）
 *   关卡池     3 关（简单/中等/困难），由 periodId 确定性派生 → 全群统一
 *   seed     全群统一（与关卡池同源）
 *   计分      群内 **Top5** 累加为群总分（`topK()` 纯函数）
 *   次数      每关每日 3 次（`stage_plays` 逐关计数，跨天归零）
 *   群间排名    每日 02:00 重算（`recalcRanks()`，`match3:group-rank` 命令）
 *
 * 【懒生成】当期没有行时自动创建，不依赖定时任务也能开打；
 * 定时任务只负责"重算排名"这件必须集中做的事。
 */
class GroupBattleService
{
    protected RankService $rank;

    public function __construct(?RankService $rank = null)
    {
        $this->rank = $rank ?? new RankService();
    }

    /* ======================================================================
     *  周期
     * ====================================================================== */

    /**
     * 取（或创建）某群当期擂台
     *
     * @return array<string,mixed>
     */
    public function ensurePeriod(string $groupId, ?string $periodId = null): array
    {
        $periodId = $periodId ?: SocialRules::periodId();

        $row = Match3GroupBattle::where('group_id', $groupId)->where('period_id', $periodId)->find();
        if ($row) {
            return $row->toArray();
        }

        $seed  = (int) (crc32('match3-group-' . $periodId) & 0x7FFFFFFF);
        $pool  = $this->pickStagePool($seed);
        $start = strtotime('monday this week 00:00:00') ?: time();

        Match3GroupBattle::create([
            'group_id'    => $groupId,
            'period_id'   => $periodId,
            'stage_pool'  => $pool,
            'seed'        => $seed,
            'total_score' => 0,
            'member_cnt'  => 0,
            'rank'        => 0,
            'start_at'    => date('Y-m-d H:i:s', $start),
            'end_at'      => date('Y-m-d H:i:s', $start + 7 * 86400),
            'update_time'  => date('Y-m-d H:i:s'),
        ]);

        return Match3GroupBattle::where('group_id', $groupId)->where('period_id', $periodId)->find()?->toArray() ?? [];
    }

    /**
     * 当期关卡池：1 简单 + 1 中等 + 1 困难，按 periodId 确定性抽取（全群统一）
     *
     * @return array<int,array<string,mixed>>
     */
    protected function pickStagePool(int $seed): array
    {
        $byDifficulty = [
            'easy'   => $this->levelsOf(1, 2),
            'medium' => $this->levelsOf(3),
            'hard'   => $this->levelsOf(4, 5),
        ];

        $mask = 0;
        $pool = [];
        foreach (['easy', 'medium', 'hard'] as $i => $key) {
            $candidates = array_values(array_filter(
                $byDifficulty[$key],
                static fn ($c) => ($mask & (1 << (int) $c['idx'])) === 0
            ));
            if ($candidates === []) {
                $candidates = $byDifficulty[$key];
            }
            if ($candidates === []) {
                continue;
            }

            $weights = [];
            foreach ($candidates as $c) {
                $weights[(string) $c['code']] = 100;
            }
            $picked = (string) Match3Random::weightedPick($seed, $weights, (int) crc32('group' . $key), $i);

            foreach ($candidates as $c) {
                if ((string) $c['code'] === $picked) {
                    $pool[] = ['code' => $c['code'], 'name' => $c['name'], 'difficulty' => $key];
                    $mask |= 1 << (int) $c['idx'];
                    break;
                }
            }
        }

        return $pool;
    }

    /**
     * 取启用关卡并按难度筛选
     *
     * @return array<int,array<string,mixed>>
     */
    protected function levelsOf(int ...$difficulties): array
    {
        $rows = Match3LevelDef::where('status', 1)
            ->field('id, code, name, difficulty')
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();

        $out = [];
        foreach ($rows as $i => $r) {
            if (in_array((int) $r['difficulty'], $difficulties, true)) {
                $out[] = ['code' => (string) $r['code'], 'name' => (string) $r['name'], 'idx' => $i];
            }
        }

        return $out;
    }

    /* ======================================================================
     *  读取
     * ====================================================================== */

    /**
     * 当前群擂台（05 §A4.1 第 14 项）
     *
     * @return array<string,mixed>
     */
    public function current(int $userId, string $groupId, ?string $periodId = null): array
    {
        $period   = $this->ensurePeriod($groupId, $periodId);
        $periodId = (string) $period['period_id'];

        $me = Match3GroupBattleMember::where('group_id', $groupId)
            ->where('period_id', $periodId)
            ->where('user_id', $userId)
            ->find();

        return [
            'groupId'    => $groupId,
            'periodId'   => $periodId,
            'startAt'    => $period['start_at'] ?? null,
            'endAt'      => $period['end_at'] ?? null,
            'seed'       => (int) $period['seed'],
            'stagePool'  => (array) $period['stage_pool'],
            'groupRank'  => (int) $period['rank'],
            'groupScore' => (int) $period['total_score'],
            'memberCnt'  => (int) $period['member_cnt'],
            'rules'      => [
                'topN'        => SocialRules::GROUP_TOP_N,
                'dailyPlays'  => SocialRules::GROUP_DAILY_PLAYS,
            ],
            'me' => [
                'score'      => $me ? (int) $me->score : 0,
                'stagePlays' => $me ? (array) ($me->stage_plays ?? []) : [],
                'remain'     => $this->remainingPlays($me, (array) $period['stage_pool']),
            ],
            'members'  => $this->rank->groupMemberRank($groupId, $periodId, $userId, 50),
            'board'    => $this->rank->groupBoardRank($periodId, 20),
        ];
    }

    /**
     * 各关剩余可打次数（本期关卡池的每一关都要出现，未打过 = 满额）
     *
     * @param Match3GroupBattleMember|null   $me
     * @param array<int,array<string,mixed>> $stagePool
     * @return array<string,int>
     */
    protected function remainingPlays(?Match3GroupBattleMember $me, array $stagePool = []): array
    {
        $today = date('Y-m-d');
        $plays = $me ? (array) ($me->stage_plays ?? []) : [];

        $codes = [];
        foreach ($stagePool as $s) {
            if (is_array($s) && !empty($s['code'])) {
                $codes[] = (string) $s['code'];
            }
        }
        foreach (array_keys($plays) as $code) {
            $codes[] = (string) $code;
        }

        $out = [];
        foreach (array_unique($codes) as $code) {
            $entry = (array) ($plays[$code] ?? []);
            $count = ((string) ($entry['day'] ?? '')) === $today ? (int) ($entry['count'] ?? 0) : 0;
            $out[$code] = max(0, SocialRules::GROUP_DAILY_PLAYS - $count);
        }

        return $out;
    }

    /**
     * 校验并消耗一次挑战次数（每关每日 3 次）
     *
     * @throws SocialException
     */
    public function consumePlay(string $groupId, string $periodId, int $userId, string $stageCode): int
    {
        $row = $this->memberRow($groupId, $periodId, $userId);
        $today = date('Y-m-d');

        $plays = (array) ($row->stage_plays ?? []);
        $entry = (array) ($plays[$stageCode] ?? []);
        $count = ((string) ($entry['day'] ?? '')) === $today ? (int) ($entry['count'] ?? 0) : 0;

        if ($count >= SocialRules::GROUP_DAILY_PLAYS) {
            throw new SocialException(
                sprintf('本关今日已打满 %d 次', SocialRules::GROUP_DAILY_PLAYS),
                SocialException::CODE_LIMIT
            );
        }

        $plays[$stageCode] = ['day' => $today, 'count' => $count + 1];

        // play_count / play_day 作为"当日总次数"的粗粒度统计
        $totalToday = ((string) $row->play_day) === $today ? (int) $row->play_count : 0;
        $totalToday++;

        Match3GroupBattleMember::where('id', (int) $row->id)->update([
            'stage_plays' => json_encode($plays, JSON_UNESCAPED_UNICODE),
            'play_count'  => $totalToday,
            'play_day'    => $today,
            'update_time'  => date('Y-m-d H:i:s'),
        ]);

        return SocialRules::GROUP_DAILY_PLAYS - ($count + 1);
    }

    /**
     * 写入成绩（取个人最佳）并重算群总分
     *
     * @return array{score:int, totalScore:int}
     */
    public function recordScore(string $groupId, string $periodId, int $userId, int $score): array
    {
        $row = $this->memberRow($groupId, $periodId, $userId);

        $best = max((int) $row->score, max(0, $score));
        Match3GroupBattleMember::where('id', (int) $row->id)->update([
            'score'      => $best,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->rank->reportGroup($groupId, $periodId, $userId, $best);
        $total = $this->recalcGroupScore($groupId, $periodId);

        return ['score' => $best, 'totalScore' => $total['totalScore']];
    }

    /**
     * 重算群总分（群内 Top5 累加）
     *
     * @return array{totalScore:int, memberCnt:int}
     */
    public function recalcGroupScore(string $groupId, string $periodId): array
    {
        $rows = Match3GroupBattleMember::where('group_id', $groupId)
            ->where('period_id', $periodId)
            ->order('score', 'desc')
            ->select()
            ->toArray();

        $scores    = array_map(static fn ($r) => (int) $r['score'], $rows);
        $total     = self::topK($scores, SocialRules::GROUP_TOP_N);
        $memberCnt = count($rows);

        Match3GroupBattle::where('group_id', $groupId)->where('period_id', $periodId)->update([
            'total_score' => $total,
            'member_cnt'  => $memberCnt,
            'update_time'  => date('Y-m-d H:i:s'),
        ]);

        $this->rank->reportGroupTotal($groupId, $periodId, $total);

        return ['totalScore' => $total, 'memberCnt' => $memberCnt];
    }

    /**
     * 群间排名重算（每日 02:00，`match3:group-rank` 命令）
     *
     * @return int 处理的群数
     */
    public function recalcRanks(string $periodId): int
    {
        $groups = Match3GroupBattle::where('period_id', $periodId)
            ->order('total_score', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $rank = 0;
        foreach ($groups as $g) {
            $rank++;
            Match3GroupBattle::where('id', (int) $g['id'])->update([
                'rank'       => $rank,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            if ($rank <= 100) {
                // 只把前 100 群推入 Redis 榜，避免榜单过长
                $this->rank->reportGroupTotal((string) $g['group_id'], $periodId, (int) $g['total_score']);
            }
        }

        ContextualLogger::info('[match3] 群擂台排名已重算', ['period_id' => $periodId, 'groups' => $rank]);

        return $rank;
    }

    /* ======================================================================
     *  纯函数（可离线单测）
     * ====================================================================== */

    /**
     * TopK 累加（05 §A7.3：群内 Top5 分数累加为群总分）
     *
     * @param array<int,int> $scores
     */
    public static function topK(array $scores, int $k): int
    {
        $scores = array_map('intval', $scores);
        rsort($scores);

        return (int) array_sum(array_slice($scores, 0, max(0, $k)));
    }

    /* ======================================================================
     *  内部
     * ====================================================================== */

    protected function memberRow(string $groupId, string $periodId, int $userId): Match3GroupBattleMember
    {
        $row = Match3GroupBattleMember::where('group_id', $groupId)
            ->where('period_id', $periodId)
            ->where('user_id', $userId)
            ->find();

        if ($row) {
            return $row;
        }

        Match3GroupBattleMember::create([
            'group_id'    => $groupId,
            'period_id'   => $periodId,
            'user_id'     => $userId,
            'score'       => 0,
            'play_count'  => 0,
            'play_day'    => null,
            'stage_plays' => [],
            'update_time'  => date('Y-m-d H:i:s'),
        ]);

        return Match3GroupBattleMember::where('group_id', $groupId)
            ->where('period_id', $periodId)
            ->where('user_id', $userId)
            ->find();
    }
}
