<?php

declare(strict_types=1);

namespace plugin\match3game\service\level;

use wise\support\context\ContextualLogger;

/**
 * 随机关卡生成编排（02 §7.4 + §7.5）
 *
 * ┌ 1. generate(seed, D, nodeId)                      生成（generator / template）
 * ├ 2. LevelValidator 校验                            死格/重叠/字符合法/目标可达
 * ├ 3. **探针模拟**（目标设为不可能达成）                跑满所有步数 → 得到该棋盘的"发挥上限"
 * ├ 4. **目标反标定**                                 用发挥上限 × 难度比值 反推目标数量与三星阈值
 * ├ 5. LevelValidator 复校验                          目标/星阈变更后必须重新合法
 * ├ 6. 正式体检模拟                                   得到真实通关率
 * ├ 7. 通关率落在 passRateRange → 采用
 * ├ 8. 不达标 → 换 seed 重生成（派生 seed，可复现）
 * └ 9. 仍不达标 → 降级为最保守参数（D=1 + 纯 generator）
 *
 * 【为什么必须反标定（重要设计决策）】
 *   02 §7.3 让目标数量随 D 线性增长，但步数随 D 下降（25 → 16）。
 *   两者相除会让"每步需要的产出"涨 3 倍以上，而 AI（以及普通玩家）的实际产出
 *   只随步数与颜色数变化 —— 结果就是高 D 关卡通关率必然趋近 0。
 *   实测：目标直接取 2000×D 时，D=1 ~18%、D≥1.45 全为 0%。
 *
 *   因此这里改用「以 AI 发挥上限为基准的比值法」：
 *       score 目标 = avgScore(探针) × scoreGoalRatio(D)
 *       collect 目标 = avgCollected(探针) × collectGoalRatio(D)
 *       clear 目标 = 棋盘实际覆盖物数量 × clearGoalRatio(D)
 *   实测比值 → 通关率（D=1.0 / D=1.85）：0.85→0.83/0.70、1.00→0.50/0.44、1.15→0.26/0.22
 *   → 取 ratio 0.90(D=1) 线性升到 1.12(D=3) 可稳定落在 40%~75%。
 *
 * 【为什么用"探针"而不是直接标定】
 *   若先给一个简单目标，AI 会提前通关，拿到的 avgScore 偏低 → 标定失败。
 *   把目标设成不可能达成，AI 才会跑满全部步数，得到的才是真实发挥上限。
 */
class LevelGenerationService
{
    /** 目标定为不可能达成 → 逼 AI 跑满全部步数（探针用） */
    protected const PROBE_GOAL_COUNT = 1073741824; // 1<<30

    protected Match3LevelGenerator $generator;

    protected LevelSimulator $simulator;

    /** 通关率合理区间（05 §B8.1 第 6 条） */
    protected array $passRateRange = [0.35, 0.85];

    /** 换 seed 重试次数 */
    protected int $maxRetries = 5;

    /** 每次模拟次数 */
    protected int $simTries = 20;

    /** 目标反标定比值配置（base + per_d×ΔD，夹在 [min,max]） */
    protected array $scoreRatio   = ['base' => 0.90, 'per_d' => 0.08, 'min' => 0.85, 'max' => 1.15];
    protected array $collectRatio = ['base' => 0.95, 'per_d' => 0.10, 'min' => 0.85, 'max' => 1.30];
    protected array $clearRatio   = ['base' => 0.55, 'per_d' => 0.12, 'min' => 0.50, 'max' => 0.95];

    public function __construct(
        ?Match3LevelGenerator $generator = null,
        ?LevelSimulator $simulator = null,
        array $config = []
    ) {
        $this->generator = $generator ?? new Match3LevelGenerator();
        $this->simulator = $simulator ?? new LevelSimulator();

        $this->passRateRange = (array) ($config['pass_rate_range'] ?? $this->passRateRange);
        $this->maxRetries    = (int) ($config['max_retries'] ?? $this->maxRetries);
        $this->simTries      = (int) ($config['sim_tries'] ?? $this->simTries);

        foreach (['score' => 'scoreRatio', 'collect' => 'collectRatio', 'clear' => 'clearRatio'] as $key => $prop) {
            $cfg = $config["{$key}_goal_ratio"] ?? null;
            if (is_array($cfg)) {
                $this->{$prop} = array_merge($this->{$prop}, $cfg);
            }
        }
    }

