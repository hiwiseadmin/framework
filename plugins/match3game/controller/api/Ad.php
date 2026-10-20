<?php

declare(strict_types=1);

namespace plugin\match3game\controller\api;

use plugin\match3game\contract\Match3Constants;
use plugin\match3game\contract\RewardReason;
use plugin\match3game\exception\Match3ErrorCode;
use plugin\match3game\exception\Match3Exception;
use plugin\match3game\model\Match3Battle;
use plugin\match3game\service\AdQuotaService;
use plugin\match3game\service\RewardBridgeService;

/**
 * 消消乐广告 / 复活 API（§6.3 ad/scene / revive/consume）
 *
 * POST /api/match3game/ad/scene    申请激励视频广告场景（返回广告位 ID）
 * POST /api/match3game/ad/revive   直接消耗道具复活（非广告路径）
 */
class Ad extends Base
{
    /**
     * 申请广告场景（复活 / 加步 / 金币）
     */
    public function scene()
    {
        $userId      = $this->userId();
        $scene       = (string) $this->payload('scene', '');
        $battleToken = (string) $this->payload('battle_token', '');

        $config = config('match3game.match3', []);
        $scenes = (array) ($config['ad']['scenes'] ?? []);

        if (!isset($scenes[$scene])) {
            throw Match3Exception::from(Match3ErrorCode::LEVEL_NOT_FOUND, "未知广告场景: {$scene}");
        }

        // 复活/加步场景需要校验对局仍可继续
        if (in_array($scene, [Match3Constants::AD_SCENE_REVIVE, Match3Constants::AD_SCENE_ADD_MOVES], true)) {
            $battle = Match3Battle::where('battle_token', $battleToken)
                ->where('user_id', $userId)
                ->find();

            if (!$battle || (int) $battle->status !== Match3Battle::STATUS_PLAYING) {
                throw Match3Exception::from(Match3ErrorCode::BATTLE_INVALID);
            }
        }

        $maxPerDay = (int) ($scenes[$scene]['max_count_per_day'] ?? 3);
        $quota     = new AdQuotaService();

        // 复活场景：告诉客户端「用道具复活」这条路**现在能不能走**
        //
        // 【为什么必须由服务端说】`ad/revive` 扣的是 `prop.revive`，而客户端拿不到它的库存
        //   （局内 `props_left` 只覆盖局内道具）。原先客户端**无条件**显示「使用复活道具」按钮，
        //   而 `prop.revive` 目前不在道具表里（种子只有 hammer/bomb/add_moves/shuffle/double_reward），
        //   玩家恒为 0 个 → 一点必然 HTTP 400 + `210201 物品不足: prop.revive 需要 1, 当前 0`
        //   （2026-09-27 真机）。把真实数量下发后，客户端可据此隐藏按钮（与「加步」不传
        //   `onUseProp` 的做法一致），玩家不会再撞上一个注定失败的按钮。
        $revivePropCode  = 'prop.revive';
        $revivePropCount = 0;

        if ($scene === Match3Constants::AD_SCENE_REVIVE) {
            try {
                $revivePropCount = (new RewardBridgeService())->getBalance($userId, $revivePropCode);
            } catch (\Throwable $e) {
                // 背包不可用 → 按"没有可用的复活道具"处理：不影响看视频复活
                $revivePropCount = 0;
            }
        }

        return $this->success('ok', [
            'scene'              => $scene,
            'ad_unit_id'         => $scenes[$scene]['ad_unit_id'] ?? '',
            'max_count_per_day'  => $maxPerDay,
            // 今日已用 / 剩余（前端据此显示「今日剩余 N 次」）
            'used_count_today'   => $quota->usedToday($userId, $scene),
            'remain_count_today' => $quota->remainToday($userId, $scene, $maxPerDay),
            // 「用道具复活」可不可用（`count > 0` 才给按钮）；`ad/revive` 扣的就是这个码
            'revive_prop_code'   => $revivePropCode,
            'revive_prop_count'  => $revivePropCount,
        ]);
    }

    /**
     * 直接消耗道具复活（非广告路径，如使用复活道具）
     */
    public function revive()
    {
        $userId      = $this->userId();
        $battleToken = (string) $this->payload('battle_token', '');

        $battle = Match3Battle::where('battle_token', $battleToken)
            ->where('user_id', $userId)
            ->find();

        if (!$battle || (int) $battle->status !== Match3Battle::STATUS_PLAYING) {
            throw Match3Exception::from(Match3ErrorCode::BATTLE_INVALID);
        }

        // 消耗复活道具 `prop.revive`
        //
        // ⚠️ 这里**没有**「否则扣钻石」的兜底 —— 无道具时 `InventoryService` 抛
        //    `210201 物品不足`（HTTP 400）。旧注释与文档曾写成"会扣钻石"，已更正（13 §三十八）。
        //    客户端是否给出这个按钮，由 `scene()` 下发的 `revive_prop_count` 决定。
        $bridge = new RewardBridgeService();
        $bridge->consumeProps($userId, ['prop.revive' => 1], RewardReason::REVIVE_CONSUME);
        $bridge->reportEvents($userId, [
            ['event' => 'revive_consumed', 'data' => ['battle_token' => $battleToken]],
        ]);

        return $this->success('ok', ['revived' => true]);
    }
}
