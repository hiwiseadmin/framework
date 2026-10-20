<?php

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

use plugin\match3game\model\Match3Run;
use plugin\match3game\model\Match3RunNode;
use plugin\match3game\service\level\Match3Random;

/**
 * 商店 / 休息节点服务（Phase 4 · 05 §B7.3）
 *
 * 【商店】库存按 (level_seed, nodeId) **确定性生成**并落库到 node_payload，
 *   保证断点续玩看到的是同一批商品与同一个价格。
 *   价格表（05 §B7.3）：
 *     遗物 60-120（按稀有度）／步数+5 = 30／回 1 机会 = 150／移除诅咒 = 100
 *   遗物 `r_meta_shop`（/run/shopPriceMul=0.7）会整体打折。
 *
 * 【休息】二选一：回 1 次机会 / 升级 1 个可升级遗物（RelicService::isUpgradable）。
 *
 * 【生命周期】enter 生成库存 → buy 可多次 → leave 才算节点通过（下一节点才可达）。
 */
class ShopService
{
    /** 遗物价格（05 §B7.3：60-120 灵玉，按稀有度） */
    public const PRICE_RELIC = [
        'common' => 60,
        'rare'   => 80,
        'epic'   => 120,
        'cursed' => 50,
    ];

    /** 步数 +N 的价格 */
    public const PRICE_MOVES = 30;
    public const MOVES_STEP  = 5;

    /** 回 1 次机会 */
    public const PRICE_LIFE = 150;

    /** 移除 1 个诅咒遗物 */
    public const PRICE_REMOVE_CURSE = 100;

    /** 商店上架遗物数量 */
    public const RELIC_STOCK = 2;

    /** 休息节点的选项 ID */
    public const REST_LIFE    = 'life';
    public const REST_UPGRADE = 'upgrade';

    protected Match3RunService $runs;

    public function __construct(?Match3RunService $runs = null)
    {
        $this->runs = $runs ?? new Match3RunService();
    }

    /* ======================================================================
     *  商店
     * ====================================================================== */

    /**
     * 进入商店：生成（或读回）库存
     *
     * @return array{coins:int, stock:array<int,array<string,mixed>>, bought:array<int,string>}
     * @throws Match3NodeException
     */
    public function enter(int $userId, string $runId, string $nodeId): array
    {
        $ctx     = $this->runs->loadCurrentNode($userId, $runId, $nodeId, Match3NodeService::NODE_SHOP);
        $run     = $ctx['run'];
        $nodeRow = $ctx['node'];

        $payload = is_array($nodeRow['node_payload'] ?? null) ? $nodeRow['node_payload'] : [];

        if (empty($payload['stock'])) {
            $payload['stock']  = $this->buildStock($run, $nodeId, $this->loadPool($userId));
            $payload['bought'] = [];
            $this->runs->persistNodePayload((int) $nodeRow['id'], $payload);
        }

        return [
            'coins'  => (int) $run['coins'],
            'stock'  => $payload['stock'],
            'bought' => array_values((array) ($payload['bought'] ?? [])),
        ];
    }

