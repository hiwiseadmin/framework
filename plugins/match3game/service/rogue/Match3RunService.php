<?php

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

use plugin\match3game\contract\Match3Constants;
use plugin\match3game\model\Match3LevelDef;
use plugin\match3game\model\Match3MetaProgress;
use plugin\match3game\model\Match3Relic;
use plugin\match3game\model\Match3Run;
use plugin\match3game\model\Match3RunNode;
use plugin\match3game\service\level\Match3Random;
use plugin\match3game\service\RewardBridgeService;
use wise\support\context\ContextualLogger;

/**
 * 肉鸽局生命周期服务
 *
 * 【职责】
 *   startRun      开新局（生成关卡树、锁定双种子）
 *   enterNode     交给 Match3NodeService 构建关卡（本类只做透传 + meta 加成）
 *   completeNode  节点通关 → 发灵玉 → 推进
 *   failNode      节点失败 → 扣机会 → 归零则本局结束
 *   finishRun     结算灵石 → 写入局外 meta 进度
 *   pickRelic     选择遗物（三选一）
 *   rerollRelics  重选遗物（付费/广告由上层处理）
 *   unlockMeta    用灵石做永久解锁
 *
 * 【双种子设计】
 *   mapSeed   决定"地图长什么样"（关卡树）
 *   levelSeed 决定"每个关卡的内容"（掉落、初始布局）
 *   两者分离，便于单独复现某一种随机。
 *
 * 【依赖表】
 *   match3_run / match3_run_node / match3_meta_progress
 *
 * 迁移自 docs/match3/code/Match3RunService.php，差异：
 *   ① 从 Db 门面改为 Model（json 列自动编解码）
 *   ② 新增 pickRelic / rerollRelics（05 §B4 接口 5/6）
 *   ③ enterNode 透传 meta 已解锁遗物与起始步数加成
 */
class Match3RunService
{
    /* ---------------- 常量（值定义在 RogueRules，便于离线单测与策划查阅） ---------------- */
    public const BASE_LIVES     = RogueRules::BASE_LIVES;
    public const DEFAULT_LAYERS = RogueRules::DEFAULT_LAYERS;

    public const REWARD_BATTLE = RogueRules::REWARD_BATTLE;
    public const REWARD_ELITE  = RogueRules::REWARD_ELITE;
    public const REWARD_BOSS   = RogueRules::REWARD_BOSS;

    public const STONE_PER_LAYER   = RogueRules::STONE_PER_LAYER;
    public const STONE_SCORE_DIV   = RogueRules::STONE_SCORE_DIV;
    public const STONE_CLEAR_BONUS = RogueRules::STONE_CLEAR_BONUS;

    public const UNLOCK_COSTS         = RogueRules::UNLOCK_COSTS;
    public const UNLOCK_RELIC_PREFIX  = RogueRules::UNLOCK_RELIC_PREFIX;
    public const UNLOCK_RELIC_COST    = RogueRules::UNLOCK_RELIC_COST;
    public const START_OPTIONS        = RogueRules::START_OPTIONS;

    public const STATUS_PLAYING = RogueRules::STATUS_PLAYING;
    public const STATUS_CLEARED = RogueRules::STATUS_CLEARED;
    public const STATUS_FAILED  = RogueRules::STATUS_FAILED;
    public const STATUS_ABANDON = RogueRules::STATUS_ABANDON;

    protected Match3NodeService $nodes;

    /**
     * 发奖 / 扣减桥接（P6-B04）
     *
     * 灵石的**唯一余额源是背包**（match3_player_inventory, item_id=spirit_stone），
     * 所有变动经本服务 → InventoryService → inventory_log（reason 可审计）。
     * `match3_meta_progress.spirit_stone` 只是**只读快照**，在同事务内同步，仅供展示。
     *
     * 懒加载：只有真正发奖/扣灵石时才构造，离线脚本与只读流程不受影响。
     */
    protected ?RewardBridgeService $bridge = null;

    /** 战斗节点可选关卡池（空 = 地图生成器用占位编码） */
    protected array $stagePool = [];

    public function __construct(?Match3NodeService $nodes = null, array $config = [], ?RewardBridgeService $bridge = null)
    {
        $this->nodes  = $nodes ?? new Match3NodeService(null, null, $config);
        $this->bridge = $bridge;

        $pool = $config['stage_pool'] ?? [];
        if (is_array($pool) && $pool !== []) {
            $this->stagePool = array_values(array_map('strval', $pool));
        }
    }

    public function setStagePool(array $pool): self
    {
        $this->stagePool = array_values(array_map('strval', $pool));
        return $this;
    }