    /**
     * 生成一张通过校验与通关率体检的关卡
     *
     * @param  array<string,mixed> $base       基础 schema（提供 palette / 默认规则）
     * @param  array<string,mixed> $generation match3_level_def.generation 配置
     * @param  int                 $seed       服务端 seed
     * @param  float               $D          难度系数（DifficultyCurve::D()）
     * @param  string              $nodeId     节点标识
     * @return array{
     *   schema: array, seed: int, attempts: int, degraded: bool,
     *   goalSource: string, goalCounts: array<string,int>, probing: array,
     *   quality: array, errors: array<int,string>
     * }
     */
    public function generate(array $base, array $generation, int $seed, float $D, string $nodeId): array
    {
        [$lo, $hi] = $this->range();
        $mid       = ($lo + $hi) / 2;

        $lastErrors = [];
        $fallback   = null;
        $attempts   = 0;

        for ($i = 0; $i <= $this->maxRetries; $i++) {
            $attempts++;
            $genSeed = $i === 0 ? $seed : Match3Random::deriveSeed($seed, $i, crc32('reseed'));

            $schema = $this->generator->generateByConfig($base, $generation, $genSeed, $D, $nodeId);

            $errors = LevelValidator::validate($schema);
            if ($errors !== []) {
                $lastErrors = $errors;
                continue;
            }

            // 探针 → 反标定 → 复校验 → 正式体检
            $built = $this->calibrate($schema, $genSeed, $D);
            $schema = $built['schema'];

            $errors = LevelValidator::validate($schema);
            if ($errors !== []) {
                $lastErrors = $errors;
                continue;
            }

            $quality = $this->simulator->simulate($schema, $genSeed, $this->simTries);
            $rate    = (float) $quality['passRate'];

            $payload = [
                'schema'     => $schema,
                'seed'       => $genSeed,
                'attempts'   => $attempts,
                'degraded'   => false,
                'goalSource' => $built['goalSource'],
                'goalCounts' => $built['goalCounts'],
                'probing'    => $built['probing'],
                'quality'    => $quality,
                'errors'     => [],
            ];

            if ($rate >= $lo && $rate <= $hi) {
                return $payload;
            }

            $lastErrors = [sprintf('通关率 %.2f 不在 [%.2f, %.2f]', $rate, $lo, $hi)];
            $payload['errors']   = $lastErrors;
            $payload['degraded'] = true;

            // 记下"最接近目标难度"的一次作为备选
            if ($fallback === null || abs($rate - $mid) < abs((float) $fallback['quality']['passRate'] - $mid)) {
                $fallback = $payload;
            }
        }

        ContextualLogger::warning('[match3] 随机关卡通关率体检未通过，降级为保守参数', [
            'node'   => $nodeId,
            'seed'   => $seed,
            'errors' => $lastErrors,
        ]);

        // 降级：D=1 + 纯 generator（颜色最多、覆盖层最少），保证一定可玩
        $degradeSeed = Match3Random::deriveSeed($seed, crc32('degrade'));
        $schema      = $this->generator->generateByConfig($base, ['mode' => 'generator'], $degradeSeed, 1.0, $nodeId);

        $errors = LevelValidator::validate($schema);
        if ($errors === []) {
            $built  = $this->calibrate($schema, $degradeSeed, 1.0);
            $schema = $built['schema'];

            $errors = LevelValidator::validate($schema);
            if ($errors === []) {
                return [
                    'schema'     => $schema,
                    'seed'       => $degradeSeed,
                    'attempts'   => $attempts + 1,
                    'degraded'   => true,
                    'goalSource' => $built['goalSource'],
                    'goalCounts' => $built['goalCounts'],
                    'probing'    => $built['probing'],
                    'quality'    => $this->simulator->simulate($schema, $degradeSeed, $this->simTries),
                    'errors'     => $lastErrors,
                ];
            }
        }

        // 连降级都校验不过：返回最接近的备选
        if ($fallback !== null) {
            return $fallback;
        }

        return [
            'schema'     => [],
            'seed'       => $degradeSeed,
            'attempts'   => $attempts + 1,
            'degraded'   => true,
            'goalSource' => 'none',
            'goalCounts' => [],
            'probing'    => [],
            'quality'    => ['passRate' => 0.0, 'wins' => 0, 'tries' => 0, 'avgMoves' => 0.0, 'avgScore' => 0],
            'errors'     => array_merge($lastErrors, $errors),
        ];
    }

    /**
     * 关卡体检（后台发布前 / AI 模拟按钮）
     *
     * @return array{valid: bool, errors: array<int,string>, passRate: float, wins: int, tries: int, avgMoves: float, avgScore: int, hasValidMove: bool}
     */
    public function inspect(array $schema, int $seed, ?int $tries = null): array
    {
        $tries  = $tries ?? $this->simTries;
        $errors = LevelValidator::validate($schema);

        if ($errors !== []) {
            return [
                'valid'        => false,
                'errors'       => $errors,
                'passRate'     => 0.0,
                'wins'         => 0,
                'tries'        => 0,
                'avgMoves'     => 0.0,
                'avgScore'     => 0,
                'hasValidMove' => false,
            ];
        }

        $quality = $this->simulator->simulate($schema, $seed, $tries);

        return array_merge([
            'valid'        => true,
            'errors'       => [],
            'hasValidMove' => $this->simulator->hasValidMove($schema, $seed),
        ], $quality);
    }