    /**
     * 确定性生成库存
     *
     * @param  array<string,mixed>            $run
     * @param  array<int,array<string,mixed>> $pool 遗物奖池
     * @return array<int,array<string,mixed>>
     */
    public function buildStock(array $run, string $nodeId, array $pool): array
    {
        $seed     = (int) ($run['level_seed'] ?? 0);
        $salt     = (int) crc32('shop' . $nodeId);
        $ownedIds = array_column((array) $run['relics'], 'id');
        $priceMul = max(0.1, (float) ($run['buffs']['shopPriceMul'] ?? 1));

        $stock = [];

        // ① 遗物（按商店稀有度分布抽，去重）
        $dist   = RelicService::RARITY_DIST['shop'];
        $rarities = [];
        foreach ($dist as $rarity => $w) {
            if ($w > 0) {
                $rarities[$rarity] = $w;
            }
        }

        $taken = $ownedIds;
        for ($i = 0; $i < self::RELIC_STOCK; $i++) {
            $rarity = (string) Match3Random::weightedPick($seed, $rarities ?: RelicService::RARITY_WEIGHTS, $salt, $i, crc32('rarity'));

            $candidates = array_values(array_filter($pool, static function ($r) use ($rarity, $taken) {
                return (string) ($r['rarity'] ?? '') === $rarity
                    && !in_array((string) ($r['relic_id'] ?? ''), $taken, true);
            }));

            if ($candidates === []) {
                // 该稀有度没了 → 全池兜底
                $candidates = array_values(array_filter($pool, static fn ($r) => !in_array((string) ($r['relic_id'] ?? ''), $taken, true)));
            }
            if ($candidates === []) {
                break;
            }

            $weights = [];
            foreach ($candidates as $r) {
                $weights[(string) $r['relic_id']] = max(1, (int) ($r['weight'] ?? 100));
            }
            $pickedId = (string) Match3Random::weightedPick($seed, $weights, $salt, $i, crc32('relic'));

            $pick = $candidates[0];
            foreach ($candidates as $r) {
                if ((string) $r['relic_id'] === $pickedId) {
                    $pick = $r;
                    break;
                }
            }

            $taken[] = (string) $pick['relic_id'];
            $price   = (int) round((self::PRICE_RELIC[(string) ($pick['rarity'] ?? 'common')] ?? 80) * $priceMul);

            $stock[] = [
                'id'      => 'relic:' . $pick['relic_id'],
                'kind'    => 'relic',
                'relicId' => (string) $pick['relic_id'],
                'name'    => (string) $pick['name'],
                'rarity'  => (string) $pick['rarity'],
                'desc'    => (string) ($pick['desc'] ?? ''),
                'price'   => $price,
            ];
        }

        // ② 步数 +5（本局后续每关）
        $stock[] = [
            'id'    => 'moves',
            'kind'  => RunEffects::KIND_BUFF_MOVES,
            'value' => self::MOVES_STEP,
            'name'  => '步数 +' . self::MOVES_STEP,
            'desc'  => '本局后续每个节点步数 +' . self::MOVES_STEP,
            'price' => (int) round(self::PRICE_MOVES * $priceMul),
        ];

        // ③ 回 1 次机会（满机会时不上架）
        if ((int) $run['lives'] < RogueRules::BASE_LIVES) {
            $stock[] = [
                'id'    => 'life',
                'kind'  => RunEffects::KIND_LIFE,
                'value' => 1,
                'name'  => '恢复 1 次机会',
                'desc'  => '失败机会 +1',
                'price' => (int) round(self::PRICE_LIFE * $priceMul),
            ];
        }

        // ④ 移除诅咒遗物（没有诅咒时不上架）
        if ($this->hasCurse($run, $pool)) {
            $stock[] = [
                'id'    => 'remove_curse',
                'kind'  => RunEffects::KIND_REMOVE_CURSE,
                'value' => 1,
                'name'  => '净化符',
                'desc'  => '移除 1 个诅咒遗物',
                'price' => (int) round(self::PRICE_REMOVE_CURSE * $priceMul),
            ];
        }

        return $stock;
    }

