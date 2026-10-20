<?php

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

use plugin\match3game\model\Match3DailyChallenge;
use plugin\match3game\model\Match3Run;
use plugin\match3game\service\level\Match3Random;

/**
 * 每日挑战服务（Phase 4 · 05 §B7.5）
 *
 * 【核心约束】**当日全服一致**：所有随机都只依赖日期。
 *   map_seed   = crc32('match3-daily-' + day)
 *   level_seed = deriveSeed(map_seed, 'level')
 *   relic_pool = 从奖池确定性地抽 3 个遗物（当天三选一固定为这三张）
 *
 * 【生成方式】**懒生成**：首个用户访问当日挑战时创建（幂等，唯一键保护），
 *   不依赖定时任务；命令 `match3:daily` 仅用于预热/排障。
 */
class DailyService
{
    /** 每日挑战的层数（1 层 = 5 个节点，约 10 分钟内） */
    public const LAYERS = 1;

    /** 当日固定遗物数（三选一的三张） */
    public const RELIC_POOL_SIZE = 3;

    /** 每日挑战通关灵石基础奖励 */
    public const STONE_BASE = 100;

    /**
     * 由日期派生种子（纯函数）
     */
    public static function seedForDay(string $day): int
    {
        return (int) (crc32('match3-daily-' . $day) & 0x7FFFFFFF);
    }

    /**
     * 今天（服务器时区）
     */
    public static function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * 取当日挑战配置（不存在则懒生成）
     *
     * @return array<string,mixed>
     */
    public function ensure(?string $day = null): array
    {
        $day = $day ?: self::today();

        $row = $this->find($day);
        if ($row !== null) {
            return $row;
        }

        $mapSeed   = self::seedForDay($day);
        $levelSeed = Match3Random::deriveSeed($mapSeed, (int) crc32('level'));

        $created = Match3DailyChallenge::create([
            'day'          => $day,
            'map_seed'     => $mapSeed,
            'level_seed'   => $levelSeed,
            'layers'       => self::LAYERS,
            'relic_pool'   => $this->pickRelicPool($levelSeed),
            'stage_code'   => null,
            'stone_reward' => self::STONE_BASE + ($mapSeed % 200),
            'status'       => 1,
            'create_time'   => date('Y-m-d H:i:s'),
        ]);

        // 并发下唯一键冲突 → 回读已存在的那行
        return $this->find($day) ?? $created->toArray();
    }

    /**
     * 查询某日的挑战（不存在返回 null）
     *
     * @return array<string,mixed>|null
     */
    public function find(string $day): ?array
    {
        if ($day === '') {
            return null;
        }

        $row = Match3DailyChallenge::where('day', $day)->find();

        return $row ? $row->toArray() : null;
    }

    /**
     * 当日挑战信息 + 我的进度（05 §B7.5）
     *
     * @return array<string,mixed>
     */
    public function info(int $userId): array
    {
        $daily = $this->ensure();

        $runs = Match3Run::where('user_id', $userId)
            ->where('mode', Match3Run::MODE_DAILY)
            ->where('daily_day', (string) $daily['day'])
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $best      = 0;
        $cleared   = false;
        $playingId = null;
        foreach ($runs as $r) {
            $best    = max($best, (int) $r['score']);
            $cleared = $cleared || ((int) $r['status'] === Match3Run::STATUS_CLEARED);
            if ((int) $r['status'] === Match3Run::STATUS_PLAYING && $playingId === null) {
                $playingId = (string) $r['run_id'];
            }
        }

        return [
            'day'         => (string) $daily['day'],
            'layers'      => (int) $daily['layers'],
            'stoneReward' => (int) $daily['stone_reward'],
            'relicPool'   => (new RelicService())->presentMany(
                (new RelicService())->byIds((array) $daily['relic_pool'])
            ),
            'myAttempts'  => count($runs),
            'myBestScore' => $best,
            'cleared'     => $cleared,
            'playingRun'  => $playingId,
        ];
    }

    /**
     * 当日固定遗物序列（从奖池确定性抽取）
     *
     * @return array<int,string>
     */
    protected function pickRelicPool(int $seed): array
    {
        $pool = (new RelicService())->pool(); // 每日挑战用全量池，保证全服一致
        if ($pool === []) {
            return [];
        }

        $taken = [];
        $out   = [];

        for ($i = 0; $i < self::RELIC_POOL_SIZE; $i++) {
            $candidates = array_values(array_filter($pool, static function ($r) use ($taken) {
                return !in_array((string) ($r['relic_id'] ?? ''), $taken, true);
            }));
            if ($candidates === []) {
                break;
            }

            $weights = [];
            foreach ($candidates as $r) {
                $weights[(string) $r['relic_id']] = max(1, (int) ($r['weight'] ?? 100));
            }

            $pickedId = (string) Match3Random::weightedPick($seed, $weights, (int) crc32('dailyPool'), $i);

            $pick = $candidates[0];
            foreach ($candidates as $r) {
                if ((string) $r['relic_id'] === $pickedId) {
                    $pick = $r;
                    break;
                }
            }

            $taken[] = (string) $pick['relic_id'];
            $out[]   = (string) $pick['relic_id'];
        }

        return $out;
    }
}
