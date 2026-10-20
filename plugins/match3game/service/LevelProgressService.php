<?php

declare(strict_types=1);

namespace plugin\match3game\service;

use plugin\match3game\contract\Match3Constants;
use plugin\match3game\model\Match3LevelDef;
use plugin\match3game\model\Match3PlayerProgress;

/**
 * 关卡解锁判定 / 进度聚合（文档 §2.2 判断 C / §5.5 / §6.3 map）
 *
 * 逐关解锁由插件实现（不依赖 wise-game-stage）：
 *   - pre_unlock：前置关卡 code
 *   - unlock_stars：解锁所需前置关星数，**默认 1 = 前置关通关（≥1 星）即解锁**
 *     （写 0 也等价，因为下面已要求 `best_stars >= 1`；DATABASE 与 SEED 默认值统一用 1）
 *
 * 读模型 match3_player_progress（聚合表），高频地图接口不反解 stage JSON。
 */
class LevelProgressService
{
    /** 关卡定义模型类 */
    protected string $levelModel = Match3LevelDef::class;

    /** 玩家聚合模型类 */
    protected string $progressModel = Match3PlayerProgress::class;

    /**
     * `unlock_stars` 的默认值：1 = 前置关通关即解锁
     *
     * 与 `Match3StageSeeder::DEFAULT_UNLOCK_STARS` 保持一致（那儿是 protected，此处是唯一出口）。
     */
    public const DEFAULT_UNLOCK_STARS = 1;

    // ================================================================
    //  纯逻辑（可单测，不依赖 DB）
    // ================================================================

    /**
     * 判断某关是否解锁（纯函数）
     *
     * @param array $level       关卡定义数组（含 pre_unlock / unlock_stars）
     * @param array $progressMap 玩家聚合进度 [level_code => ['best_stars'=>int, ...]]
     */
    public function isUnlocked(array $level, array $progressMap): bool
    {
        // 关卡停用一律视为未解锁（不可玩）
        if ((int) ($level['status'] ?? 1) !== 1) {
            return false;
        }

        $preUnlock = $level['pre_unlock'] ?? null;
        if (empty($preUnlock)) {
            return true; // 无前置关 → 解锁
        }

        $pre = $progressMap[$preUnlock] ?? null;
        if ($pre === null) {
            return false; // 前置关从未游玩
        }

        $preStars = (int) ($pre['best_stars'] ?? 0);
        if ($preStars <= 0) {
            return false; // 前置关未通关
        }

        $unlockStars = (int) ($level['unlock_stars'] ?? self::DEFAULT_UNLOCK_STARS);
        if ($unlockStars > 0 && $preStars < $unlockStars) {
            return false; // 前置关星数不足
        }

        return true;
    }

    /**
     * 计算当前关（第一个未通关的关；全通返回最后一关）
     *
     * @param array $levels      按 sort_order 排序的关卡定义列表
     * @param array $progressMap 玩家聚合进度
     */
    public function resolveCurrentLevel(array $levels, array $progressMap): ?string
    {
        foreach ($levels as $level) {
            if ((int) ($level['status'] ?? 1) !== 1) {
                continue;
            }
            $code   = $level['code'];
            $stars  = (int) ($progressMap[$code]['best_stars'] ?? 0);
            if ($stars <= 0) {
                return $code;
            }
        }

        // 全部通关 → 最后一关
        $last = null;
        foreach ($levels as $level) {
            if ((int) ($level['status'] ?? 1) === 1) {
                $last = $level['code'];
            }
        }
        return $last;
    }

    // ================================================================
    //  数据访问（依赖 DB）
    // ================================================================

