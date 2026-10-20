<?php

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

use plugin\match3game\model\Match3Relic;
use plugin\match3game\service\level\Match3Random;

/**
 * 遗物服务（05 §B7.2 / §B2.2）
 *
 * 职责：
 *   · 奖池：从 match3_relic 读启用遗物（并按 meta 解锁过滤）
 *   · 三选一：按节点类型 → 稀有度分布 → 同稀有度内按 weight 抽取
 *   · reroll：同 draw，但换 salt（付费/广告由上层处理）
 *
 * 【确定性】同 (nodeType, seed, nodeId, 已持有) 必得同一组三选一，
 * 这是断点续玩与"同 seed 可复现"的基础（05 §B8.1 第 2 条）。
 */
class RelicService
{
    /** 稀有度基础权重（05 §B7.2 左表） */
    public const RARITY_WEIGHTS = [
        'common' => 100,
        'rare'   => 45,
        'epic'   => 12,
        'cursed' => 3,
    ];

    /** 各节点类型下的稀有度分布（%，05 §B7.2 右表） */
    public const RARITY_DIST = [
        'battle' => ['common' => 70, 'rare' => 28, 'epic' => 2,  'cursed' => 0],
        'elite'  => ['common' => 55, 'rare' => 38, 'epic' => 7,  'cursed' => 0],
        'boss'   => ['common' => 40, 'rare' => 42, 'epic' => 18, 'cursed' => 0],
        'shop'   => ['common' => 50, 'rare' => 35, 'epic' => 13, 'cursed' => 2],
        'event'  => ['common' => 50, 'rare' => 35, 'epic' => 13, 'cursed' => 2],
    ];

    /** 三选一默认个数 */
    public const CHOICES = 3;

    /** @var array<string, array<int, array<string,mixed>>> 奖池缓存 */
    protected array $poolCache = [];

    /**
     * 取奖池（启用 + 已解锁）
     *
     * @param array<int,string> $unlockedRelicIds meta 中已解锁的遗物 ID
     * @return array<int, array<string,mixed>>
     */
    public function pool(array $unlockedRelicIds = []): array
    {
        $cacheKey = implode(',', $unlockedRelicIds);
        if (isset($this->poolCache[$cacheKey])) {
            return $this->poolCache[$cacheKey];
        }

        $rows = Match3Relic::where('enabled', 1)->select()->toArray();

        $pool = [];
        foreach ($rows as $r) {
            $cost = (int) ($r['unlock_cost'] ?? 0);
            if ($cost > 0 && !in_array((string) $r['relic_id'], $unlockedRelicIds, true)) {
                continue; // 未解锁，不进池
            }
            if (!is_array($r['patch'] ?? null) || $r['patch'] === []) {
                continue;
            }
            $pool[] = $r;
        }

        return $this->poolCache[$cacheKey] = $pool;
    }

    /**
     * 抽取三选一（确定性）
     *
     * @param  string             $nodeType    节点类型（battle/elite/boss/shop/event）
     * @param  int                $seed        本局 seed（用 run 的 level_seed）
     * @param  string             $nodeId      节点标识（作为 salt）
     * @param  array<int,string>  $excludeIds  已持有/已出现过的遗物 ID
     * @param  array<int,string>  $unlockedRelicIds
     * @param  array<int,array<string,mixed>>|null $poolOverride 指定奖池（每日挑战的固定遗物序列）
     * @return array<int, array<string,mixed>> 遗物配置（含 relic_id/name/rarity/desc/icon/patch）
     */
    public function draw(
        string $nodeType,
        int $seed,
        string $nodeId,
        array $excludeIds = [],
        int $count = self::CHOICES,
        array $unlockedRelicIds = [],
        ?array $poolOverride = null,
        string $scope = 'pick'
    ): array {
        $pool = $poolOverride ?? $this->pool($unlockedRelicIds);
        if ($pool === []) {
            return [];
        }

        // 按稀有度分桶
        $byRarity = [];
        foreach ($pool as $r) {
            $byRarity[(string) $r['rarity']][] = $r;
        }

        $taken    = array_map('strval', $excludeIds);
        $chosen   = [];
        $dist     = self::RARITY_DIST[$nodeType] ?? self::RARITY_DIST['battle'];
        $rarities = array_keys($dist);
        $salt     = (int) crc32($nodeId) % 100000;

        for ($i = 0; $i < $count; $i++) {
            $rarity = $this->pickRarity($seed, $dist, $rarities, $salt, $i, $scope);

            $candidate = $this->pickFromRarity($seed, $byRarity, $rarity, $taken, $salt, $i, $scope);

            // 该稀有度空了 → 依次尝试其他稀有度，最后退化为任意
            if ($candidate === null) {
                foreach ($rarities as $alt) {
                    $candidate = $this->pickFromRarity($seed, $byRarity, $alt, $taken, $salt, $i, $scope);
                    if ($candidate !== null) {
                        break;
                    }
                }
            }
            if ($candidate === null) {
                break; // 池子抽干
            }

            $taken[]  = (string) $candidate['relic_id'];
            $chosen[] = self::present($candidate);
        }

        return $chosen;
    }

