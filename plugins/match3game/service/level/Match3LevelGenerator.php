<?php

declare(strict_types=1);

namespace plugin\match3game\service\level;

/**
 * 随机关卡内容生成器（02 §7）
 *
 * 【职责】用 (seed, D, nodeId) 生成一张完整、可玩的关卡 schema。
 *   与 Phase 3 的 MapGenerator 分工：
 *     · MapGenerator  生成"关卡树"（一张图有哪些节点）
 *     · LevelGenerator 生成"单个关卡内容"（某个节点里棋盘长什么样）
 *
 * 【三种模式】02 §7.1
 *   fixed     → 直接返回 base（策划写死的关卡）
 *   template  → 策划给 terrain + slots，程序按 seed 填内容
 *   generator → 纯参数生成（形状池 + 难度曲线）
 *
 * 【确定性】同一 (seed, D, nodeId, 配置) 永远产出同一张关卡。
 *
 * 【输出】完整 Level Schema v1（数组），Phase 3 的 NodeService 会在此基础上再应用遗物 patch。
 *
 * 【与 code/ 参考实现的差异】
 *   ① 返回 array 而非 stdClass（便于直接 json_encode 落库）
 *   ② `Match3NodeService::movesFor($D)` → `DifficultyCurve::moves($D)`（Phase 3 才引入 NodeService）
 *   ③ 去掉 scatter() 里的死代码
 *   ④ 新增 template 模式
 */
class Match3LevelGenerator
{
    /** 形状模板池（generator 模式用） */
    public const SHAPES = [
        'rect9x9' => [
            'cols'    => 9,
            'terrain' => [
                'SSSSSSSSS',
                'ooooooooo', 'ooooooooo', 'ooooooooo', 'ooooooooo',
                'ooooooooo', 'ooooooooo', 'ooooooooo', 'ooooooooo', 'ooooooooo',
            ],
        ],
        'hourglass' => [
            'cols'    => 9,
            'terrain' => [
                'SSSSSSSSS',
                'ooooooooo',
                '.ooooooo.',
                '..ooooo..',
                '...ooo...',
                '..ooooo..',
                '.ooooooo.',
                'ooooooooo',
                'ooooooooo',
                'ooooooooo',
            ],
        ],
        'cross' => [
            'cols'    => 9,
            'terrain' => [
                '....S....',
                '....o....',
                '....o....',
                'ooooooooo',
                'ooooooooo',
                'ooooooooo',
                '....o....',
                '....o....',
                '....o....',
                '....o....',
            ],
        ],
        'donut' => [
            'cols'    => 11,
            'terrain' => [
                'SSSSSSSSSSS',
                'ooooooooooo',
                'ooooooooooo',
                'ooo#####ooo',
                'ooo#...#ooo',
                'ooo#.o.#ooo',
                'ooo#...#ooo',
                'ooo#####ooo',
                'ooooooooooo',
                'ooooooooooo',
            ],
        ],
    ];

    public const SHAPE_WEIGHTS = [
        'rect9x9'   => 60,
        'hourglass' => 15,
        'cross'     => 15,
        'donut'     => 10,
    ];

    /** 覆盖物类型池与权重（02 §7.3 overlayDensity 配合 D 使用） */
    public const OVERLAY_POOL = ['ice' => 45, 'jelly' => 30, 'chocolate' => 25];

    /** 覆盖物字符映射 */
    public const OVERLAY_CHAR = ['ice' => 'I', 'jelly' => 'L', 'chocolate' => 'Q', 'poison' => 'W', 'snow' => 'K'];

    /** 目标类型池 */
    public const GOAL_POOL = ['score' => 40, 'clear_ice' => 30, 'clear_jelly' => 20, 'collect' => 10];

    /** 收集目标物（legend a-c） */
    public const COLLECT_POOL = ['bean' => 'a', 'apple' => 'b', 'jewel' => 'c'];

    /** 收集目标权重（等权） */
    public const COLLECT_WEIGHTS = ['bean' => 1, 'apple' => 1, 'jewel' => 1];