    /**
     * 购买
     *
     * @return array{coins:int, item:array<string,mixed>, applied:array<int,string>, relics:array<int,string>}
     * @throws Match3NodeException
     */
    public function buy(int $userId, string $runId, string $nodeId, string $itemId): array
    {
        $ctx     = $this->runs->loadCurrentNode($userId, $runId, $nodeId, Match3NodeService::NODE_SHOP);
        $run     = $ctx['run'];
        $nodeRow = $ctx['node'];

        $payload = is_array($nodeRow['node_payload'] ?? null) ? $nodeRow['node_payload'] : [];
        $stock   = (array) ($payload['stock'] ?? []);
        $bought  = array_values((array) ($payload['bought'] ?? []));

        $item = null;
        foreach ($stock as $it) {
            if ((string) ($it['id'] ?? '') === $itemId) {
                $item = $it;
                break;
            }
        }
        if ($item === null) {
            throw new Match3NodeException('商品不存在: ' . $itemId, Match3NodeException::CODE_NODE_NOT_FOUND);
        }
        if (in_array($itemId, $bought, true)) {
            throw new Match3NodeException('该商品已售出', Match3NodeException::CODE_NODE_SETTLED);
        }

        $price = (int) ($item['price'] ?? 0);
        if ((int) $run['coins'] < $price) {
            throw new Match3NodeException('灵玉不足（需要 ' . $price . '）', Match3NodeException::CODE_STONE_NOT_ENOUGH);
        }

        // 先扣费，再应用效果（效果失败会退回）
        $run['coins'] = (int) $run['coins'] - $price;

        $effects = $this->effectsOf($item);
        $result  = RunEffects::apply($run, $effects, [
            'seed'       => (int) $run['level_seed'],
            'salt'       => (int) crc32('shopbuy' . $nodeId . $itemId),
            'pool'       => $this->loadPool($userId),
            'obtainedAt' => $nodeId,
        ]);

        if ($result['failed'] !== [] && $result['applied'] === []) {
            throw new Match3NodeException('购买失败：' . implode('；', $result['failed']), Match3NodeException::CODE_RELIC_MISSING);
        }

        $bought[] = $itemId;
        $payload['bought'] = $bought;

        $this->runs->persistRunState($runId, $run);
        $this->runs->persistNodePayload((int) $nodeRow['id'], $payload);

        return [
            'coins'   => (int) $run['coins'],
            'item'    => $item,
            'applied' => $result['applied'],
            'relics'  => array_column((array) $run['relics'], 'id'),
        ];
    }

    /**
     * 离开商店（标记节点通过，解锁后续节点）
     *
     * @return array{nextNodes:array<int,string>, coins:int}
     * @throws Match3NodeException
     */
    public function leave(int $userId, string $runId, string $nodeId): array
    {
        $ctx     = $this->runs->loadCurrentNode($userId, $runId, $nodeId, Match3NodeService::NODE_SHOP);
        $run     = $ctx['run'];
        $nodeRow = $ctx['node'];

        $payload           = is_array($nodeRow['node_payload'] ?? null) ? $nodeRow['node_payload'] : [];
        $payload['settled'] = true;

        $this->runs->persistNodePayload((int) $nodeRow['id'], $payload, true);

        return [
            'nextNodes' => Match3MapGenerator::nextNodeIds($run['map_data'], $nodeId),
            'coins'     => (int) $run['coins'],
        ];
    }

    /* ======================================================================
     *  休息
     * ====================================================================== */

    /**
     * 休息节点选项（二选一，05 §B7.3）
     *
     * @return array{options:array<int,array<string,mixed>>, settled:bool}
     * @throws Match3NodeException
     */
    public function rest(int $userId, string $runId, string $nodeId, ?string $choice = null, ?string $relicId = null): array
    {
        $ctx     = $this->runs->loadCurrentNode($userId, $runId, $nodeId, Match3NodeService::NODE_REST);
        $run     = $ctx['run'];
        $nodeRow = $ctx['node'];

        $pool    = $this->loadPool($userId);
        $payload = is_array($nodeRow['node_payload'] ?? null) ? $nodeRow['node_payload'] : [];

        // 未选择 → 只返回选项
        if ($choice === null || $choice === '') {
            $options = $this->restOptions($run, $pool);
            $payload['options'] = $options;
            $this->runs->persistNodePayload((int) $nodeRow['id'], $payload);

            return ['options' => $options, 'settled' => (bool) ($payload['settled'] ?? false)];
        }

        if (!empty($payload['settled'])) {
            throw new Match3NodeException('该节点已结算', Match3NodeException::CODE_NODE_SETTLED);
        }

        $effects = match ($choice) {
            self::REST_LIFE    => [['kind' => RunEffects::KIND_LIFE, 'value' => 1]],
            self::REST_UPGRADE => [['kind' => RunEffects::KIND_UPGRADE, 'relic_id' => (string) $relicId]],
            default            => throw new Match3NodeException('未知的休息选项: ' . $choice, Match3NodeException::CODE_NODE_NOT_FOUND),
        };

        $result = RunEffects::apply($run, $effects, [
            'seed'       => (int) $run['level_seed'],
            'salt'       => (int) crc32('rest' . $nodeId),
            'pool'       => $pool,
            'obtainedAt' => $nodeId,
        ]);

        if ($result['applied'] === []) {
            throw new Match3NodeException('操作无效：' . implode('；', $result['failed']), Match3NodeException::CODE_RELIC_MISSING);
        }

        $payload['settled'] = true;
        $payload['choice']  = $choice;
        $payload['relicId'] = $relicId;
        $payload['applied'] = $result['applied'];

        $this->runs->persistRunState($runId, $run);
        $this->runs->persistNodePayload((int) $nodeRow['id'], $payload, true);

        return [
            'options'   => $this->restOptions($run, $pool),
            'settled'   => true,
            'applied'   => $result['applied'],
            'lives'     => (int) $run['lives'],
            'nextNodes' => Match3MapGenerator::nextNodeIds($run['map_data'], $nodeId),
        ];
    }