    /**
     * 生成地图时用的关卡池（**保证非空**）
     *
     * 【为什么必须非空】`Match3MapGenerator::pickStage()` 在池子为空时会退回**占位编码**
     * （Boss 节点用 `M3-BOSS-{层}`、普通节点用 `M3-0101`）——这些码在 `match3_level_def` 里并不存在，
     * 地图会被存进 `map_data`，玩家点进那个节点就 `41003 关卡模板不存在: M3-BOSS-1`（2026-09-27 线上）。
     *
     * 口径：**配置里有就用配置**（`config/match3.php` 的 `rogue.stage_pool`，可用来只放"适合肉鸽的关"）；
     * 没配就从**关卡表**按 `sort_order` 推导 —— 这样"忘了配"不会再变成一张打不了的图。
     *
     * @throws Match3NodeException 关卡表也是空的（没导入关卡）→ 直接报错，而不是发一张打不了的图
     */
    protected function stagePoolForMap(): array
    {
        if ($this->stagePool !== []) {
            return $this->stagePool;
        }

        $codes = array_values(array_unique(array_map('strval', (array) Match3LevelDef::order('sort_order', 'asc')
            ->order('id', 'asc')
            ->column('code'))));

        if ($codes === []) {
            throw new Match3NodeException(
                '肉鸽关卡池为空：请先导入关卡（match3_level_def）或在插件配置里设置 rogue.stage_pool',
                Match3NodeException::CODE_LEVEL_NOT_FOUND
            );
        }

        return $this->stagePool = $codes;
    }

    /**
     * 发奖桥接（懒加载，可注入）
     */
    protected function bridge(): RewardBridgeService
    {
        return $this->bridge ??= new RewardBridgeService();
    }

    public function getNodeService(): Match3NodeService
    {
        return $this->nodes;
    }

    /* ======================================================================
     *  开局
     * ====================================================================== */