    /**
     * 加载玩家某游戏的全部聚合进度
     *
     * @return array<string, array> [level_code => ['best_stars'=>int, 'best_score'=>int, 'play_count'=>int, ...]]
     */
    public function loadProgressMap(int $userId): array
    {
        $rows = $this->progressModel::where('user_id', $userId)->select();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->level_code] = [
                'best_stars'    => (int) $row->best_stars,
                'best_score'    => (int) $row->best_score,
                'play_count'    => (int) $row->play_count,
                'first_pass_at' => $row->first_pass_at,
                'last_play_at'  => $row->last_play_at,
            ];
        }

        return $map;
    }

    /**
     * 判断某关是否已解锁（数据库版，供服务层使用）
     */
    public function checkUnlocked(int $userId, string $levelCode): bool
    {
        $level = $this->levelModel::where('code', $levelCode)->find();
        if (!$level) {
            return false;
        }

        return $this->isUnlocked($level->toArray(), $this->loadProgressMap($userId));
    }

    /**
     * 通关后更新聚合进度（best_stars/best_score/play_count/first_pass_at）
     *
     * 仅在新成绩更优（星数或分数）时更新最佳记录。
     */
    public function recordPass(int $userId, string $levelCode, int $stars, int $score): array
    {
        /** @var Match3PlayerProgress|null $row */
        $row = $this->progressModel::where('user_id', $userId)
            ->where('level_code', $levelCode)
            ->find();

        $now = date('Y-m-d H:i:s');

        if (!$row) {
            $row = new $this->progressModel();
            $row->user_id       = $userId;
            $row->level_code    = $levelCode;
            $row->best_stars    = $stars;
            $row->best_score    = $score;
            $row->play_count    = 1;
            $row->first_pass_at = $now;
            $row->last_play_at  = $now;
            $row->save();
        } else {
            $improved = $stars > (int) $row->best_stars
                || ($stars === (int) $row->best_stars && $score > (int) $row->best_score);

            if ($improved) {
                $row->best_stars = max((int) $row->best_stars, $stars);
                $row->best_score = max((int) $row->best_score, $score);
            }
            if ((int) $row->best_stars === 0) {
                // 首次通关
                $row->best_stars    = $stars;
                $row->best_score    = max((int) $row->best_score, $score);
                $row->first_pass_at = $row->first_pass_at ?: $now;
            }
            $row->play_count   = (int) $row->play_count + 1;
            $row->last_play_at = $now;
            $row->save();
        }

        return [
            'level_code' => $levelCode,
            'best_stars' => (int) $row->best_stars,
            'best_score' => (int) $row->best_score,
            'play_count' => (int) $row->play_count,
        ];
    }

    /**
     * 标记开始游玩（play_count+1 / last_play_at 刷新，不改变通关状态）
     */
    public function recordStart(int $userId, string $levelCode): void
    {
        $row = $this->progressModel::where('user_id', $userId)
            ->where('level_code', $levelCode)
            ->find();

        $now = date('Y-m-d H:i:s');

        if (!$row) {
            $row = new $this->progressModel();
            $row->user_id      = $userId;
            $row->level_code   = $levelCode;
            $row->best_stars   = 0;
            $row->best_score   = 0;
            $row->play_count   = 1;
            $row->last_play_at = $now;
            $row->save();
        } else {
            $row->play_count   = (int) $row->play_count + 1;
            $row->last_play_at = $now;
            $row->save();
        }
    }

    /**
     * 构建关卡地图（解锁态 + 星数 + 当前关）
     *
     * @return array {
     *     current_level: string|null,
     *     levels: array [{ code, name, chapter, difficulty, unlocked, stars, play_count, ... }]
     * }
     */
    public function getMap(int $userId): array
    {
        $levels = $this->levelModel::where('status', 1)
            ->order('sort_order', 'asc')
            ->order('code', 'asc')
            ->select();

        $progressMap = $this->loadProgressMap($userId);

        $list = [];
        foreach ($levels as $level) {
            $levelArr  = $level->toArray();
            $code      = $levelArr['code'];
            $progress  = $progressMap[$code] ?? ['best_stars' => 0, 'play_count' => 0];
            $list[]    = [
                'code'        => $code,
                'name'        => $levelArr['name'],
                'chapter'     => $levelArr['chapter'],
                'difficulty'  => (int) $levelArr['difficulty'],
                'unlocked'    => $this->isUnlocked($levelArr, $progressMap),
                'stars'       => (int) $progress['best_stars'],
                'play_count'  => (int) $progress['play_count'],
            ];
        }

        // 当前关 = 第一个已解锁但未通关的关
        $current = null;
        foreach ($list as $item) {
            if ($item['unlocked'] && $item['stars'] <= 0) {
                $current = $item['code'];
                break;
            }
        }

        return [
            'game_id'        => Match3Constants::GAME_ID,
            'current_level'  => $current,
            'levels'         => $list,
        ];
    }
}