    /**
     * 重选（reroll）：同 draw，但换 salt，保证与首次不同
     *
     * @param  array<int,string> $excludeIds 应包含首次已抽出的遗物
     * @param  array<int,array<string,mixed>>|null $poolOverride
     * @return array<int, array<string,mixed>>
     */
    public function reroll(
        string $nodeType,
        int $seed,
        string $nodeId,
        array $excludeIds = [],
        int $count = self::CHOICES,
        array $unlockedRelicIds = [],
        ?array $poolOverride = null
    ): array {
        return $this->draw($nodeType, $seed, $nodeId, $excludeIds, $count, $unlockedRelicIds, $poolOverride, 'reroll');
    }

    /**
     * 重新抽一个"当前节点的三选一"（供 Match3NodeService 使用，屏蔽参数差异）
     *
     * @param  array<int,string> $excludeIds
     * @param  array<int,array<string,mixed>>|null $poolOverride
     * @return array<int, array<string,mixed>>
     */
    public function drawChoices(
        string $nodeType,
        int $seed,
        string $nodeId,
        array $excludeIds = [],
        array $unlockedRelicIds = [],
        ?array $poolOverride = null
    ): array {
        return $this->draw($nodeType, $seed, $nodeId, $excludeIds, self::CHOICES, $unlockedRelicIds, $poolOverride);
    }