    /**
     * 休息选项
     *
     * @param  array<string,mixed>            $run
     * @param  array<int,array<string,mixed>> $pool
     * @return array<int,array<string,mixed>>
     */
    public function restOptions(array $run, array $pool): array
    {
        $options = [];

        if ((int) $run['lives'] < RogueRules::BASE_LIVES) {
            $options[] = [
                'id'    => self::REST_LIFE,
                'label' => '恢复 1 次机会',
                'desc'  => '当前机会 ' . (int) $run['lives'] . ' / ' . RogueRules::BASE_LIVES,
            ];
        }

        $upgradable = array_values(array_filter(
            RelicService::upgradableOf((array) $run['relics'], $pool),
            static fn ($r) => $r['upgradable']
        ));
        if ($upgradable !== []) {
            $options[] = [
                'id'     => self::REST_UPGRADE,
                'label'  => '升级 1 个遗物',
                'desc'   => '可升级：' . implode('、', array_column($upgradable, 'name')),
                'relics' => $upgradable,
            ];
        }

        // 两个选项都不可用时，给一个"回满机会"兜底，避免节点卡死
        if ($options === []) {
            $options[] = [
                'id'    => self::REST_LIFE,
                'label' => '休息（无可用强化）',
                'desc'  => '机会已满且无可升级遗物，本节点直接通过',
            ];
        }

        return $options;
    }

    /* ======================================================================
     *  内部
     * ====================================================================== */

    /**
     * 商品 → 效果列表
     *
     * @param  array<string,mixed> $item
     * @return array<int,array<string,mixed>>
     */
    protected function effectsOf(array $item): array
    {
        $kind = (string) ($item['kind'] ?? '');

        if ($kind === 'relic') {
            return [['kind' => RunEffects::KIND_RELIC, 'relic_id' => (string) ($item['relicId'] ?? '')]];
        }

        $effects = [['kind' => $kind, 'value' => (int) ($item['value'] ?? 0)]];

        return $effects;
    }

    /**
     * 商店/事件可用的遗物池（含 meta 解锁）
     *
     * @return array<int,array<string,mixed>>
     */
    protected function loadPool(int $userId): array
    {
        $meta = $this->runs->getMeta($userId);

        return (new RelicService())->pool((array) ($meta['unlocked_relics'] ?? []));
    }

    /**
     * 玩家是否持有诅咒遗物
     *
     * @param array<string,mixed>            $run
     * @param array<int,array<string,mixed>> $pool
     */
    protected function hasCurse(array $run, array $pool): bool
    {
        $byId = [];
        foreach ($pool as $r) {
            $byId[(string) $r['relic_id']] = (string) ($r['rarity'] ?? '');
        }

        foreach ((array) $run['relics'] as $r) {
            if (($byId[(string) ($r['id'] ?? '')] ?? '') === 'cursed') {
                return true;
            }
        }

        return false;
    }
}