    /**
     * 开新局（已有进行中的局则直接返回，避免一用户多局并存）
     *
     * @param  array<int,string> $startRelics 起始遗物（需已解锁"起始遗物位"）
     * @return array<string, mixed>
     * @throws Match3NodeException
     */
    public function startRun(
        int $userId,
        string $startOption = 'default',
        ?int $fixedMapSeed = null,
        array $startRelics = []
    ): array {
        $current = $this->getCurrentRun($userId, Match3Run::MODE_NORMAL);
        if ($current !== null) {
            return $current;
        }

        $option = self::START_OPTIONS[$startOption] ?? self::START_OPTIONS['default'];
        if (($option['cost'] ?? 0) > 0 && !$this->hasUnlock($userId, 'start:' . $startOption)) {
            throw new Match3NodeException('起点未解锁: ' . $startOption, Match3NodeException::CODE_RUN_NOT_FOUND);
        }

        $meta  = $this->getMeta($userId);
        $slots = $this->startRelicSlots($meta);

        $relics = [];
        if (!empty($option['relic'])) {
            $relics[] = ['id' => $option['relic'], 'obtainedAt' => '0-0', 'lv' => 1];
        }

        // 起始遗物位（meta 解锁 relic_slot）
        $startRelics = array_slice(array_values(array_unique(array_filter(array_map('strval', $startRelics)))), 0, $slots);
        if (count($startRelics) > $slots) {
            throw new Match3NodeException("起始遗物位不足（已解锁 {$slots} 个）", Match3NodeException::CODE_RELIC_MISSING);
        }
        // 起始遗物必须已在奖池内（已解锁），避免白拿付费遗物
        $pool = (new RelicService())->pool((array) ($meta['unlocked_relics'] ?? []));
        foreach ($startRelics as $relicId) {
            $found = false;
            foreach ($pool as $r) {
                if ((string) $r['relic_id'] === $relicId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new Match3NodeException('起始遗物不可用: ' . $relicId, Match3NodeException::CODE_RELIC_MISSING);
            }
            $relics[] = ['id' => $relicId, 'obtainedAt' => '0-0', 'lv' => 1];
        }

        $mapSeed   = $fixedMapSeed ?? random_int(1, 0x7FFFFFFF);
        $levelSeed = random_int(1, 0x7FFFFFFF);

        $mapData = Match3MapGenerator::generate($mapSeed, self::DEFAULT_LAYERS, $this->stagePoolForMap());

        $runId = 'run_' . bin2hex(random_bytes(6));

        $run = Match3Run::create([
            'run_id'       => $runId,
            'user_id'      => $userId,
            'map_seed'     => $mapSeed,
            'level_seed'   => $levelSeed,
            'mode'         => Match3Run::MODE_NORMAL,
            'start_option' => $startOption,
            'layer'        => 1,
            'node_index'   => 0,
            'lives'        => (int) $option['lives'],
            'coins'        => 0,
            'relics'       => $relics,
            'map_data'     => $mapData,
            'buffs'        => Match3Run::defaultBuffs(),
            'status'       => self::STATUS_PLAYING,
            'score'        => 0,
            'meta_reward'  => 0,
            'create_time'   => date('Y-m-d H:i:s'),
        ]);

        return $this->decorateRun($run->toArray());
    }

    /**
     * 开始（或继续）当日挑战（05 §B7.5）
     *
     * 与普通局的关键区别：**种子与遗物池来自 match3_daily_challenge**，
     * 当日全服一致；同时每天只允许 1 个进行中的挑战局。
     *
     * @return array<string, mixed>
     * @throws Match3NodeException
     */
    public function startDaily(int $userId): array
    {
        $daily = (new DailyService())->ensure();

        $current = Match3Run::where('user_id', $userId)
            ->where('mode', Match3Run::MODE_DAILY)
            ->where('status', self::STATUS_PLAYING)
            ->order('id', 'desc')
            ->find();

        if ($current) {
            return $this->decorateRun($current->toArray());
        }

        $relics = [];
        if (!empty($daily['relic_pool'][0])) {
            // 每日挑战的起始遗物固定为遗物序列第 1 个
            $relics[] = ['id' => (string) $daily['relic_pool'][0], 'obtainedAt' => '0-0', 'lv' => 1];
        }

        $mapData = Match3MapGenerator::generate((int) $daily['map_seed'], (int) $daily['layers'], $this->stagePoolForMap());

        $run = Match3Run::create([
            'run_id'       => 'run_' . bin2hex(random_bytes(6)),
            'user_id'      => $userId,
            'map_seed'     => (int) $daily['map_seed'],
            'level_seed'   => (int) $daily['level_seed'],
            'mode'         => Match3Run::MODE_DAILY,
            'daily_day'    => (string) $daily['day'],
            'start_option' => 'default',
            'layer'        => 1,
            'node_index'   => 0,
            'lives'        => self::BASE_LIVES,
            'coins'        => 0,
            'relics'       => $relics,
            'map_data'     => $mapData,
            'buffs'        => Match3Run::defaultBuffs(),
            'status'       => self::STATUS_PLAYING,
            'create_time'   => date('Y-m-d H:i:s'),
        ]);

        return $this->decorateRun($run->toArray());
    }

    /**
     * 起始遗物位数量（05 §B7.4：解锁 "relic_slot" 各 +1）
     *
     * @param array<string,mixed> $meta
     */
    public function startRelicSlots(array $meta): int
    {
        $slots = 0;
        foreach ((array) ($meta['unlocked_relics'] ?? []) as $key) {
            if (str_starts_with((string) $key, 'relic_slot')) {
                $slots++;
            }
        }

        return $slots;
    }

    /**
     * 当前进行中的局（断点续玩入口）
     *
     * @param string|null $mode null = 不限模式；默认只看普通局，避免每日挑战抢占
     * @return array<string, mixed>|null
     */
    public function getCurrentRun(int $userId, ?string $mode = Match3Run::MODE_NORMAL): ?array
    {
        $query = Match3Run::where('user_id', $userId)->where('status', self::STATUS_PLAYING);
        if ($mode !== null) {
            $query->where('mode', $mode);
        }

        $row = $query->order('id', 'desc')->find();

        return $row ? $this->decorateRun($row->toArray()) : null;
    }

    /* ======================================================================
     *  节点
     * ====================================================================== */

    /**
     * 进入节点
     *
     * 透传三样东西：
     *   ① meta 已解锁遗物（决定奖池）
     *   ② 步数加成 = meta 起始步数 + 本局商店/事件购买的 buffs.moves
     *   ③ 每日挑战的固定遗物池
     *
     * @return array<string, mixed>
     * @throws Match3NodeException
     */
    public function enterNode(int $userId, string $runId, string $nodeId): array
    {
        $meta = $this->getMeta($userId);
        $run  = $this->loadRun($userId, $runId);

        $bonus = (int) ($meta['start_moves_bonus'] ?? 0) + (int) ($run['buffs']['moves'] ?? 0);

        $out = $this->nodes->enterNode(
            $userId,
            $runId,
            $nodeId,
            (array) ($meta['unlocked_relics'] ?? []),
            $bonus,
            $this->dailyPool($run)
        );

        // 遗物 /run/* patch 的产物固化到 match3_run.buffs（metaMul / shopPriceMul / freeRerollPerLevel …）
        $flags = $this->nodes->getRunFlags();
        if ($flags !== []) {
            $out['buffs'] = $this->mergeRunFlags($runId, $flags);
        }

        return $out;
    }

    /**
     * 当前可进入的节点
     *
     * @return array{available:array<int,string>, cleared:array<int,string>, current:string}
     */
    public function availableNodes(int $userId, string $runId): array
    {
        return $this->nodes->availableNodes($userId, $runId);
    }

    /**
     * 节点通关
     *
     * @param  array<string,int> $goals 目标进度（写库留档）
     * @return array{
     *     reward: array<string,int>, lives: int, coins: int, totalScore: int,
     *     cleared: bool, nextNodes: array<int,string>, relicChoices: array
     * }
     * @throws Match3NodeException
     */
    public function completeNode(
        int $userId,
        string $runId,
        string $nodeId,
        int $score = 0,
        int $movesUsed = 0,
        array $goals = []
    ): array {
        $run = $this->loadRun($userId, $runId);

        $node = Match3RunNode::where('run_id', $runId)->where('node_id', $nodeId)->find();
        if (!$node) {
            throw new Match3NodeException('节点不存在: ' . $nodeId, Match3NodeException::CODE_NODE_NOT_FOUND);
        }
        if ((int) $node->status !== Match3RunNode::STATUS_PLAYING) {
            throw new Match3NodeException('节点已结算: ' . $nodeId, Match3NodeException::CODE_NODE_SETTLED);
        }

        $nodeType = (string) $node->node_type;
        $coins    = $this->rollReward((int) $run['level_seed'], $nodeType, $nodeId);

        // 灵玉倍率（05 §B7.3）：
        //   · 节点 rules.coinMul —— 遗物 r_coin_boost(×1.3) / r_cursed_narrow(×2) 的落地
        //   · buffs.coinPercent —— 商店/事件购买的百分比加成
        //   · buffs.eliteRewardMul —— r_meta_elite（精英奖励翻倍）
        $schema = is_array($node->final_schema ?? null) ? $node->final_schema : [];
        $buffs  = array_merge(Match3Run::defaultBuffs(), is_array($run['buffs'] ?? null) ? $run['buffs'] : []);

        $mul = (float) ($schema['rules']['coinMul'] ?? 1);
        $mul *= 1 + ((int) ($buffs['coinPercent'] ?? 0)) / 100;
        if ($nodeType === Match3NodeService::NODE_ELITE) {
            $mul *= max(0.1, (float) ($buffs['eliteRewardMul'] ?? 1));
        }

        $coins    = max(0, (int) round($coins * $mul));
        $newCoins = (int) $run['coins'] + $coins;

        $node->save([
            'status'      => Match3RunNode::STATUS_PASSED,
            'score'       => $score,
            'moves_used'  => $movesUsed,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        $totalScore = (int) $run['score'] + $score;

        Match3Run::where('run_id', $runId)->update([
            'coins' => $newCoins,
            'score' => $totalScore,
        ]);

        $nextNodes = Match3MapGenerator::nextNodeIds($run['map_data'], $nodeId);
        $isLast    = self::isFinalNode($run['map_data'], $nodeId);
        $cleared   = $nodeType === Match3NodeService::NODE_BOSS && $isLast;

        if ($cleared) {
            $this->finishRun($userId, $runId, true);
        }

        return [
            'reward'       => ['coins' => $coins, 'score' => $score],
            'lives'        => (int) $run['lives'],
            'coins'        => $newCoins,
            'totalScore'   => $totalScore,
            'cleared'      => $cleared,
            'nextNodes'    => $nextNodes,
            'relicChoices' => is_array($node->toArray()['relic_choices'] ?? null) ? $node->toArray()['relic_choices'] : [],
        ];
    }

    /**
     * 节点失败
     *
     * @return array{lives:int, runEnded:bool, nextNodes:array<int,string>}
     * @throws Match3NodeException
     */
    public function failNode(int $userId, string $runId, string $nodeId): array
    {
        $run = $this->loadRun($userId, $runId);

        $lives = max(0, (int) $run['lives'] - 1);
        $ended = $lives <= 0;

        Match3Run::where('run_id', $runId)->update(['lives' => $lives]);

        Match3RunNode::where('run_id', $runId)->where('node_id', $nodeId)->update([
            'status'      => Match3RunNode::STATUS_FAILED,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        if ($ended) {
            $this->finishRun($userId, $runId, false);
        }

        return [
            'lives'     => $lives,
            'runEnded'  => $ended,
            'nextNodes' => $ended ? [] : Match3MapGenerator::nextNodeIds($run['map_data'], $nodeId),
        ];
    }

    /* ======================================================================
     *  遗物（05 §B4 接口 5/6）
     * ====================================================================== */

    /**
     * 选择遗物（三选一）
     *
     * @return array{relics:array<int,array<string,mixed>>, picked:array<string,mixed>}
     * @throws Match3NodeException
     */
    public function pickRelic(int $userId, string $runId, string $nodeId, string $relicId): array
    {
        $run = $this->loadRun($userId, $runId);

        $node = Match3RunNode::where('run_id', $runId)->where('node_id', $nodeId)->find();
        if (!$node) {
            throw new Match3NodeException('节点不存在: ' . $nodeId, Match3NodeException::CODE_NODE_NOT_FOUND);
        }

        $choices = is_array($node->toArray()['relic_choices'] ?? null) ? $node->toArray()['relic_choices'] : [];
        $picked  = null;
        foreach ($choices as $c) {
            if (($c['id'] ?? '') === $relicId) {
                $picked = $c;
                break;
            }
        }
        if ($picked === null) {
            throw new Match3NodeException('遗物不在本次候选中: ' . $relicId, Match3NodeException::CODE_RELIC_MISSING);
        }

        $relics = (array) $run['relics'];
        foreach ($relics as $r) {
            if (($r['id'] ?? '') === $relicId) {
                throw new Match3NodeException('已持有该遗物: ' . $relicId, Match3NodeException::CODE_RELIC_MISSING);
            }
        }

        $relics[] = ['id' => $relicId, 'obtainedAt' => $nodeId];

        Match3Run::where('run_id', $runId)->update(['relics' => json_encode($relics, JSON_UNESCAPED_UNICODE)]);

        return ['relics' => $relics, 'picked' => $picked];
    }

    /**
     * 重选遗物
     *
     * 免费次数来自遗物 `r_meta_reroll`（`/run/freeRerollPerLevel`，记为 buffs.freeRerollPerLevel），
     * 用完后需要付费/看广告 —— 付费由客户端与广告服务完成，再带 `paid=true` 调一次。
     *
     * @return array{relicChoices:array<int,array<string,mixed>>, needPay:bool, freeLeft:int}
     * @throws Match3NodeException
     */
    public function rerollRelics(int $userId, string $runId, string $nodeId, bool $paid = false): array
    {
        $run = $this->loadRun($userId, $runId);

        $node = Match3RunNode::where('run_id', $runId)->where('node_id', $nodeId)->find();
        if (!$node) {
            throw new Match3NodeException('节点不存在: ' . $nodeId, Match3NodeException::CODE_NODE_NOT_FOUND);
        }

        $nodeArr = $node->toArray();
        $payload = is_array($nodeArr['node_payload'] ?? null) ? $nodeArr['node_payload'] : [];
        $used    = (int) ($payload['rerollUsed'] ?? 0);
        $free    = (int) ($run['buffs']['freeRerollPerLevel'] ?? 0);

        if ($used >= $free && !$paid) {
            return ['relicChoices' => [], 'needPay' => true, 'freeLeft' => 0];
        }

        $old  = is_array($nodeArr['relic_choices'] ?? null) ? $nodeArr['relic_choices'] : [];
        $meta = $this->getMeta($userId);

        $exclude = array_merge(
            array_column((array) $run['relics'], 'id'),
            array_column($old, 'id')
        );

        $service = new RelicService();
        $choices = $service->reroll(
            (string) $node->node_type,
            (int) $run['level_seed'],
            $nodeId,
            $exclude,
            RelicService::CHOICES,
            (array) ($meta['unlocked_relics'] ?? []),
            $this->dailyPool($run)
        );

        $payload['rerollUsed'] = $used + 1;

        Match3RunNode::where('id', (int) $node->id)->update([
            'relic_choices' => json_encode($choices, JSON_UNESCAPED_UNICODE),
            'node_payload'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'relicChoices' => $choices,
            'needPay'      => false,
            'freeLeft'     => max(0, $free - (int) $payload['rerollUsed']),
        ];
    }

    /**
     * 每日挑战的固定遗物池（非每日局返回 null）
     *
     * @param  array<string,mixed> $run
     * @return array<int,array<string,mixed>>|null
     */
    public function dailyPool(array $run): ?array
    {
        if (($run['mode'] ?? '') !== Match3Run::MODE_DAILY) {
            return null;
        }

        $daily = (new DailyService())->find((string) ($run['daily_day'] ?? ''));
        $ids   = is_array($daily['relic_pool'] ?? null) ? $daily['relic_pool'] : [];
        if ($ids === []) {
            return null;
        }

        return (new RelicService())->byIds($ids);
    }

    /* ======================================================================
     *  结算与局外进度
     * ====================================================================== */

    /**
     * 结束本局并结算灵石
     *
     * @return array{stone:int, cleared:bool, layers:int, score:int}
     * @throws Match3NodeException
     */
    public function finishRun(int $userId, string $runId, bool $cleared): array
    {
        $run = $this->loadRun($userId, $runId, false);

        $layers = (int) ($run['layer'] ?: 1);
        $score  = (int) ($run['score'] ?: 0);

        // buffs.metaMul（遗物 r_meta_stone ×1.5）+ buffs.stone（事件/每日挑战直给）
        $buffs = array_merge(Match3Run::defaultBuffs(), is_array($run['buffs'] ?? null) ? $run['buffs'] : []);
        $stone = (int) round(
            self::calcMetaReward($layers, $score, $cleared) * max(0.0, (float) ($buffs['metaMul'] ?? 1))
        ) + (int) ($buffs['stone'] ?? 0);

        Match3Run::where('run_id', $runId)->update([
            'status'      => $cleared ? self::STATUS_CLEARED : self::STATUS_FAILED,
            'meta_reward' => $stone,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        // 写入局外进度
        Match3MetaProgress::of($userId);

        // ① 灵石：走 InventoryService → 写 inventory_log（reason = rogue_finish）
        //    余额以**背包**为准；meta.spirit_stone 只是同事务同步的只读快照。
        $granted = 0;
        $balance = null;

        if ($stone > 0) {
            $balance = $this->bridge()->grantSpiritStone($userId, $stone, [
                'run_id'  => $runId,
                'cleared' => $cleared,
                'layers'  => $layers,
                'score'   => $score,
            ]);

            if ($this->bridge()->lastError() === null) {
                $granted = $stone;
            } else {
                // 发奖失败时**绝不同步快照** —— 否则等于凭空改账
                ContextualLogger::error('[match3] 灵石发放失败，快照未同步（余额以背包为准）', [
                    'user_id' => $userId, 'run_id' => $runId,
                    'stone'   => $stone, 'error' => $this->bridge()->lastError(),
                ]);
            }
        }

        $update = ['update_time' => date('Y-m-d H:i:s')];
        if ($granted > 0 && $balance !== null) {
            $update['spirit_stone'] = (int) $balance;
        }

        Match3MetaProgress::where('user_id', $userId)->inc('total_runs')->update($update);

        $meta = Match3MetaProgress::find($userId);
        if ($meta && $score > (int) $meta->best_score) {
            $meta->save(['best_score' => $score]);
        }

        return [
            'stone'   => $granted,
            'balance' => $balance,
            'cleared' => $cleared,
            'layers'  => $layers,
            'score'   => $score,
        ];
    }

    /**
     * 放弃本局（不发灵石，不计入完成数）
     *
     * @throws Match3NodeException
     */
    public function abandonRun(int $userId, string $runId): void
    {
        $this->loadRun($userId, $runId);

        Match3Run::where('run_id', $runId)->update([
            'status'      => self::STATUS_ABANDON,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 灵石产出（委托 RogueRules，纯逻辑可单测）
     */
    public static function calcMetaReward(int $layers, int $score, bool $cleared): int
    {
        return RogueRules::metaReward($layers, $score, $cleared);
    }

    /**
     * 局外进度（不存在则初始化）
     *
     * @return array<string, mixed>
     */
    public function getMeta(int $userId): array
    {
        $row = Match3MetaProgress::of($userId)->toArray();

        $row['unlocked_relics'] = is_array($row['unlocked_relics'] ?? null) ? $row['unlocked_relics'] : [];
        $row['unlocked_starts'] = is_array($row['unlocked_starts'] ?? null) ? $row['unlocked_starts'] : [];

        return $row;
    }

    /**
     * 灵石解锁
     *
     * @return array{ok:bool, reason?:string, stone?:int, bonus?:int}
     */
    public function unlockMeta(int $userId, string $unlockKey): array
    {
        $isRelic = str_starts_with($unlockKey, self::UNLOCK_RELIC_PREFIX);

        if ($isRelic) {
            $relicId = substr($unlockKey, strlen(self::UNLOCK_RELIC_PREFIX));
            $relic   = Match3Relic::where('relic_id', $relicId)->find();
            if (!$relic) {
                return ['ok' => false, 'reason' => 'unknown_relic'];
            }
            $cost = (int) $relic->unlock_cost ?: self::UNLOCK_RELIC_COST;
        } else {
            $cost = self::UNLOCK_COSTS[$unlockKey] ?? null;
            if ($cost === null) {
                return ['ok' => false, 'reason' => 'unknown_unlock_key'];
            }
        }

        if ($this->hasUnlock($userId, $unlockKey)) {
            return ['ok' => false, 'reason' => 'already_unlocked'];
        }

        // 灵石余额以**背包**为准（P6-B04），扣减走 InventoryService（防超卖 + 记流水）
        $balance = $this->bridge()->getBalance($userId, Match3Constants::ITEM_SPIRIT_STONE);
        if ($balance < $cost) {
            return ['ok' => false, 'reason' => 'insufficient_stone', 'stone' => $balance];
        }

        $res = $this->bridge()->consumeSpiritStone($userId, $cost, ['unlock_key' => $unlockKey]);
        if (!$res['ok']) {
            return ['ok' => false, 'reason' => 'insufficient_stone', 'stone' => $balance];
        }

        Match3MetaProgress::where('user_id', $userId)->update([
            'spirit_stone' => (int) $res['balance'],
            'update_time'   => date('Y-m-d H:i:s'),
        ]);

        $bonus = 0;
        if (str_starts_with($unlockKey, 'start_move')) {
            Match3MetaProgress::where('user_id', $userId)->inc('start_moves_bonus')->update(['update_time' => date('Y-m-d H:i:s')]);
            $bonus = (int) $this->getMeta($userId)['start_moves_bonus'];
        } else {
            $meta = $this->getMeta($userId);

            if ($isRelic) {
                $field  = 'unlocked_relics';
                $record = substr($unlockKey, strlen(self::UNLOCK_RELIC_PREFIX));
            } elseif ($unlockKey === 'character') {
                $field  = 'unlocked_starts';
                $record = $unlockKey;
            } else {
                $field  = 'unlocked_relics';
                $record = $unlockKey; // relic_slot / relic_slot_2 ...
            }

            $list = (array) $meta[$field];
            if (!in_array($record, $list, true)) {
                $list[] = $record;
            }
            Match3MetaProgress::where('user_id', $userId)->update([
                $field       => json_encode($list, JSON_UNESCAPED_UNICODE),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }

        return ['ok' => true, 'stone' => (int) $this->getMeta($userId)['spirit_stone'], 'bonus' => $bonus];
    }

    /**
     * 是否已解锁
     */
    public function hasUnlock(int $userId, string $key): bool
    {
        $meta = $this->getMeta($userId);

        if (str_starts_with($key, self::UNLOCK_RELIC_PREFIX)) {
            return in_array(substr($key, strlen(self::UNLOCK_RELIC_PREFIX)), $meta['unlocked_relics'], true);
        }
        if (str_starts_with($key, 'start:')) {
            return in_array(substr($key, 6), $meta['unlocked_starts'], true);
        }
        if (str_starts_with($key, 'start_move')) {
            return (int) $meta['start_moves_bonus'] >= (int) substr($key, -1);
        }
        if (in_array($key, $meta['unlocked_starts'], true) || in_array($key, $meta['unlocked_relics'], true)) {
            return true;
        }

        return false;
    }

    /* ======================================================================
     *  内部
     * ====================================================================== */

    /**
     * @return array<string, mixed>
     * @throws Match3NodeException
     */
    protected function loadRun(int $userId, string $runId, bool $requirePlaying = true): array
    {
        $run = Match3Run::where('run_id', $runId)->find();

        if (!$run) {
            throw new Match3NodeException('肉鸽局不存在: ' . $runId, Match3NodeException::CODE_RUN_NOT_FOUND);
        }
        if ((int) $run->user_id !== $userId) {
            throw new Match3NodeException('无权访问该局', Match3NodeException::CODE_RUN_NOT_FOUND);
        }
        if ($requirePlaying && (int) $run->status !== self::STATUS_PLAYING) {
            throw new Match3NodeException('本局已结束', Match3NodeException::CODE_RUN_FINISHED);
        }

        return $this->decorateRun($run->toArray());
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    protected function decorateRun(array $run): array
    {
        $run['relics']   = is_array($run['relics'] ?? null) ? $run['relics'] : [];
        $run['map_data'] = is_array($run['map_data'] ?? null) ? $run['map_data'] : [];
        $run['buffs']    = array_merge(Match3Run::defaultBuffs(), is_array($run['buffs'] ?? null) ? $run['buffs'] : []);

        return $run;
    }

    /* ======================================================================
     *  局内节点交互（Phase 4：商店 / 休息 / 事件）
     * ====================================================================== */

    /**
     * 加载并校验"当前正在访问的节点"
     *
     * 规则：
     *   ① run 属于该用户且进行中
     *   ② 节点行存在、类型匹配
     *   ③ 该节点必须是 run 的**当前节点**（离开后不能再回来交易）
     *   ④ 一次性节点（rest/event）不能重复结算
     *
     * @return array{run:array<string,mixed>, node:array<string,mixed>}
     * @throws Match3NodeException
     */
    public function loadCurrentNode(int $userId, string $runId, string $nodeId, string $expectType): array
    {
        $run  = $this->loadRun($userId, $runId);
        $node = Match3RunNode::where('run_id', $runId)->where('node_id', $nodeId)->find();

        if (!$node) {
            throw new Match3NodeException('节点不存在: ' . $nodeId, Match3NodeException::CODE_NODE_NOT_FOUND);
        }
        if ((string) $node->node_type !== $expectType) {
            throw new Match3NodeException("节点类型不匹配（期望 {$expectType}）", Match3NodeException::CODE_NODE_NOT_FOUND);
        }

        $mapNode = Match3MapGenerator::findNode($run['map_data'], $nodeId);
        if ($mapNode === null) {
            throw new Match3NodeException('节点不在地图中: ' . $nodeId, Match3NodeException::CODE_NODE_NOT_FOUND);
        }

        if ((int) $run['layer'] !== (int) $mapNode['layer'] || (int) $run['node_index'] !== (int) $mapNode['index']) {
            throw new Match3NodeException('已离开该节点，无法继续交易', Match3NodeException::CODE_NODE_SETTLED);
        }

        return ['run' => $run, 'node' => $node->toArray()];
    }

    /**
     * 写回局内可变状态（灵玉 / 机会 / 遗物 / buffs）
     *
     * @param array<string,mixed> $run
     */
    public function persistRunState(string $runId, array $run): void
    {
        Match3Run::where('run_id', $runId)->update([
            'coins'  => (int) $run['coins'],
            'lives'  => (int) $run['lives'],
            'relics' => json_encode(array_values((array) $run['relics']), JSON_UNESCAPED_UNICODE),
            'buffs'  => json_encode((array) $run['buffs'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 写回节点 payload（商店已购项 / 休息选择 / 事件选择）
     *
     * @param array<string,mixed> $payload
     */
    public function persistNodePayload(int $nodeRowId, array $payload, bool $settled = false): void
    {
        $data = [
            'node_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];
        if ($settled) {
            $data['status']      = Match3RunNode::STATUS_PASSED;
            $data['finished_at'] = date('Y-m-d H:i:s');
        }

        Match3RunNode::where('id', $nodeRowId)->update($data);
    }

    /**
     * 把节点 schema 里的 `run` 段（遗物 /run/* patch 的产物）并入本局 buffs
     *
     * 例：`r_meta_stone` 的 `mul /run/metaMul 1.5` 会落到 final_schema.run.metaMul，
     * 这里把它固化到 `match3_run.buffs`，供结算与商店读取。
     * 因为 patch 产出的是**绝对值**，用 array_replace 合并天然幂等。
     *
     * @param array<string,mixed> $flags  final_schema.run
     */
    public function mergeRunFlags(string $runId, array $flags): array
    {
        if ($flags === []) {
            return [];
        }

        $row = Match3Run::where('run_id', $runId)->find();
        if (!$row) {
            return [];
        }

        $buffs = array_merge(Match3Run::defaultBuffs(), is_array($row->buffs) ? $row->buffs : []);
        $buffs = array_replace($buffs, $flags);

        Match3Run::where('run_id', $runId)->update([
            'buffs' => json_encode($buffs, JSON_UNESCAPED_UNICODE),
        ]);

        return $buffs;
    }

    /**
     * 读取本局的遗物配置（含已加载的 patch，供商店/事件使用）
     *
     * @param  array<int,array<string,mixed>> $runRelics
     * @return array<int,array<string,mixed>>
     */
    public function relicConfigs(array $runRelics): array
    {
        return (new RelicService())->byIds(array_column($runRelics, 'id'));
    }

    /**
     * 节点灵玉奖励（按 seed 确定性取值）
     */
    protected function rollReward(int $levelSeed, string $nodeType, string $nodeId): int
    {
        $range = RogueRules::rewardRange($nodeType);
        $salt  = (int) preg_replace('/\D/', '', $nodeId) ?: 0;

        return Match3Random::intBetween($levelSeed, $range[0], $range[1], $salt);
    }

    /**
     * 是否为最后一个节点（委托 RogueRules，纯逻辑可单测）
     *
     * @param array<string, mixed> $mapData
     */
    public static function isFinalNode(array $mapData, string $nodeId): bool
    {
        return RogueRules::isFinalNode($mapData, $nodeId);
    }
}