    /** 默认调色板（与元素字典 code 对齐） */
    public const GEMS = ['red_gem', 'blue_gem', 'yellow_gem', 'green_gem', 'purple_gem', 'orange_gem'];

    public const DEFAULT_CELL_SIZE = 76;

    /**
     * 统一入口：按 generation.mode 分派（02 §7.1）
     *
     * @param array  $base       基础关卡 schema（提供 palette / code 前缀 / 默认规则）
     * @param array  $generation match3_level_def.generation 列
     * @param int    $seed       服务端下发的 seed
     * @param float  $D          难度系数
     * @param string $nodeId     节点标识（如 '2-3'，仅用于命名与派生 salt）
     * @return array 完整 Level Schema v1
     */
    public function generateByConfig(array $base, array $generation, int $seed, float $D, string $nodeId): array
    {
        $mode = (string) ($generation['mode'] ?? 'fixed');

        return match ($mode) {
            'template'  => $this->generateFromTemplate($base, $generation, $seed, $D, $nodeId),
            'generator' => $this->generate($base, $seed, $D, $nodeId),
            // fixed：策划写死的关卡原样返回，只把本局 seed 钉进 random（重放需要）
            default     => array_replace($base, [
                'random' => ['seedPolicy' => 'fixed', 'seed' => $seed],
            ]),
        };
    }

