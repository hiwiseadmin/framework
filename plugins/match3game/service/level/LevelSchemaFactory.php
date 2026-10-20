<?php

declare(strict_types=1);

namespace plugin\match3game\service\level;

/**
 * Level Schema v1 装配 / 拆解器
 *
 * 【为什么需要它】
 * 数据库是「按列存储」的（board_cols/rows 冗余列 + regions/portals/rules 等 JSON 列），
 * 而 Provider、Validator、Phase 2 的生成器、Phase 3 的 NodeService 需要的是
 * 「一份完整的 Level Schema v1 文档」。本类是两者之间**唯一的转换入口**，
 * 保证所有调用方拿到的 schema 结构完全一致。
 *
 * 【协议】docs/match3/01-level-layout-design.md §4
 *   schema / code / name / chapter / difficulty / version
 *   palette / board / regions[] / portals[] / rules / random / runtime
 *   + P4 扩展：behaviours / actors / props / generation（02 §9.1）
 */
class LevelSchemaFactory
{
    /** 可站立（可放元素）的 terrain 字符，用于确定性棋盘生成的掩码 */
    public const STANDABLE_TERRAIN = 'oDP><^v';

    /** 默认单元格像素 */
    public const DEFAULT_CELL_SIZE = 76;

    /**
     * 数据库行 → 完整 Level Schema v1 文档
     *
     * @param array<string,mixed> $row match3_level_def 一行（JSON 列已由模型解码为数组）
     * @return array<string,mixed>
     */
    public static function fromRow(array $row): array
    {
        $regions = self::normalizeRegions($row['regions'] ?? []);
        $board   = self::boardOf($row, $regions);

        $schema = [
            'schema'      => (string) (($row['schema_ver'] ?? '') ?: 'match3/level@1'),
            'code'        => (string) ($row['code'] ?? ''),
            'name'        => (string) ($row['name'] ?? ''),
            'chapter'     => (string) ($row['chapter'] ?? ''),
            'difficulty'  => (int) ($row['difficulty'] ?? 1),
            'version'     => (int) ($row['version'] ?? 1),
            'board'       => $board,
            'regions'     => $regions,
            'rules'       => self::normalizeRules($row['rules'] ?? []),
            'random'      => [
                'seedPolicy' => (string) (($row['seed_policy'] ?? '') ?: 'server'),
                'seed'       => self::nullableInt($row['fixed_seed'] ?? null),
            ],
        ];

        // 可选字段：仅在非空时下发，避免前端拿到一堆 null
        foreach (['portals', 'palette', 'behaviours', 'actors', 'props', 'generation'] as $key) {
            $value = $row[$key] ?? null;
            if (is_array($value) && $value !== []) {
                $schema[$key] = $value;
            } elseif (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded) && $decoded !== []) {
                    $schema[$key] = $decoded;
                }
            }
        }

        return $schema;
    }

    /**
     * 完整 Schema 文档 → 数据库列（后台保存 / Phase 2 生成器落库使用）
     *
     * @param array<string,mixed> $schema Level Schema v1
     * @param array<string,mixed> $extra  插件自有列（code/name/chapter/.../reward_conf 等）
     * @return array<string,mixed>
     */
    public static function toRow(array $schema, array $extra = []): array
    {
        $regions = self::normalizeRegions($schema['regions'] ?? []);
        $board   = $schema['board'] ?? [];

        [$rows, $cols] = self::measure($regions, (int) ($board['rows'] ?? 0), (int) ($board['cols'] ?? 0));

        $row = array_merge($extra, [
            'schema_ver' => (string) ($schema['schema'] ?: 'match3/level@1'),
            'version'    => (int) ($schema['version'] ?? 1),
            'board_rows' => $rows,
            'board_cols' => $cols,
            'cell_size'  => (int) ($board['cellSize'] ?? self::DEFAULT_CELL_SIZE),
            'regions'    => $regions,
            'rules'      => self::normalizeRules($schema['rules'] ?? []),
            'seed_policy' => (string) ($schema['random']['seedPolicy'] ?? 'server'),
            'fixed_seed'  => self::nullableInt($schema['random']['seed'] ?? null),
        ]);

        foreach (['portals', 'palette', 'behaviours', 'actors', 'props', 'generation'] as $key) {
            $value = $schema[$key] ?? null;
            $row[$key] = (is_array($value) && $value !== []) ? $value : null;
        }

        return $row;
    }

    /**
     * 规范化：补齐 board / regions / rules 的缺省字段
     *
     * 【为什么必须做】遗物 patch 会直接改 `/rules/specials/match4`、`/board/cols` 这类路径。
     * `replace` 命中不存在的路径会抛异常，所以任何进入 PatchEngine 的 schema 都必须是
     * **完整形态**（即便字段值为默认值）。生成器与 fixed 模板都要先过这里。
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    public static function normalize(array $schema): array
    {
        $regions = self::normalizeRegions($schema['regions'] ?? []);
        $board   = (array) ($schema['board'] ?? []);

        [$rows, $cols] = self::measure($regions, (int) ($board['rows'] ?? 0), (int) ($board['cols'] ?? 0));

        $schema['schema']  = (string) ($schema['schema'] ?? 'match3/level@1');
        $schema['board']   = [
            'cols'     => $cols,
            'rows'     => $rows,
            'cellSize' => (int) ($board['cellSize'] ?? self::DEFAULT_CELL_SIZE),
            'origin'   => ['c' => 0, 'r' => 0],
        ];
        $schema['regions'] = $regions;
        $schema['rules']   = self::normalizeRules($schema['rules'] ?? []);

        return $schema;
    }

    /**
     * 空字符串 / null → null，其余转 int（表单提交的空输入框会拿到 ''）
     */
    protected static function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    /**
     * 校验 schema（01 §10.3）
     *
     * @return array<int,string> 错误列表；空数组 = 通过
     */
    public static function validate(array $schema): array
    {
        return LevelValidator::validate($schema);
    }

    /**
     * schema 指纹（用于同 seed 生成结果一致性断言、前端缓存校验）
     */
    public static function fingerprint(array $schema): string
    {
        return StableStringify::fingerprint($schema);
    }

    /**
     * 从 schema 推导元素池（palette.gems × regions[0].spawn.weights）
     *
     * Schema v1 不再有 element_pool 列，补货权重改由 spawn.weights 表达，
     * 这里把它翻译成 BoardSeedService 需要的 [{element,weight}]。
     *
     * @return array<int,array{element:string,weight:int}>
     */
    public static function elementPool(array $schema): array
    {
        $region = $schema['regions'][0] ?? [];
        $weights = $region['spawn']['weights'] ?? [];
        $gems    = $schema['palette']['gems'] ?? [];

        if (!is_array($weights) || !is_array($gems)) {
            return [];
        }

        $pool = [];
        foreach ($gems as $i => $gem) {
            $weight = (int) ($weights[$i] ?? 0);
            if ($weight > 0) {
                $pool[] = ['element' => (string) $gem, 'weight' => $weight];
            }
        }

        return $pool;
    }

    // ==================================================================
    //  内部
    // ==================================================================

    /**
     * 组装 board（冗余列优先，缺失时从 regions 推导）
     */
    protected static function boardOf(array $row, array $regions): array
    {
        [$rows, $cols] = self::measure($regions, (int) ($row['board_rows'] ?? 0), (int) ($row['board_cols'] ?? 0));

        return [
            'cols'     => $cols,
            'rows'     => $rows,
            'cellSize' => (int) (($row['cell_size'] ?? 0) ?: self::DEFAULT_CELL_SIZE),
            'origin'   => ['c' => 0, 'r' => 0],
        ];
    }

    /**
     * 推导棋盘尺寸：优先显式声明，否则取 regions 的最大包围盒
     *
     * @return array{0:int,1:int} [rows, cols]
     */
    protected static function measure(array $regions, int $declaredRows, int $declaredCols): array
    {
        if ($declaredRows > 0 && $declaredCols > 0) {
            return [$declaredRows, $declaredCols];
        }

        $rows = $declaredRows;
        $cols = $declaredCols;

        foreach ($regions as $rg) {
            $terrain = $rg['layers']['terrain'] ?? [];
            $originR = (int) ($rg['origin']['r'] ?? 0);
            $originC = (int) ($rg['origin']['c'] ?? 0);
            $h       = count($terrain);
            $w       = $h > 0 ? strlen((string) $terrain[0]) : 0;

            $rows = max($rows, $originR + $h);
            $cols = max($cols, $originC + $w);
        }

        return [$rows ?: 9, $cols ?: 9];
    }

    /**
     * 规范化 regions：补齐 id/origin/gravity/layers/spawn 默认值
     */
    protected static function normalizeRegions($regions): array
    {
        if (is_string($regions)) {
            $regions = json_decode($regions, true);
        }
        if (!is_array($regions)) {
            return [];
        }

        $out = [];
        foreach ($regions as $i => $rg) {
            if (!is_array($rg)) {
                continue;
            }
            $layers = $rg['layers'] ?? [];
            $out[]  = [
                'id'      => (string) ($rg['id'] ?? ($i === 0 ? 'main' : "r{$i}")),
                'origin'  => [
                    'c' => (int) ($rg['origin']['c'] ?? 0),
                    'r' => (int) ($rg['origin']['r'] ?? 0),
                ],
                'gravity' => (string) ($rg['gravity'] ?? 'down'),
                'layers'  => [
                    'terrain' => array_values((array) ($layers['terrain'] ?? [])),
                    'entity'  => array_values((array) ($layers['entity'] ?? [])),
                    'overlay' => array_values((array) ($layers['overlay'] ?? [])),
                ],
                'spawn'   => [
                    'mode'                => (string) ($rg['spawn']['mode'] ?? 'top'),
                    'weights'             => array_values((array) ($rg['spawn']['weights'] ?? [])),
                    'excludeInitialMatch' => (bool) ($rg['spawn']['excludeInitialMatch'] ?? true),
                ],
            ];
        }

        return $out;
    }

    /**
     * 规范化 rules：补齐 mode/minMatch/autoShuffle 默认值
     */
    protected static function normalizeRules($rules): array
    {
        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }
        if (!is_array($rules)) {
            $rules = [];
        }

        $rules['mode']        = (string) ($rules['mode'] ?? 'moves');
        $rules['minMatch']    = (int) ($rules['minMatch'] ?? 3);
        $rules['autoShuffle'] = (bool) ($rules['autoShuffle'] ?? true);
        $rules['goals']       = array_values((array) ($rules['goals'] ?? []));

        // 特殊元素合成规则（01 §4.1）：必须预置，否则遗物 `replace /rules/specials/match4` 会抛异常
        if (!isset($rules['specials']) || !is_array($rules['specials'])) {
            $rules['specials'] = ['match4' => 'stripe', 'match5Line' => 'rainbow', 'match5Cross' => 'bomb'];
        } else {
            $rules['specials'] = array_merge(
                ['match4' => 'stripe', 'match5Line' => 'rainbow', 'match5Cross' => 'bomb'],
                $rules['specials']
            );
        }

        // 遗物常引用的可选倍率字段，以 1 为基准预置（乘法单位元，避免 mul/replace 抛异常）
        foreach (['scoreMul', 'coinMul', 'goalMul', 'comboMul'] as $mulField) {
            if (!isset($rules[$mulField]) || !is_numeric($rules[$mulField])) {
                $rules[$mulField] = 1;
            }
        }

        return $rules;
    }
}