    /* ======================================================================
     *  目标反标定
     * ====================================================================== */

    /**
     * 探针模拟 → 反标定目标 → 返回新 schema
     *
     * @return array{schema: array, goalSource: string, goalCounts: array<string,int>, probing: array}
     */
    public function calibrate(array $schema, int $seed, float $D): array
    {
        // 先规范化：保证 rules.specials / board / regions 结构完整，
        // 否则后续遗物 patch 的 replace 会命中不存在的路径而抛异常
        $schema = LevelSchemaFactory::normalize($schema);

        $rules = (array) ($schema['rules'] ?? []);
        $goals = (array) ($rules['goals'] ?? []);

        // 1. 探针：把目标改成不可能达成，逼 AI 跑满全部步数
        $probe = $schema;
        $probe['rules']['goals'] = [['type' => 'score', 'count' => self::PROBE_GOAL_COUNT]];
        $probing = $this->simulator->simulate($probe, $seed, $this->simTries);

        // 2. 覆盖物实际数量（clear 目标的上限）
        $overlayCounts = self::overlayCounts($schema);

        // 3. 逐个标定
        $goalCounts = [];
        foreach ($goals as $i => $g) {
            if (!is_array($g)) {
                continue;
            }

            $type = (string) ($g['type'] ?? '');
            $count = 0;

            if ($type === 'score') {
                $count = (int) round(((int) ($probing['avgScore'] ?? 0)) * $this->ratio($this->scoreRatio, $D));
            } elseif ($type === 'collect') {
                $key   = (string) ($g['target'] ?? '');
                $count = (int) round(((float) ($probing['avgCollected'][$key] ?? 0)) * $this->ratio($this->collectRatio, $D));
            } elseif ($type === 'clear') {
                $key   = (string) ($g['layer'] ?? '');
                $count = (int) round(($overlayCounts[$key] ?? 0) * $this->ratio($this->clearRatio, $D));
            } else {
                continue; // 其他目标类型暂不反标定
            }

            $count = max(1, $count);
            $goals[$i]['count'] = $count;
            $goalCounts[$i]     = $count;
        }

        $schema['rules']['goals'] = array_values($goals);

        // 4. 有 score 目标时，三星阈值跟随目标（1× / 1.5× / 2.2×）
        $scoreTarget = 0;
        foreach ($schema['rules']['goals'] as $g) {
            if (is_array($g) && ($g['type'] ?? '') === 'score') {
                $scoreTarget = (int) ($g['count'] ?? 0);
                break;
            }
        }
        if ($scoreTarget > 0) {
            $schema['rules']['starScores'] = [
                $scoreTarget,
                (int) round($scoreTarget * 1.5),
                (int) round($scoreTarget * 2.2),
            ];
        }

        return [
            'schema'     => $schema,
            'goalSource' => 'calibrated',
            'goalCounts' => $goalCounts,
            'probing'    => [
                'avgScore'     => (int) ($probing['avgScore'] ?? 0),
                'avgCollected' => (array) ($probing['avgCollected'] ?? []),
                'avgCleared'   => (array) ($probing['avgCleared'] ?? []),
                'overlay'      => $overlayCounts,
            ],
        ];
    }

    /**
     * 统计棋盘上各类覆盖物的数量（clear 目标上限）
     *
     * @return array<string,int> {ice: 12, jelly: 8, ...}
     */
    public static function overlayCounts(array $schema): array
    {
        $counts = [];

        foreach ((array) ($schema['regions'] ?? []) as $rg) {
            foreach ((array) ($rg['layers']['overlay'] ?? []) as $row) {
                foreach (str_split((string) $row) as $ch) {
                    if (isset(LevelSimulator::OVERLAY_LAYER[$ch])) {
                        $layer = LevelSimulator::OVERLAY_LAYER[$ch];
                        $counts[$layer] = ($counts[$layer] ?? 0) + 1;
                    }
                }
            }
        }

        return $counts;
    }

    /**
     * ratio = clamp(base + per_d × (D - 1), min, max)
     */
    protected function ratio(array $cfg, float $D): float
    {
        $v = (float) ($cfg['base'] ?? 1.0) + (float) ($cfg['per_d'] ?? 0.0) * (max(0.0, $D) - 1.0);

        return max((float) ($cfg['min'] ?? 0.0), min((float) ($cfg['max'] ?? 99.0), $v));
    }

    /**
     * @return array{0:float,1:float}
     */
    protected function range(): array
    {
        $lo = (float) ($this->passRateRange[0] ?? 0.35);
        $hi = (float) ($this->passRateRange[1] ?? 0.85);

        return $lo <= $hi ? [$lo, $hi] : [$hi, $lo];
    }
}
