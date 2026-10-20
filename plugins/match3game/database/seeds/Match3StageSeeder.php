<?php

declare(strict_types=1);

use think\migration\Seeder;

/**
 * 消消乐关卡种子（Level Schema v1，01 §4 / §5）
 *
 * 12 关 / 3 章节，逐关解锁（pre_unlock 链）。
 * `unlock_stars` 默认 1 = 前置关通关即解锁（`DEFAULT_UNLOCK_STARS`），逐关连锁不需要逐个写。
 * 关卡 = board + regions（三层字符地图）+ rules（mode/moves/goals/starScores）。
 *
 * 标准矩形布局（01 §5.1）：terrain 第 0 行是生成器行 S，其余为可玩格 o；
 * entity 留空 = 由 seed 随机填充；overlay 按需铺冰/果冻/雪块。
 */
class Match3StageSeeder extends Seeder
{
    /** 调色板：6 色基础元素（schema.palette.gems） */
    protected const GEMS = ['red_gem', 'blue_gem', 'yellow_gem', 'green_gem', 'purple_gem', 'orange_gem'];

    /** 5 色权重（第 6 色权重 0 = 不出） */
    protected const W5 = [20, 20, 20, 20, 20, 0];

    /** 6 色权重 */
    protected const W6 = [20, 20, 20, 20, 20, 15];

    /**
     * 解锁所需前置关星数的**默认值**：1 = 前置关通关（≥1 星）即解锁
     *
     * 【为什么是 1 而不是 0】`LevelProgressService::isUnlocked()` 已要求前置关 `best_stars >= 1`
     * （即必须通关），所以 0 与 1 **判定结果完全等价**；写成 1 是为了让"默认能进下一关"这件事
     * 在数据上看得出来，不必去翻 0 的特殊含义。
     * 需要卡进度的关卡（例如"这关必须打到 2 星才放行"）**显式传参**并在行尾注明原因。
     */
    protected const DEFAULT_UNLOCK_STARS = 1;

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [];
        foreach ($this->buildLevels() as $l) {
            $l['regions']     = json_encode($l['regions'], JSON_UNESCAPED_UNICODE);
            $l['rules']       = json_encode($l['rules'], JSON_UNESCAPED_UNICODE);
            $l['palette']     = json_encode($l['palette'], JSON_UNESCAPED_UNICODE);
            $l['reward_conf'] = json_encode($l['reward_conf'], JSON_UNESCAPED_UNICODE);
            $l['create_time']  = $now;
            $l['update_time']  = $now;
            $rows[] = $l;
        }

