<?php

declare(strict_types=1);

namespace plugin\match3game\controller\api;

use plugin\match3game\contract\Match3Constants;
use plugin\match3game\contract\RewardReason;
use plugin\match3game\exception\Match3ErrorCode;
use plugin\match3game\exception\Match3Exception;
use plugin\match3game\model\Match3Prop;
use plugin\match3game\service\RewardBridgeService;

/**
 * 消消乐商店 API（§6.3 shop/props / shop/buy / shop/inventory）
 *
 * GET  /api/match3game/shop/props       道具列表（含价格）
 * POST /api/match3game/shop/buy         金币/钻石购买道具
 * POST /api/match3game/shop/inventory   背包查询（货币 + 各道具库存）
 */
class Shop extends Base
{
    /**
     * 道具列表（含价格、广告获取标识）
     */
    public function props()
    {
        $props = Match3Prop::where('status', 1)
            ->order('id', 'asc')
            ->select();

        $list = [];
        foreach ($props as $prop) {
            $list[] = [
                'code'             => $prop->code,
                'name'             => $prop->name,
                'kind'             => (int) $prop->kind,
                'effect'           => $prop->effect,
                'price'            => $prop->price,
                'per_battle_limit' => (int) $prop->per_battle_limit,
                'icon'             => $prop->icon,
            ];
        }

        return $this->success('ok', $list);
    }

    /**
     * 购买道具（金币/钻石扣减 + 道具入包）
     */
    public function buy()
    {
        $userId = $this->userId();
        $code   = (string) $this->payload('code', '');
        $count  = (int) $this->payload('count', 1);

        if ($code === '' || $count <= 0) {
            throw Match3Exception::from(Match3ErrorCode::LEVEL_NOT_FOUND, '缺少道具编码或数量非法');
        }

        $prop = Match3Prop::where('code', $code)->where('status', 1)->find();
        if (!$prop) {
            throw Match3Exception::from(Match3ErrorCode::LEVEL_NOT_FOUND, "道具不存在: {$code}");
        }

        $price = (array) ($prop->price ?? []);
        $bridge = new RewardBridgeService();

        // 扣款（金币 / 钻石）
        $costItem = null;
        $costAmount = 0;
        if (isset($price['coin']) && (int) $price['coin'] > 0) {
            $costItem   = Match3Constants::ITEM_COINS;
            $costAmount = (int) $price['coin'] * $count;
        } elseif (isset($price['diamond']) && (int) $price['diamond'] > 0) {
            $costItem   = Match3Constants::ITEM_DIAMOND;
            $costAmount = (int) $price['diamond'] * $count;
        } else {
            // 纯广告道具（price.ad=1）不能直接金币购买
            throw Match3Exception::from(Match3ErrorCode::BALANCE_NOT_ENOUGH, '该道具仅可通过观看广告获得');
        }

        // 扣款（不足抛 BALANCE_NOT_ENOUGH，由 InventoryService 异常上抛）
        $bridge->consumeProps($userId, [$costItem => $costAmount], RewardReason::SHOP_BUY_COST);

        // 入包
        $bridge->grantRewards($userId, [['item_id' => $code, 'count' => $count]], RewardReason::SHOP_BUY_GAIN);

        return $this->success('ok', [
            'code'  => $code,
            'count' => $count,
        ]);
    }

    /**
     * 背包查询（补齐「无道具背包查询接口」缺口）
     *
     * 返回：
     *   currency  金币 / 钻石 / 体力 / 灵石 余额
     *   items     全部启用道具的持有数量（count 为 0 也会返回，便于 UI 一次性渲染）
     *
     * InventoryService 未接入时降级为全 0（fail-open，不报错）。
     */
    public function inventory()
    {
        $userId = $this->userId();
        $bridge = new RewardBridgeService();

        $currency = [
            'coins'        => $bridge->getBalance($userId, Match3Constants::ITEM_COINS),
            'diamond'      => $bridge->getBalance($userId, Match3Constants::ITEM_DIAMOND),
            'energy'       => $bridge->getBalance($userId, Match3Constants::ITEM_ENERGY),
            'spirit_stone' => $bridge->getBalance($userId, Match3Constants::ITEM_SPIRIT_STONE),
        ];

        $props = Match3Prop::where('status', 1)
            ->order('id', 'asc')
            ->select();

        $items = [];
        foreach ($props as $prop) {
            $items[] = [
                'code'             => (string) $prop->code,
                'name'             => (string) $prop->name,
                'kind'             => (int) $prop->kind,
                'effect'           => $prop->effect,
                'per_battle_limit' => (int) $prop->per_battle_limit,
                'icon'             => (string) $prop->icon,
                'count'            => $bridge->getBalance($userId, (string) $prop->code),
            ];
        }

        return $this->success('ok', [
            'currency' => $currency,
            'items'    => $items,
        ]);
    }
}
