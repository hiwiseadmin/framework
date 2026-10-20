<?php
/**
 * 水管工通关验证器 — BFS 水流模拟
 *
 * 核心算法: 使用广度优先搜索模拟水流，判断入口到出口是否连通。
 * 额外的通关条件: 步数 ≤ max_steps、时间 ≤ time_limit。
 *
 * 评分公式:
 *   baseScore = 1000
 *   timeBonus = (timeLimit - actualTime) × 10
 *   stepBonus = (maxSteps - actualSteps) × 20
 *   score = baseScore + max(timeBonus, 0) + max(stepBonus, 0)
 *
 * 星级评定:
 *   score >= 7000 → 3 星
 *   score >= 4000 → 2 星
 *   score >= 1     → 1 星
 */
namespace plugin\pipe_game\validator;

use wise\game\stage\contract\StageValidatorInterface;
use wise\game\stage\contract\StageResult;
use wise\game\stage\contract\StageProviderInterface;

class PipeValidator implements StageValidatorInterface
{
    /** @var StageProviderInterface 本方 Provider（通过构造函数注入） */
    protected StageProviderInterface $provider;

    public function __construct(StageProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    public function validate(int $userId, string $stageCode, $submission): StageResult
    {
        // 1. 获取关卡定义（含 extra: grid_size/pipe_layout/entrance/exit/limits）
        $stage  = $this->provider->getDefinition($stageCode);
        $extra  = $stage['extra'] ?? [];
        $layout = $submission['pipe_layout'] ?? [];
        $time   = (int) ($submission['time'] ?? 0);
        $steps  = (int) ($submission['steps'] ?? 0);

        // 2. 输入校验
        if (empty($layout)) {
            return StageResult::fail('empty_layout', '未提交管道布局');
        }

        // 3. 棋盘尺寸校验
        $gridSize = $extra['grid_size'] ?? [0, 0];
        if (count($layout) !== $gridSize[0] || (count($layout) > 0 && count($layout[0]) !== $gridSize[1])) {
            return StageResult::fail('grid_mismatch', '管道布局尺寸与关卡定义不匹配');
        }

        // 4. BFS 模拟水流
        $entrance = $extra['entrance'] ?? null;
        $exit     = $extra['exit'] ?? null;
        if (!$entrance || !$exit) {
            return StageResult::fail('invalid_stage', '关卡入口或出口未定义');
        }

        $connected = $this->bfsFlow($layout, $entrance, $exit);
        if (!$connected) {
            return StageResult::fail('not_connected', '水流未到达出口');
        }

        // 5. 步数限制检查
        $maxSteps = (int) ($extra['max_steps'] ?? 99);
        if ($steps > $maxSteps) {
            return StageResult::fail(
                'step_exceeded',
                "步数 {$steps} 超过限制 {$maxSteps}"
            );
        }

        // 6. 时间限制检查
        $timeLimit = (int) ($extra['time_limit'] ?? 300);
        if ($time > $timeLimit) {
            return StageResult::fail(
                'timeout',
                "时间 {$time}秒 超过限制 {$timeLimit}秒"
            );
        }

        // 7. 计算分数和星级
        $score = $this->calcScore($time, $steps, $maxSteps, $timeLimit);
        $stars = $this->calcStars($score);

        return StageResult::pass(
            [
                'stars' => $stars,
                'score' => $score,
                'steps' => $steps,
                'time'  => $time,
            ],
            [
                ['item_id' => 'coins', 'count' => $stars * 50],
                ['item_id' => 'exp', 'count' => $score],
            ]
        );
    }

    // ===================== 核心算法 =====================

    /**
     * BFS 水流模拟
     *
     * 从入口出发，走管道连通的路（layout[r][c] === 1），
     * 判断是否能到达出口。
     *
     * @param  array $layout   管道布局矩阵 (0=空地, 1=管道)
     * @param  array $entrance [row, col] 入口坐标
     * @param  array $exit     [row, col] 出口坐标
     * @return bool 是否连通
     */
    protected function bfsFlow(array $layout, array $entrance, array $exit): bool
    {
        $rows = count($layout);
        $cols = $rows > 0 ? count($layout[0]) : 0;

        if ($rows === 0 || $cols === 0) {
            return false;
        }

        $visited = array_fill(0, $rows, array_fill(0, $cols, false));

        $queue = new \SplQueue();
        $queue->enqueue($entrance);
        $visited[$entrance[0]][$entrance[1]] = true;

        // 四方向: 上、下、左、右
        $directions = [[-1, 0], [1, 0], [0, -1], [0, 1]];

        while (!$queue->isEmpty()) {
            [$r, $c] = $queue->dequeue();

            // 到达出口
            if ($r === $exit[0] && $c === $exit[1]) {
                return true;
            }

            foreach ($directions as [$dr, $dc]) {
                $nr = $r + $dr;
                $nc = $c + $dc;

                if ($nr >= 0 && $nr < $rows
                    && $nc >= 0 && $nc < $cols
                    && !$visited[$nr][$nc]
                    && ($layout[$nr][$nc] ?? 0) === 1) {
                    $visited[$nr][$nc] = true;
                    $queue->enqueue([$nr, $nc]);
                }
            }
        }

        return false;
    }

    /**
     * 计分公式
     *
     * @param  int $time      实际用时（秒）
     * @param  int $steps     实际步数
     * @param  int $maxSteps  最大步数限制
     * @param  int $timeLimit 时间限制（秒）
     * @return int
     */
    protected function calcScore(int $time, int $steps, int $maxSteps, int $timeLimit): int
    {
        $baseScore = 1000;
        $timeBonus = max(0, ($timeLimit - $time) * 10);
        $stepBonus = max(0, ($maxSteps - $steps) * 20);

        return $baseScore + $timeBonus + $stepBonus;
    }

    /**
     * 星级评定
     *
     * @param  int $score 最终得分
     * @return int 1-3 星
     */
    protected function calcStars(int $score): int
    {
        if ($score >= 7000) {
            return 3;
        }
        if ($score >= 4000) {
            return 2;
        }
        return 1;
    }
}