        $this->table('match3_level_def')->insert($rows)->save();
    }

    /**
     * 构造 12 个关卡（Level Schema v1）
     */
    protected function buildLevels(): array
    {
        $levels = [];

        // ===================== 第一章：入门（9×9，5 色） =====================
        // unlock_stars：一律走默认（1 = 通关即解锁），本章不卡星
        $levels[] = $this->level(
            'M3-0101', '初识三消', '第一章·入门', 1, 9, 9, self::W5, [], 30,
            [['type' => 'score', 'count' => 2000]], [2000, 4000, 6000],
            ['coins' => [200, 400, 600]], null, 1
        );
        $levels[] = $this->level(
            'M3-0102', '红宝石之约', '第一章·入门', 1, 9, 9, self::W5, [], 28,
            [['type' => 'collect', 'target' => 'red_gem', 'count' => 20]], [3000, 6000, 9000],
            ['coins' => [200, 400, 600]], 'M3-0101', 2
        );
        $levels[] = $this->level(
            'M3-0103', '初见寒冰', '第一章·入门', 2, 9, 9, self::W5,
            $this->ice(9, 9, 4, 2, 5, 5), 26,                       // 2 行 × 4 列 = 8 块冰
            [['type' => 'clear', 'layer' => 'ice', 'count' => 8]], [4000, 8000, 12000],
            ['coins' => [250, 450, 700]], 'M3-0102', 3
        );
        $levels[] = $this->level(
            'M3-0104', '红蓝并收', '第一章·入门', 2, 9, 9, self::W5, [], 25,
            [
                ['type' => 'collect', 'target' => 'red_gem',  'count' => 25],
                ['type' => 'collect', 'target' => 'blue_gem', 'count' => 25],
            ], [5000, 10000, 15000],
            ['coins' => [250, 450, 700]], 'M3-0103', 4
        );

        // ===================== 第二章：进阶（9×9，6 色） =====================
        $levels[] = $this->level(
            'M3-0201', '六色棋盘', '第二章·进阶', 2, 9, 9, self::W6, [], 28,
            [['type' => 'collect', 'target' => 'yellow_gem', 'count' => 30]], [6000, 12000, 18000],
            ['coins' => [300, 500, 800]], 'M3-0104', 5
        );
        $levels[] = $this->level(
            'M3-0202', '破冰行动', '第二章·进阶', 3, 9, 9, self::W6,
            $this->ice(9, 9, 3, 2, 5, 5), 26,                       // 3 行 × 4 列 = 12 块冰
            [
                ['type' => 'clear', 'layer' => 'ice', 'count' => 12],
                ['type' => 'collect', 'target' => 'green_gem', 'count' => 20],
            ], [8000, 16000, 24000],
            ['coins' => [300, 500, 800]], 'M3-0201', 6
        );
        $levels[] = $this->level(
            'M3-0203', '果冻迷阵', '第二章·进阶', 3, 9, 9, self::W6,
            $this->ice(9, 9, 3, 2, 5, 6, 'L'), 24,                  // 3 行 × 5 列 = 15 块果冻
            [['type' => 'clear', 'layer' => 'jelly', 'count' => 15]], [10000, 20000, 30000],
            ['coins' => [350, 550, 850]], 'M3-0202', 7
        );
        $levels[] = $this->level(
            'M3-0204', '三色目标', '第二章·进阶', 3, 9, 9, self::W6, [], 22,
            [
                ['type' => 'collect', 'target' => 'red_gem',    'count' => 30],
                ['type' => 'collect', 'target' => 'purple_gem', 'count' => 30],
                ['type' => 'collect', 'target' => 'orange_gem', 'count' => 25],
            ], [12000, 24000, 36000],
            // 这里原来是显式 2（要求前置关 2 星）→ 第 7 关 1 星通关后结算页把它显示成
            // "已是最后一关"（2026-10-02 真机）。现统一回默认值 1；要再卡进度就把尾参显式写成 2。
            ['coins' => [350, 550, 850]], 'M3-0203', 8
        );

        // ===================== 第三章：挑战 =====================
        $levels[] = $this->level(
            'M3-0301', '冰封王座', '第三章·挑战', 4, 9, 9, self::W6,
            $this->ice(9, 9, 2, 2, 4, 7), 24,                       // 3 行 × 6 列 = 18 块冰
            [
                ['type' => 'clear', 'layer' => 'ice', 'count' => 18],
                ['type' => 'collect', 'target' => 'blue_gem', 'count' => 25],
            ], [15000, 30000, 45000],
            ['coins' => [400, 600, 1000]], 'M3-0204', 9
        );
        $levels[] = $this->level(                                    // 9 行 × 10 列
            'M3-0302', '全员收集', '第三章·挑战', 4, 9, 10, self::W6, [], 22,
            [
                ['type' => 'collect', 'target' => 'red_gem',    'count' => 35],
                ['type' => 'collect', 'target' => 'green_gem',  'count' => 35],
                ['type' => 'collect', 'target' => 'yellow_gem', 'count' => 35],
            ], [18000, 36000, 54000],
            ['coins' => [400, 600, 1000]], 'M3-0301', 10
        );
        $levels[] = $this->level(
            'M3-0303', '雪原突围', '第三章·挑战', 5, 9, 9, self::W6,
            $this->mergeLayers(
                $this->ice(9, 9, 2, 2, 3, 7),                       // 2 行 × 6 列 = 12 块冰
                $this->ice(9, 9, 6, 2, 7, 7, 'K')                   // 2 行 × 6 列 = 12 块雪
            ), 20,
            [
                ['type' => 'clear', 'layer' => 'ice',  'count' => 12],
                ['type' => 'clear', 'layer' => 'snow', 'count' => 12],
            ], [20000, 40000, 60000],
            ['coins' => [450, 700, 1200]], 'M3-0302', 11
        );
        $levels[] = $this->level(                                    // 10 行 × 10 列
            'M3-0304', '最终试炼', '第三章·挑战', 5, 10, 10, self::W6,
            $this->ice(10, 10, 2, 2, 6, 6), 20,                      // 5 行 × 5 列 = 25 块冰
            [
                ['type' => 'collect', 'target' => 'purple_gem', 'count' => 40],
                ['type' => 'clear',   'layer' => 'ice',          'count' => 25],
            ], [25000, 50000, 75000],
            ['coins' => [500, 800, 1500]], 'M3-0303', 12
        );

        return $levels;
    }

    /**
     * 构造单个关卡行（Level Schema v1 平铺列）
     *
     * @param int   $rows       棋盘行数（含生成器行）
     * @param int   $cols       棋盘列数
     * @param array $weights    spawn.weights（6 色权重）
     * @param array $overlay    overlay 层字符地图（[] = 无覆盖）
     * @param int   $moves      步数
     * @param array $goals      rules.goals
     * @param array $starScores [一星, 二星, 三星]
     * @param array $rewardConf 插件自有奖励 {coins}
     * @param string|null $preUnlock  前置关卡 code（null = 首关，无条件开放）
     * @param int   $sortOrder  地图里的先后序号
     * @param int   $unlockStars 解锁所需前置关星数，**默认 1**（通关即解锁）；
     *                           只有刻意要卡进度的关卡才传别的值
     */
    protected function level(
        string $code,
        string $name,
        string $chapter,
        int $difficulty,
        int $rows,
        int $cols,
        array $weights,
        array $overlay,
        int $moves,
        array $goals,
        array $starScores,
        array $rewardConf,
        ?string $preUnlock,
        int $sortOrder,
        int $unlockStars = self::DEFAULT_UNLOCK_STARS
    ): array {
        return [
            'code'         => $code,
            'name'         => $name,
            'chapter'      => $chapter,
            'difficulty'   => $difficulty,
            'description'  => null,
            'schema_ver'   => 'match3/level@1',
            'version'      => 1,
            'board_rows'   => $rows,
            'board_cols'   => $cols,
            'cell_size'    => 76,
            'regions'      => [
                [
                    'id'     => 'main',
                    'origin' => ['c' => 0, 'r' => 0],
                    // 第 0 行是生成器行 S，其余为可玩格 o（01 §5.1）
                    'gravity' => 'down',
                    'layers'  => [
                        'terrain' => $this->terrain($rows, $cols),
                        'entity'  => [],   // 空 = 由 seed 随机填充
                        'overlay' => $overlay,
                    ],
                    'spawn'   => [
                        'mode'                => 'top',
                        'weights'             => $weights,
                        'excludeInitialMatch' => true,
                    ],
                ],
            ],
            'rules'        => [
                'mode'        => 'moves',
                'moves'       => $moves,
                'minMatch'    => 3,
                'starScores'  => $starScores,
                'goals'       => $goals,
                'autoShuffle' => true,
            ],
            'palette'      => [
                'gems'  => self::GEMS,
                'theme' => 'orchard',
            ],
            'seed_policy'  => 'server',
            'fixed_seed'   => null,
            'reward_conf'  => $rewardConf,
            'pre_unlock'   => $preUnlock,
            'unlock_stars' => $unlockStars,
            'sort_order'   => $sortOrder,
            'status'       => 1,
        ];
    }

    /**
     * 标准矩形 terrain：第 0 行全 S（生成器），其余全 o（可玩格）
     *
     * @return string[]
     */
    protected function terrain(int $rows, int $cols): array
    {
        $out = [str_repeat('S', $cols)];
        for ($r = 1; $r < $rows; $r++) {
            $out[] = str_repeat('o', $cols);
        }
        return $out;
    }

    /**
     * 生成 overlay 层：在 [r0..r1] × [c0..c1] 矩形内铺满指定覆盖物，其余为 '.'
     *
     * @param string $ch I=单层冰 J=双层冰 L=果冻 K=雪块
     * @return string[]
     */
    protected function ice(int $rows, int $cols, int $r0, int $c0, int $r1, int $c1, string $ch = 'I'): array
    {
        $out = [];
        for ($r = 0; $r < $rows; $r++) {
            $line = '';
            for ($c = 0; $c < $cols; $c++) {
                $line .= ($r >= $r0 && $r <= $r1 && $c >= $c0 && $c <= $c1) ? $ch : '.';
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * 合并两个 overlay 层（后者覆盖前者的非空字符）
     */
    protected function mergeLayers(array $base, array $top): array
    {
        foreach ($top as $r => $line) {
            if (!isset($base[$r])) {
                $base[$r] = $line;
                continue;
            }
            $len = strlen($line);
            for ($c = 0; $c < $len; $c++) {
                if ($line[$c] !== '.') {
                    $base[$r][$c] = $line[$c];
                }
            }
        }
        return $base;
    }
}
