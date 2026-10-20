<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\contract\Match3Constants;
use plugin\match3game\contract\RewardReason;
use plugin\match3game\service\RewardBridgeService;

/**
 * GM 道具发放 + 背包流水审计（§8.2 / P6-B04）
 *
 * 【发放】走 InventoryService → inventory_log，reason 必须来自 RewardReason 的**发放类**常量。
 * 【审计】按用户 / 物品 / reason 查流水，中文文案由 RewardReason 提供。
 */
class Reward extends Base
{
    public function index()
    {
        // 只把"发放类"reason 暴露给后台（扣减类不该被手动使用）
        $gains = RewardReason::gains();
        $map   = [];
        foreach ($gains as $r) {
            $map[$r] = RewardReason::text($r);
        }

        return $this->assign([
            'gainReasons'   => $map,
            'defaultReason' => RewardReason::GM_GRANT,
        ])->fetch();
    }

    /**
     * 发放奖励
     */
    public function send()
    {
        $userId = $this->request->post('user_id/d', 0);
        $itemId = (string) $this->request->post('item_id', '');
        $count  = $this->request->post('count/d', 0);
        $reason = (string) $this->request->post('reason', RewardReason::GM_GRANT);

        if ($userId <= 0 || $itemId === '' || $count <= 0) {
            return $this->error('请填写完整的发放信息');
        }

        // reason 必须是受控词表里的"发放类"
        if (RewardReason::categoryOf($reason) !== RewardReason::CAT_GAIN) {
            return $this->error('发放原因不合法：必须是 RewardReason 的发放类常量（当前 ' . $reason . '）');
        }

        $bridge   = new RewardBridgeService();
        $balances = $bridge->grantRewards($userId, [
            ['item_id' => $itemId, 'count' => $count],
        ], $reason, ['gm' => true]);

        if ($balances === []) {
            return $this->error('发放失败：' . ($bridge->lastError() ?? '未知原因（详见日志）'));
        }

        return $this->success('发放成功', ['balances' => $balances]);
    }

    /**
     * 发放资源清单（前端下拉用）
     */
    public function items()
    {
        return $this->success('', [
            ['item_id' => Match3Constants::ITEM_COINS, 'name' => '金币'],
            ['item_id' => Match3Constants::ITEM_DIAMOND, 'name' => '钻石'],
            ['item_id' => Match3Constants::ITEM_ENERGY, 'name' => '体力'],
            ['item_id' => Match3Constants::ITEM_SPIRIT_STONE, 'name' => '灵石'],
            ['item_id' => 'prop.hammer', 'name' => '魔法锤'],
            ['item_id' => 'prop.bomb', 'name' => '炸弹'],
            ['item_id' => 'prop.add_moves', 'name' => '加步道具'],
            ['item_id' => 'prop.shuffle', 'name' => '洗牌'],
        ]);
    }

    /**
     * 背包流水审计（P6-B04）
     *
     * post: { user_id, item_id?, pageNumber?, pageSize? }
     * 返回 bootstrap-table 结构 { total, rows }，reason 带中文文案
     */
    public function logs()
    {
        $userId = $this->request->post('user_id/d', 0);
        $itemId = (string) $this->request->post('item_id', '');
        $page   = $this->request->post('pageNumber/d', 1);
        $size   = $this->request->post('pageSize/d', 20);

        if ($userId <= 0) {
            return $this->success('', ['total' => 0, 'rows' => []]);
        }

        $res = (new RewardBridgeService())->getLogs($userId, $itemId, max(1, $page), max(1, min(100, $size)));

        return $this->success('', ['total' => $res['total'], 'rows' => $res['list']]);
    }
}