    /**
     * generator 模式：纯参数生成
     *
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    public function generate(array $base, int $seed, float $D, string $nodeId): array
    {
        // 1. 选形状
        $shapeName = (string) Match3Random::weightedPick($seed, self::SHAPE_WEIGHTS, crc32('shape'));
        if (!isset(self::SHAPES[$shapeName])) {
            $shapeName = 'rect9x9';
        }
        $shape   = self::SHAPES[$shapeName];
        $terrain = $shape['terrain'];
        $cols    = (int) $shape['cols'];
        $rows    = count($terrain);

        // 2. 难度驱动参数（全部走 DifficultyCurve，三端共用同一份公式）
        $gemCount     = DifficultyCurve::gemCount($D);
        $overlayDens  = DifficultyCurve::overlayDensity($D, 0.15);
        $blockerCount = DifficultyCurve::blockerCount($D, 1.0, 1.0);

        // 3. 撒覆盖层与障碍
        [$overlay, $entity] = $this->scatter($seed, $terrain, $overlayDens, $blockerCount);

        // 4. 目标与规则
        //    目标数量这里是**临时值**，LevelGenerationService 会用 AI 探针结果反标定覆盖。
        //    但目标"类型"必须与棋盘实际内容匹配（没有冰就不能出 clear:ice 目标）。
        $gems  = $this->paletteGems($base, $gemCount);
        $goal  = $this->pickGoal($seed, $D, $gems, $this->countOverlay($overlay));
        $moves = DifficultyCurve::moves($D);

        $rules = [
            'mode'           => 'moves',
            'moves'          => $moves,
            'minMatch'       => 3,
            'goals'          => [$goal['goal']],
            'goalLogic'      => 'AND',
            'starScores'     => $goal['stars'],
            'autoShuffle'    => true,
            'loseConditions' => [['type' => 'movesExhausted']],
        ];

        return $this->assemble($base, $nodeId, 'gen', $shapeName, $terrain, $entity, $overlay, $rules, $gems, $seed);
    }

    /**
     * template 模式：策划给 terrain + slots，程序按 seed 填内容（02 §7.2）
     *
     * slots 里 rect 采用 **[c0, r0, c1, r1] 闭区间**语义。
     * fill 取值：
     *   overlay:ice1 / overlay:ice2 / overlay:jelly / overlay:snow / overlay:chocolate / overlay:poison
     *   faller:bean / faller:apple / faller:jewel
     *   blocker:stone / blocker:cage / blocker:vine
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $generation
     * @return array<string,mixed>
     */
    public function generateFromTemplate(array $base, array $generation, int $seed, float $D, string $nodeId): array
    {
        $template = (array) ($generation['template'] ?? []);
        $terrain  = array_values((array) ($template['terrain'] ?? []));
        if ($terrain === []) {
            // 模板缺 terrain → 退化为 generator 模式，保证一定有产出
            return $this->generate($base, $seed, $D, $nodeId);
        }

        $rows = count($terrain);
        $cols = strlen((string) $terrain[0]);

        // 若模板没有生成器，把第 0 行可玩格改成生成器行（否则棋盘永远补不上货）
        if (!str_contains(implode('', $terrain), 'S')) {
            $terrain[0] = str_replace('o', 'S', (string) $terrain[0]);
        }

        $overlay = array_fill(0, $rows, str_repeat('.', $cols));
        $entity  = array_fill(0, $rows, str_repeat('0', $cols));

        foreach ((array) ($template['slots'] ?? []) as $i => $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $rect    = array_values((array) ($slot['rect'] ?? []));
            $fill    = (string) ($slot['fill'] ?? '');
            $density = (float) ($slot['density'] ?? 1.0);
            if (count($rect) < 4 || $fill === '') {
                continue;
            }

            [$c0, $r0, $c1, $r1] = array_map('intval', array_slice($rect, 0, 4));
            $c0 = max(0, min($c0, $c1));
            $r0 = max(0, min($r0, $r1));
            $c1 = min($cols - 1, max($c0, $c1));
            $r1 = min($rows - 1, max($r0, $r1));

            // 收集候选格（确定性打乱后按 density 取前 N 个）
            $cells = [];
            for ($r = $r0; $r <= $r1; $r++) {
                for ($c = $c0; $c <= $c1; $c++) {
                    if (str_contains(LevelSchemaFactory::STANDABLE_TERRAIN, $terrain[$r][$c])) {
                        $cells[] = [$c, $r];
                    }
                }
            }
            if ($cells === []) {
                continue;
            }

            $pick = Match3Random::shuffle($seed, $cells, crc32('slot'), $i);
            $take = max(1, (int) round(count($pick) * DifficultyCurve::clamp($density, 0.0, 1.0)));

            [$layer, $char] = $this->resolveFill($fill);
            if ($layer === null) {
                continue;
            }

            foreach (array_slice($pick, 0, $take) as [$c, $r]) {
                if ($layer === 'overlay') {
                    $overlay[$r] = substr_replace($overlay[$r], $char, $c, 1);
                } else {
                    $entity[$r] = substr_replace($entity[$r], $char, $c, 1);
                }
            }
        }

        // 规则：模板未给规则时，复用 base 的规则（步数按 D 覆盖）
        $rules             = (array) ($template['rules'] ?? ($base['rules'] ?? []));
        $rules['mode']     = (string) ($rules['mode'] ?? 'moves');
        $rules['minMatch'] = (int) ($rules['minMatch'] ?? 3);
        if (!isset($rules['moves'])) {
            $rules['moves'] = DifficultyCurve::moves($D);
        }
        if (empty($rules['goals'])) {
            $rules['goals'] = [['type' => 'score', 'count' => (int) round(2000 * DifficultyCurve::goalScale($D))]];
        }
        if (empty($rules['starScores'])) {
            $rules['starScores'] = DifficultyCurve::stars(2000);
        }
        $rules['autoShuffle'] ??= true;

        $gemCount = (int) count((array) ($base['palette']['gems'] ?? self::GEMS));
        $gemCount = max(4, min(6, $gemCount ?: 6));
        $gems     = $this->paletteGems($base, $gemCount);

        return $this->assemble($base, $nodeId, 'tpl', 'template', $terrain, $entity, $overlay, $rules, $gems, $seed);
    }

    /* ======================================================================
     *  内部
     * ====================================================================== */

