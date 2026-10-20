<?php

declare(strict_types=1);

namespace plugin\match3game\controller\api;

use plugin\match3game\contract\Match3Constants;
use plugin\match3game\service\LevelProgressService;
use plugin\match3game\service\RewardBridgeService;

/**
 * 消消乐首页聚合 API（§6.3 home / first-guide/done）
 *
 * GET  /api/match3game/home/index            首页聚合（体力+金币钻石+当前关）
 * POST /api/match3game/home/firstGuideDone   新手引导完成上报
 */
class Home extends Base
{
    /**
     * 首页聚合：当前关 + 金币钻石 + 体力（一次拉齐）
     */
    public function index()
    {
        $userId = $this->userId();

        $progress = new LevelProgressService();
        $map      = $progress->getMap($userId);

        $bridge   = new RewardBridgeService();
        $wallet   = $this->inventorySnapshot($userId);

        return $this->success('ok', [
            'current_level' => $map['current_level'] ?? null,
            'coins'         => $wallet['coins'] ?? 0,
            'diamond'       => $wallet['diamond'] ?? 0,
            'energy'        => $this->energySnapshot($userId),
        ]);
    }

    /**
     * 新手引导完成上报（任务/埋点）
     */
    public function firstGuideDone()
    {
        $userId = $this->userId();

        $bridge = new RewardBridgeService();
        $bridge->reportEvents($userId, [
            ['event' => 'first_guide_done', 'data' => []],
        ]);

        return $this->success('ok', ['done' => true]);
    }

    /**
     * 背包快照（金币/钻石）
     */
    protected function inventorySnapshot(int $userId): array
    {
        $inventory = class_exists(\wise\game\core\service\InventoryService::class)
            ? app()->make(\wise\game\core\service\InventoryService::class)
            : null;

        if ($inventory === null) {
            return ['coins' => 0, 'diamond' => 0];
        }

        return [
            'coins'   => $inventory->get(Match3Constants::GAME_ID, $userId, Match3Constants::ITEM_COINS),
            'diamond' => $inventory->get(Match3Constants::GAME_ID, $userId, Match3Constants::ITEM_DIAMOND),
        ];
    }

    /**
     * 体力快照
     */
    protected function energySnapshot(int $userId): ?array
    {
        $class = \wise\game\energy\service\EnergyService::class;
        if (!class_exists($class)) {
            return null;
        }

        try {
            /** @var \wise\game\energy\service\EnergyService $energy */
            $energy = app()->make($class);
            return $energy->getWallet($userId, Match3Constants::GAME_ID);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