    /**
     * 按 ID 批量取遗物配置（严格保持传入顺序 —— 顺序影响 patch 结果）
     *
     * @param  array<int,string> $relicIds
     * @return array<int, array<string,mixed>>
     * @throws Match3NodeException 有 ID 查不到（配置缺失）
     */
    public function byIds(array $relicIds): array
    {
        $ids = array_values(array_filter(array_map('strval', $relicIds)));
        if ($ids === []) {
            return [];
        }

        $rows = Match3Relic::whereIn('relic_id', $ids)->where('enabled', 1)->select()->toArray();

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['relic_id']] = $r;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (!isset($map[$id])) {
                throw new Match3NodeException(
                    '遗物配置缺失: ' . $id,
                    Match3NodeException::CODE_RELIC_MISSING
                );
            }
            $ordered[] = $map[$id];
        }

        return $ordered;
    }

    /**
     * 遗物强度总和（用于难度补偿，05 §B7.1 的 relicPower）
     *
     * @param array<int, array<string,mixed>> $relics
     */
    public static function power(array $relics): int
    {
        $power = 0;
        foreach ($relics as $r) {
            $rarity = (string) ($r['rarity'] ?? 'common');
            $power += Match3NodeService::RARITY_POWER[$rarity] ?? 1;
        }

        return $power;
    }

    /**
     * 遗物是否可升级（休息节点的"升级 1 个遗物"）
     *
     * 判定：patch 里只含**可重复应用**的操作 ——
     *   · `add` + `mode:accumulate`（再次累加）
     *   · `mul`（再次相乘）
     * 含 `replace` / `remove` / 普通 `add`（覆盖写）的遗物重复应用是幂等的，
     * 升级没有意义，因此不允许升级（否则玩家白花一次机会）。
     *
     * @param array<int,array<string,mixed>> $patch
     */
    public static function isUpgradable(array $patch): bool
    {
        if ($patch === []) {
            return false;
        }

        foreach ($patch as $op) {
            if (!is_array($op)) {
                return false;
            }
            $opName = (string) ($op['op'] ?? '');

            if ($opName === PatchEngine::OP_MUL) {
                continue;
            }
            if ($opName === PatchEngine::OP_ADD && (string) ($op['mode'] ?? '') === PatchEngine::MODE_ACCUMULATE) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * 可升级的遗物（供休息节点列选项）
     *
     * @param  array<int,array<string,mixed>> $relics run 的 relics（[{id,lv}]）
     * @param  array<int,array<string,mixed>> $pool   遗物配置池
     * @return array<int,array<string,mixed>> [{id, name, lv, upgradable}]
     */
    public static function upgradableOf(array $relics, array $pool): array
    {
        $byId = [];
        foreach ($pool as $r) {
            $byId[(string) ($r['relic_id'] ?? '')] = $r;
        }

        $out = [];
        foreach ($relics as $r) {
            $id  = (string) ($r['id'] ?? '');
            $cfg = $byId[$id] ?? null;
            if ($cfg === null) {
                continue;
            }

            $lv = (int) ($r['lv'] ?? 1);
            $out[] = [
                'id'         => $id,
                'name'       => (string) ($cfg['name'] ?? ''),
                'rarity'     => (string) ($cfg['rarity'] ?? ''),
                'lv'         => $lv,
                'upgradable' => self::isUpgradable((array) ($cfg['patch'] ?? [])) && $lv < RunEffects::MAX_RELIC_LEVEL,
            ];
        }

        return $out;
    }

    /**
     * 遗物 → 图鉴展示结构（不泄露 patch 细节）
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id'       => (string) $row['relic_id'],
            'name'     => (string) $row['name'],
            'rarity'   => (string) $row['rarity'],
            'category' => (string) ($row['category'] ?? ''),
            'desc'     => (string) ($row['desc'] ?? ''),
            'icon'     => (string) ($row['icon'] ?? ''),
            'patch'    => $row['patch'] ?? [],
        ];
    }

    /**
     * 批量 → 图鉴展示结构
     *
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function presentMany(array $rows): array
    {
        return array_values(array_map([self::class, 'present'], $rows));
    }

    /* ---------------- 内部：确定性抽取 ---------------- */

    /**
     * @param array<string,int> $dist
     * @param array<int,string> $rarities
     */
    protected function pickRarity(int $seed, array $dist, array $rarities, int $salt, int $i, string $scope): string
    {
        $weights = [];
        foreach ($rarities as $r) {
            $w = (int) ($dist[$r] ?? 0);
            if ($w > 0) {
                $weights[$r] = $w;
            }
        }

        if ($weights === []) {
            // 全部为 0（理论上不会）→ 退化为基础权重
            $weights = self::RARITY_WEIGHTS;
        }

        return (string) Match3Random::weightedPick($seed, $weights, $salt, $i, crc32($scope . 'rarity'));
    }

    /**
     * @param array<string, array<int, array<string,mixed>>> $byRarity
     * @param array<int,string> $taken
     */
    protected function pickFromRarity(
        int $seed,
        array $byRarity,
        string $rarity,
        array $taken,
        int $salt,
        int $i,
        string $scope
    ): ?array {
        $bucket = $byRarity[$rarity] ?? [];
        $bucket = array_values(array_filter(
            $bucket,
            fn ($r) => !in_array((string) $r['relic_id'], $taken, true)
        ));

        if ($bucket === []) {
            return null;
        }

        $weights = [];
        foreach ($bucket as $r) {
            $weights[(string) $r['relic_id']] = max(1, (int) ($r['weight'] ?? 100));
        }

        $pickedId = (string) Match3Random::weightedPick($seed, $weights, $salt, $i, crc32($scope . 'relic'));

        foreach ($bucket as $r) {
            if ((string) $r['relic_id'] === $pickedId) {
                return $r;
            }
        }

        return $bucket[0];
    }
}