    /**
     * 统计 overlay 行里各覆盖物的数量（用于剔除不可达的 clear 目标）
     *
     * @param  array<int,string> $overlayRows
     * @return array<string,int>
     */
    protected function countOverlay(array $overlayRows): array
    {
        $counts = [];
        foreach ($overlayRows as $row) {
            foreach (str_split((string) $row) as $ch) {
                if (isset(LevelSimulator::OVERLAY_LAYER[$ch])) {
                    $layer = LevelSimulator::OVERLAY_LAYER[$ch];
                    $counts[$layer] = ($counts[$layer] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * 取调色板元素码（不足 count 时用默认池补足）
     *
     * @param  array<string,mixed> $base
     * @return array<int,string>
     */
    protected function paletteGems(array $base, int $count): array
    {
        $count = max(1, $count);

        $gems = array_values(array_filter(
            array_slice((array) ($base['palette']['gems'] ?? []), 0, $count),
            'is_string'
        ));

        foreach (self::GEMS as $g) {
            if (count($gems) >= $count) {
                break;
            }
            if (!in_array($g, $gems, true)) {
                $gems[] = $g;
            }
        }

        return array_slice($gems, 0, $count);
    }

    /**
     * fill 说明 → [层, 字符]
     *
     * @return array{0: ?string, 1: string}
     */
    protected function resolveFill(string $fill): array
    {
        if (str_starts_with($fill, 'overlay:')) {
            $key = substr($fill, 8);
            // ice1 / ice2 / jelly / snow / chocolate / poison
            $char = match ($key) {
                'ice1'      => 'I',
                'ice2'      => 'J',
                'jelly'     => 'L',
                'snow'      => 'K',
                'chocolate' => 'Q',
                'poison'    => 'W',
                default     => 'I',
            };
            return ['overlay', $char];
        }

        if (str_starts_with($fill, 'faller:')) {
            $key  = substr($fill, 7);
            $char = self::COLLECT_POOL[$key] ?? 'a';
            return ['entity', $char];
        }

        if (str_starts_with($fill, 'blocker:')) {
            $key  = substr($fill, 8);
            $char = match ($key) {
                'stone' => 'X',
                'cage'  => 'C',
                'vine'  => 'G',
                default => 'X',
            };
            return ['entity', $char];
        }

        return [null, ''];
    }

    /**
     * 撒覆盖层与障碍（确定性）
     *
     * @param  array<int,string> $terrain
     * @return array{0: array<int,string>, 1: array<int,string>} [overlay, entity]
     */
    protected function scatter(int $seed, array $terrain, float $density, int $blockerCount): array
    {
        $rows = count($terrain);
        $cols = strlen((string) $terrain[0]);

        // 候选格：不含生成器行 r=0、不含空洞 . / 生成器 S / 障碍 #
        $candidates = [];
        for ($r = 1; $r < $rows; $r++) {
            $row = (string) $terrain[$r];
            for ($c = 0; $c < $cols; $c++) {
                if ($row[$c] === 'o') {
                    $candidates[] = [$c, $r];
                }
            }
        }

        $shuffled      = Match3Random::shuffle($seed, $candidates, crc32('scatter'));
        $overlayCount  = (int) round(count($shuffled) * $density);
        $overlayCells  = array_slice($shuffled, 0, $overlayCount);
        $rest          = array_slice($shuffled, $overlayCount);
        $blockers      = array_slice($rest, 0, min($blockerCount, count($rest)));

        $overlay = array_fill(0, $rows, str_repeat('.', $cols));
        $entity  = array_fill(0, $rows, str_repeat('0', $cols));

        foreach ($overlayCells as [$c, $r]) {
            $type        = (string) Match3Random::weightedPick($seed, self::OVERLAY_POOL, $c, $r);
            $char        = self::OVERLAY_CHAR[$type] ?? 'I';
            $overlay[$r] = substr_replace($overlay[$r], $char, $c, 1);
        }

        foreach ($blockers as [$c, $r]) {
            $entity[$r] = substr_replace($entity[$r], 'X', $c, 1);
        }

        return [$overlay, $entity];
    }

    /**
     * 生成目标与三星阈值
     *
     * @return array{goal: array<string,mixed>, stars: array<int,int>}
     */
    protected function pickGoal(int $seed, float $D, array $gems, array $overlayCounts = []): array
    {
        $type = (string) Match3Random::weightedPick($seed, self::GOAL_POOL, crc32('goal'));

        // 棋盘上没有对应覆盖物 → 该目标不可达成，退回分数目标
        if ($type === 'clear_ice' && empty($overlayCounts['ice'])) {
            $type = 'score';
        }
        if ($type === 'clear_jelly' && empty($overlayCounts['jelly'])) {
            $type = 'score';
        }

        $S = DifficultyCurve::goalScale($D);

        // collect 目标用「收集指定颜色元素 N 个」语义（target = 元素字典 code），
        // 与 01 §4.1 的 goals[].target 一致，且客户端/模拟器都能直接统计。
        $collectTarget = $gems !== []
            ? $gems[(int) floor(Match3Random::next($seed, crc32('ct')) * count($gems)) % count($gems)]
            : 'red_gem';

        return match ($type) {
            'clear_ice' => [
                'goal'  => ['type' => 'clear', 'layer' => 'ice', 'count' => (int) round(18 * $S)],
                'stars' => DifficultyCurve::stars((int) round(1500 * $S)),
            ],
            'clear_jelly' => [
                'goal'  => ['type' => 'clear', 'layer' => 'jelly', 'count' => (int) round(12 * $S)],
                'stars' => DifficultyCurve::stars((int) round(1600 * $S)),
            ],
            'collect' => [
                'goal'  => [
                    'type'   => 'collect',
                    'target' => (string) $collectTarget,
                    'count'  => (int) round(20 * $S),
                ],
                'stars' => DifficultyCurve::stars((int) round(1800 * $S)),
            ],
            default => [
                'goal'  => ['type' => 'score', 'count' => (int) round(2000 * $S)],
                'stars' => DifficultyCurve::stars((int) round(2000 * $S)),
            ],
        };
    }

    /**
     * 组装完整 Level Schema v1
     *
     * @param  array<int,string> $terrain
     * @param  array<int,string> $entity
     * @param  array<int,string> $overlay
     * @return array<string,mixed>
     */
    protected function assemble(
        array $base,
        string $nodeId,
        string $codePrefix,
        string $shapeName,
        array $terrain,
        array $entity,
        array $overlay,
        array $rules,
        array $gems,
        int $seed
    ): array {
        $rows = count($terrain);
        $cols = strlen((string) $terrain[0]);

        if ($gems === []) {
            $gems = array_slice(self::GEMS, 0, 4);
        }

        return [
            'schema'   => 'match3/level@1',
            'code'     => strtoupper($codePrefix) . '-' . $shapeName . '-' . $nodeId,
            'name'     => '秘境·' . $shapeName . '·' . $nodeId,
            'chapter'  => 'roguelike',
            'version'  => 1,
            'board'    => [
                'cols'     => $cols,
                'rows'     => $rows,
                'cellSize' => self::DEFAULT_CELL_SIZE,
                'origin'   => ['c' => 0, 'r' => 0],
            ],
            'palette'  => [
                'gems'  => $gems,
                'theme' => $base['palette']['theme'] ?? 'orchard',
            ],
            'regions'  => [[
                'id'      => 'main',
                'origin'  => ['c' => 0, 'r' => 0],
                'gravity' => 'down',
                'layers'  => [
                    'terrain' => array_values($terrain),
                    'entity'  => array_values($entity),
                    'overlay' => array_values($overlay),
                ],
                'spawn'   => [
                    'mode'                => 'top',
                    'weights'             => array_fill(0, count($gems), 1),
                    'excludeInitialMatch' => true,
                ],
            ]],
            'rules'    => $rules,
            'random'   => [
                'seedPolicy' => 'fixed',
                'seed'       => $seed,
            ],
        ];
    }
}
