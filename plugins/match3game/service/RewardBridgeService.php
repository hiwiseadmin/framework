<?php

declare(strict_types=1);

namespace plugin\match3game\service;

use plugin\match3game\contract\Match3Constants;
use plugin\match3game\contract\RewardReason;
use wise\support\context\ContextualLogger;

/**
 * 统一发奖 / 扣减 / 排行 / 事件桥接（文档 §2.3 / §7.2）
 *
 * 结算 / 任务 / 活动 / 广告共用本服务，统一走 wise-game-core 的 InventoryService，
 * 保证流水审计与防超卖一致。
 *
 * 【P6-B04 强化：可审计】
 *   ① reason 必须来自 `RewardReason` 受控词表（未知值记 warning 但**不阻断**发奖）
 *   ② 灵石（spirit_stone）与金币/道具一样走 InventoryService，纳入 inventory_log
 *   ③ 失败**不再完全静默**：记 error 并通过返回值告知调用方（原来是只 warn 然后跳过，
 *      结果"玩家没拿到奖励"这件事没人知道）
 *   ④ 提供 `getBalance()` / `getLogs()` 供后台审计页按 reason 查询
 *
 * 所有下游服务通过 class_exists() 守卫 + 容器解析，未接入时降级（fail-open），
 * 与 wise-game-stage 的 grantRewards() 策略一致 —— 但**降级必须留痕**。
 */
class RewardBridgeService
{
    /** @var string 游戏标识 */
    protected string $gameId;

    /** @var array 排行榜 board key 配置 */
    protected array $boardKeys;

    /** 最近一次失败原因（便于调用方 diagnostics，不影响主流程） */
    protected ?string $lastError = null;

    public function __construct(array $config = [])
    {
        $this->gameId    = Match3Constants::GAME_ID;
        $this->boardKeys = $config['leaderboard'] ?? [
            'score_board' => Match3Constants::BOARD_SCORE,
            'star_board'  => Match3Constants::BOARD_STAR,
        ];
    }

    // ================================================================
    //  发奖
    // ================================================================

    /**
     * 批量发奖（遍历 rewards，逐项调用 InventoryService::add）
     *
     * @param int    $userId  用户 ID
     * @param array  $rewards [{item_id:string, count:int}, ...]
     * @param string $reason  发放原因（**须为 RewardReason 常量**，写入流水）
     * @param array  $extra   扩展数据
     * @return array [item_id => 发放后余额]；失败项不出现在结果里
     */
    public function grantRewards(int $userId, array $rewards, string $reason = RewardReason::GM_GRANT, array $extra = []): array
    {
        $this->lastError    = null;
        $this->checkReason($reason, 'grant');

        $inventory = $this->inventoryService();
        $balances  = [];

        foreach ($rewards as $reward) {
            $itemId = (string) ($reward['item_id'] ?? '');
            $count  = (int) ($reward['count'] ?? 0);
            if ($itemId === '' || $count <= 0) {
                continue;
            }

            if ($inventory === null) {
                $this->fail('InventoryService 未接入，发奖跳过（玩家不会收到）', [
                    'user_id' => $userId, 'item_id' => $itemId, 'count' => $count, 'reason' => $reason,
                ]);
                continue;
            }

            try {
                $balances[$itemId] = $inventory->add($this->gameId, $userId, $itemId, $count, $reason, $extra);
            } catch (\Throwable $e) {
                // 单品失败不影响其他品，但必须留痕
                $this->fail('发奖失败: ' . $e->getMessage(), [
                    'user_id' => $userId, 'item_id' => $itemId, 'count' => $count, 'reason' => $reason,
                ]);
            }
        }

        return $balances;
    }

    /**
     * 发放金币（便捷方法）
     */
    public function grantCoins(int $userId, int $count, string $reason = RewardReason::GM_GRANT, array $extra = []): int
    {
        $balances = $this->grantRewards($userId, [
            ['item_id' => Match3Constants::ITEM_COINS, 'count' => $count],
        ], $reason, $extra);

        return (int) ($balances[Match3Constants::ITEM_COINS] ?? 0);
    }

    // ================================================================
    //  灵石（局外货币，P6-B04 纳入 inventory_log）
    // ================================================================

    /**
     * 发放灵石（肉鸽局结算）
     *
     * @return int 发放后余额（失败返回当前余额或 0）
     */
    public function grantSpiritStone(int $userId, int $count, array $extra = []): int
    {
        return $this->grantOne($userId, Match3Constants::ITEM_SPIRIT_STONE, $count, RewardReason::ROGUE_FINISH, $extra);
    }

