<?php
/**
 * 肉鸽关卡树（Run Map）生成器
 *
 * 由 mapSeed 生成一张确定性的节点图：3 层，每层 4 个节点 + 层末 1 个 Boss。
 * 同一 mapSeed 永远产出同一张图 —— 这是断点续玩与重放的基础。
 *
 * 节点图结构（存 match3_run.map_data）：
 *   {
 *     "mapSeed": 88231,
 *     "layers": 3,
 *     "nodes": [
 *       { "id":"1-1", "layer":1, "index":0, "type":"battle", "stageCode":"M3-0101", "next":["1-2"] }
 *     ]
 *   }
 *
 * 迁移自 docs/match3/code/Match3MapGenerator.php，新增：
 *   · isStartNode / availableNodeIds / isReachable（进节点的可达性校验，防跳关）
 */

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

use plugin\match3game\service\level\Match3Random;

class Match3MapGenerator
{
    /** 每层节点数（不含 Boss） */
    public const NODES_PER_LAYER = 4;

    /** 起点节点数 */
    public const START_NODES = 2;

    /**
     * 每层战斗类节点下限（含层末 Boss）
     *
     * 纯按权重随机时，某层可能只有 1 个战斗节点（其余全是 shop/rest/event），
     * 一局跑下来三消玩不到几关。这里强制补足：不足则把非战斗节点按索引顺序改写为 battle。
     * 策划权重仍是主导（满足下限时不做任何修改）。
     */
    public const MIN_COMBAT_PER_LAYER = 3;

    /** 节点类型权重（05 §1.3） */
    public const TYPE_WEIGHTS = [
        'battle' => 45,
        'elite'  => 15,
        'shop'   => 12,
        'rest'   => 12,
        'event'  => 16,
    ];

    /** 战斗类节点（需要分配关卡） */
    public const COMBAT_TYPES = ['battle', 'elite', 'boss'];

    /**
     * 生成关卡树
     *
     * @param  array<int, string> $stagePool 可选关卡池（给战斗节点分配 stageCode）
     * @return array<string, mixed>
     */
    public static function generate(int $mapSeed, int $layers = 3, array $stagePool = []): array
    {
        $nodes         = [];
        $allLayerNodes = [];

        for ($layer = 1; $layer <= $layers; $layer++) {
            $layerNodes = [];

            for ($i = 0; $i < self::NODES_PER_LAYER; $i++) {
                $type         = self::pickType($mapSeed, $layer, $i);
                $layerNodes[] = [
                    'id'        => "{$layer}-{$i}",
                    'layer'     => $layer,
                    'index'     => $i,
                    'type'      => $type,
                    'stageCode' => in_array($type, ['battle', 'elite'], true)
                        ? self::pickStage($mapSeed, $layer, $i, $stagePool)
                        : null,
                    'next'      => [],
                ];
            }

            // 补足战斗节点下限（Boss 固定占 1 个）
            $layerNodes = self::enforceCombatFloor($layerNodes, $mapSeed, $layer, $stagePool);

            // 层末 Boss
            $bossIndex    = self::NODES_PER_LAYER;
            $layerNodes[] = [
                'id'        => "{$layer}-{$bossIndex}",
                'layer'     => $layer,
                'index'     => $bossIndex,
                'type'      => 'boss',
                'stageCode' => self::pickStage($mapSeed, $layer, $bossIndex, $stagePool, true),
                'next'      => [],
            ];

            $allLayerNodes[$layer] = $layerNodes;
        }

        // 连接：层内顺序推进 + 跨层连接
        for ($layer = 1; $layer <= $layers; $layer++) {
            $cur  = $allLayerNodes[$layer];
            $next = $allLayerNodes[$layer + 1] ?? null;

            foreach ($cur as $i => &$node) {
                // 层内：连到下一个节点（最后一个即 Boss，无后继）
                if ($i < count($cur) - 1) {
                    $node['next'][] = $cur[$i + 1]['id'];
                }

                // 跨层：本层 Boss 连到下一层的起点节点
                if ($next !== null && $i === count($cur) - 1) {
                    foreach (array_slice($next, 0, self::START_NODES) as $n) {
                        $node['next'][] = $n['id'];
                    }
                }
            }
            unset($node);

            $nodes = array_merge($nodes, $cur);
        }

        return [
            'mapSeed' => $mapSeed,
            'layers'  => $layers,
            'nodes'   => $nodes,
        ];
    }

    /**
     * 取起点节点 ID 列表
     *
     * @param array<string, mixed> $mapData
     * @return array<int, string>
     */
    public static function startNodeIds(array $mapData): array
    {
        $ids = [];
        foreach (($mapData['nodes'] ?? []) as $n) {
            if ((int)($n['layer'] ?? 0) === 1) {
                $ids[] = $n['id'];
            }
            if (count($ids) >= self::START_NODES) {
                break;
            }
        }
        return $ids;
    }