    /**
     * 消耗灵石（meta 永久解锁）
     *
     * 不足时**不扣款**并返回 false（由调用方决定是否提示）。
     *
     * @return array{ok:bool, balance:int, error?:string}
     */
    public function consumeSpiritStone(int $userId, int $count, array $extra = []): array
    {
        $inventory = $this->inventoryService();
        if ($inventory === null) {
            $this->fail('InventoryService 未接入，灵石扣减跳过', ['user_id' => $userId, 'count' => $count]);

            return ['ok' => false, 'balance' => 0, 'error' => 'inventory_unavailable'];
        }

        try {
            $balance = $inventory->use(
                $this->gameId,
                $userId,
                Match3Constants::ITEM_SPIRIT_STONE,
                $count,
                RewardReason::META_UNLOCK,
                $extra
            );

            return ['ok' => true, 'balance' => (int) $balance];
        } catch (\Throwable $e) {
            // GameCoreException: insufficient item —— 属于业务预期（余额不足），不算系统错误
            $this->fail('灵石扣减失败: ' . $e->getMessage(), ['user_id' => $userId, 'count' => $count]);

            return ['ok' => false, 'balance' => 0, 'error' => $e->getMessage()];
        }
    }

    // ================================================================
    //  道具扣减
    // ================================================================

    /**
     * 扣减道具（InventoryService::use，防超卖）
     *
     * @param int    $userId 用户 ID
     * @param array  $items  [item_code => count]
     * @param string $reason 扣减原因（**须为 RewardReason 常量**）
     * @throws \Throwable 余额不足时向上抛（由调用方转成业务错误码）
     */
    public function consumeProps(int $userId, array $items, string $reason = RewardReason::PROP_USE): void
    {
        $this->lastError = null;
        $this->checkReason($reason, 'consume');

        $inventory = $this->inventoryService();
        if ($inventory === null) {
            $this->fail('InventoryService 未接入，道具扣减跳过', [
                'user_id' => $userId, 'items' => $items, 'reason' => $reason,
            ]);
            return;
        }

        foreach ($items as $itemCode => $count) {
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }

            try {
                $inventory->use($this->gameId, $userId, (string) $itemCode, $count, $reason);
            } catch (\Throwable $e) {
                $this->fail('道具扣减失败: ' . $e->getMessage(), [
                    'user_id' => $userId, 'item_id' => (string) $itemCode, 'count' => $count, 'reason' => $reason,
                ]);
                throw $e; // 扣减失败必须让调用方知道（否则等于白送道具）
            }
        }
    }

    // ================================================================
    //  审计查询（P6-B04）
    // ================================================================

    /**
     * 查询余额（未接入时返回 0）
     */
    public function getBalance(int $userId, string $itemId): int
    {
        $inventory = $this->inventoryService();
        if ($inventory === null) {
            return 0;
        }

        try {
            return (int) $inventory->get($this->gameId, $userId, $itemId);
        } catch (\Throwable $e) {
            ContextualLogger::warning('[match3] 查询余额失败: ' . $e->getMessage(), [
                'user_id' => $userId, 'item_id' => $itemId,
            ]);

            return 0;
        }
    }

    /**
     * 查询背包流水（后台审计页用）
     *
     * @return array{list:array<int,array<string,mixed>>, total:int}
     */
    public function getLogs(int $userId, string $itemId = '', int $page = 1, int $pageSize = 20): array
    {
        $inventory = $this->inventoryService();
        if ($inventory === null) {
            return ['list' => [], 'total' => 0];
        }

        try {
            $res = $inventory->getLogs($this->gameId, $userId, $itemId, $page, $pageSize);
        } catch (\Throwable $e) {
            ContextualLogger::warning('[match3] 查询流水失败: ' . $e->getMessage(), ['user_id' => $userId]);

            return ['list' => [], 'total' => 0];
        }

        // wise-game-core 的 findByUser 返回 {total, page, items}
        $rows = (array) ($res['items'] ?? $res['list'] ?? $res['data'] ?? []);

        // 补上 reason 的中文文案，审计页不用自己维护映射
        $list = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['reason'])) {
                $row['reason_text'] = RewardReason::text((string) $row['reason']);
                $row['reason_cat']  = RewardReason::categoryOf((string) $row['reason']);
            }
            $list[] = $row;
        }

        return [
            'list'  => $list,
            'total' => (int) ($res['total'] ?? count($list)),
            'page'  => (int) ($res['page'] ?? $page),
        ];
    }

    /**
     * 最近一次失败原因（可观测性；成功时为 null）
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    // ================================================================
    //  内部
    // ================================================================

    /**
     * 单项发放（内部统一走这里，保证 reason 受控）
     */
    protected function grantOne(int $userId, string $itemId, int $count, string $reason, array $extra = []): int
    {
        $this->lastError = null;

        if ($count <= 0) {
            return $this->getBalance($userId, $itemId);
        }

        $inventory = $this->inventoryService();
        if ($inventory === null) {
            $this->fail('InventoryService 未接入，发放跳过', [
                'user_id' => $userId, 'item_id' => $itemId, 'count' => $count, 'reason' => $reason,
            ]);

            return 0;
        }

        try {
            return (int) $inventory->add($this->gameId, $userId, $itemId, $count, $reason, $extra);
        } catch (\Throwable $e) {
            $this->fail('发放失败: ' . $e->getMessage(), [
                'user_id' => $userId, 'item_id' => $itemId, 'count' => $count, 'reason' => $reason,
            ]);

            return 0;
        }
    }

    /**
     * reason 受控校验：**只告警，不阻断**
     *
     * 审计价值在于"发现"，而不是"拦截" —— 因为写错 reason 时玩家该拿的奖励仍要拿到。
     */
    protected function checkReason(string $reason, string $op): void
    {
        $err = RewardReason::validate($reason);
        if ($err !== null) {
            ContextualLogger::warning('[match3] ' . $op . ' ' . $err);
        }
    }

    /**
     * 记录失败（统一出口：error 级别 + lastError）
     *
     * @param array<string,mixed> $ctx
     */
    protected function fail(string $message, array $ctx = []): void
    {
        $this->lastError = $message;

        ContextualLogger::error('[match3] ' . $message, $ctx);
    }

    // ================================================================
    //  排行榜
    // ================================================================

    /**
     * 提交排行榜（分数榜 + 星数榜）
     *
     * @return array {score_rank: array|null, star_rank: array|null}
     */
    public function submitScore(int $userId, int $score, int $stars, array $extra = []): array
    {
        $leaderboard = $this->leaderboardService();
        if ($leaderboard === null) {
            return ['score_rank' => null, 'star_rank' => null];
        }

        $extra = array_merge($extra, ['stars' => $stars]);

        $scoreRank = $leaderboard->submitScore(
            $this->boardKeys['score_board'],
            $userId,
            $score,
            $extra
        );

        $starRank = $leaderboard->submitScore(
            $this->boardKeys['star_board'],
            $userId,
            $stars,
            $extra
        );

        return [
            'score_rank' => $scoreRank,
            'star_rank'  => $starRank,
        ];
    }

    // ================================================================
    //  事件上报（任务 + 埋点）
    // ================================================================

    /**
     * 上报对局事件（任务进度 + 行为埋点）
     *
     * @param int   $userId 用户 ID
     * @param array $events [{event:string, data:array}, ...]
     */
    public function reportEvents(int $userId, array $events): void
    {
        foreach ($events as $ev) {
            $event = (string) ($ev['event'] ?? '');
            $data  = (array) ($ev['data'] ?? []);
            if ($event === '') {
                continue;
            }

            $this->reportTaskEvent($userId, $event, $data);
            $this->reportAnalytics($userId, $event, $data);
        }
    }

    /**
     * 上报任务事件（wise-game-task TaskService::onEvent）
     */
    protected function reportTaskEvent(int $userId, string $event, array $data): void
    {
        $task = $this->taskService();
        if ($task === null) {
            return;
        }

        try {
            $task->onEvent($userId, $this->gameId, $event, $data);
        } catch (\Throwable $e) {
            ContextualLogger::warning('[match3] 任务事件上报失败: ' . $e->getMessage(), [
                'user_id' => $userId, 'event' => $event,
            ]);
        }
    }

    /**
     * 上报行为埋点（wise-game-ops AnalyticsService::trackEvent）
     */
    protected function reportAnalytics(int $userId, string $event, array $data): void
    {
        $analytics = $this->analyticsService();
        if ($analytics === null) {
            return;
        }

        try {
            $analytics->trackEvent($this->gameId, $event, $userId, $data);
        } catch (\Throwable $e) {
            ContextualLogger::warning('[match3] 埋点上报失败: ' . $e->getMessage(), [
                'user_id' => $userId, 'event' => $event,
            ]);
        }
    }

    // ================================================================
    //  下游服务解析（class_exists 守卫）
    // ================================================================

    protected function inventoryService()
    {
        return class_exists(\wise\game\core\service\InventoryService::class)
            ? app()->make(\wise\game\core\service\InventoryService::class)
            : null;
    }

    protected function leaderboardService()
    {
        return class_exists(\wise\game\core\service\LeaderboardService::class)
            ? app()->make(\wise\game\core\service\LeaderboardService::class)
            : null;
    }

    protected function taskService()
    {
        return class_exists(\wise\game\task\service\TaskService::class)
            ? app()->make(\wise\game\task\service\TaskService::class)
            : null;
    }

    protected function analyticsService()
    {
        return class_exists(\wise\game\ops\service\AnalyticsService::class)
            ? app()->make(\wise\game\ops\service\AnalyticsService::class)
            : null;
    }
}