    /**
     * 取某节点的后继
     *
     * @param array<string, mixed> $mapData
     * @return array<int, string>
     */
    public static function nextNodeIds(array $mapData, string $nodeId): array
    {
        foreach (($mapData['nodes'] ?? []) as $n) {
            if (($n['id'] ?? '') === $nodeId) {
                return $n['next'] ?? [];
            }
        }
        return [];
    }

    /**
     * 取单个节点
     *
     * @param array<string, mixed> $mapData
     * @return array<string, mixed>|null
     */
    public static function findNode(array $mapData, string $nodeId): ?array
    {
        foreach (($mapData['nodes'] ?? []) as $n) {
            if (($n['id'] ?? '') === $nodeId) {
                return $n;
            }
        }

        return null;
    }

    /**
     * 当前可进入的节点集合
     *
     * 规则：还没有任何已通关节点 → 起点节点；
     *       否则 → 已通关节点的并集后继。
     *
     * @param  array<string, mixed> $mapData
     * @param  array<int,string>    $clearedNodeIds 已通关节点 ID
     * @return array<int,string>
     */
    public static function availableNodeIds(array $mapData, array $clearedNodeIds = []): array
    {
        if ($clearedNodeIds === []) {
            return self::startNodeIds($mapData);
        }

        $available = [];
        foreach ($clearedNodeIds as $clearedId) {
            foreach (self::nextNodeIds($mapData, (string) $clearedId) as $nextId) {
                $available[$nextId] = true;
            }
        }

        return array_keys($available);
    }

    /**
     * 是否为起点节点
     *
     * @param array<string, mixed> $mapData
     */
    public static function isStartNode(array $mapData, string $nodeId): bool
    {
        return in_array($nodeId, self::startNodeIds($mapData), true);
    }

    /**
     * 节点是否可达（防跳关）
     *
     * @param array<string, mixed> $mapData
     * @param array<int,string>    $clearedNodeIds
     */
    public static function isReachable(array $mapData, string $nodeId, array $clearedNodeIds = []): bool
    {
        return in_array($nodeId, self::availableNodeIds($mapData, $clearedNodeIds), true);
    }

    /**
     * 统计各类型节点数量（用于自测与展示）
     *
     * @param array<string, mixed> $mapData
     * @return array<string, int>
     */
    public static function countByType(array $mapData): array
    {
        $count = [];
        foreach (($mapData['nodes'] ?? []) as $n) {
            $t = $n['type'] ?? 'unknown';
            $count[$t] = ($count[$t] ?? 0) + 1;
        }
        return $count;
    }

    /* ---------------- 内部 ---------------- */

    private static function pickType(int $seed, int $layer, int $index): string
    {
        $salt = $layer * 100 + $index;
        return (string)Match3Random::weightedPick($seed, self::TYPE_WEIGHTS, $salt);
    }

    /**
     * 补足战斗节点下限（含 Boss 口径）
     *
     * 被改写的节点必须同时补上 stageCode，否则上层会拿着空编码去查关卡模板。
     *
     * @param  array<int, array<string,mixed>> $layerNodes 不含 Boss
     * @param  array<int, string>              $stagePool
     * @return array<int, array<string,mixed>>
     */
    private static function enforceCombatFloor(array $layerNodes, int $seed, int $layer, array $stagePool): array
    {
        $need = self::MIN_COMBAT_PER_LAYER - 1; // Boss 固定提供 1 个

        $combat = 0;
        foreach ($layerNodes as $n) {
            if (in_array($n['type'], ['battle', 'elite'], true)) {
                $combat++;
            }
        }

        foreach ($layerNodes as $i => $n) {
            if ($combat >= $need) {
                break;
            }
            if (in_array($n['type'], ['battle', 'elite'], true)) {
                continue;
            }

            $layerNodes[$i]['type']      = 'battle';
            $layerNodes[$i]['stageCode'] = self::pickStage($seed, $layer, (int) $n['index'], $stagePool);
            $combat++;
        }

        return $layerNodes;
    }

    /**
     * @param array<int, string> $pool
     */
    private static function pickStage(int $seed, int $layer, int $index, array $pool, bool $isBoss = false): ?string
    {
        if ($pool === []) {
            // 无关卡池时返回占位编码，由上层按规则填充
            return $isBoss ? "M3-BOSS-{$layer}" : 'M3-0101';
        }
        $salt = $layer * 100 + $index + ($isBoss ? 5000 : 0);
        $idx  = Match3Random::intBetween($seed, 0, count($pool) - 1, $salt);
        return $pool[$idx];
    }
}
