<?php

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

use think\facade\Cache;
use plugin\match3game\model\Match3LevelDef;
use plugin\match3game\model\Match3Run;
use plugin\match3game\model\Match3RunNode;
use plugin\match3game\service\level\DifficultyCurve;
use plugin\match3game\service\level\LevelGenerationService;
use plugin\match3game\service\level\LevelSchemaFactory;
use plugin\match3game\service\level\LevelValidator;
use plugin\match3game\service\level\Match3Random;
use plugin\match3game\service\level\StableStringify;
use wise\support\context\ContextualLogger;

/**
 * 肉鸽节点服务 —— 关卡构建主流程
 *
 * 玩家进入节点时，生成"这个玩家专属的这一关"：
 *   ① 取 base schema（关卡模板 match3_level_def）
 *   ② 生成关卡内容（LevelGenerationService：生成 + 目标反标定 + 通关率体检）
 *   ③ 应用遗物 patch（PatchEngine，确定性）
 *   ④ 节点类型修正（elite / boss 加成）
 *   ⑤ 校验（LevelValidator，防 patch 把关卡改坏）
 *   ⑥ 存盘（final_schema 作为重放校验的初始条件）
 *   ⑦ 下发最终 schema 给客户端
 *
 * 【与 code/ 参考实现的差异（重要）】
 *   ① 不再调用 `applyDifficulty`：难度 D 已由 DifficultyCurve + 目标反标定烘焙进关卡，
 *      再乘一次会让高 D 关卡必然不可通关（见 07 文档 Phase 2 的实测数据）。
 *   ② 每个节点用**派生 seed**（level_seed + crc32(nodeId)），而不是全 run 共用一个 seed，
 *      否则同局所有节点会长得一模一样。
 *   ③ 生成器改为 LevelGenerationService（数组进出），并返回闯关质量指标。
 *   ④ 删除 `Cache::remember`（缓存不可用时不应影响主流程），改为 try/catch 手动读写。
 *   ⑤ 新增可达性校验（防跳关）与非战斗节点的自动结算占位。
 *
 * 【依赖表】
 *   match3_run / match3_run_node / match3_level_def / match3_relic
 */
class Match3NodeService
{
    /* ---------------- 节点类型 ---------------- */
    public const NODE_BATTLE = 'battle';
    public const NODE_ELITE  = 'elite';
    public const NODE_BOSS   = 'boss';
    public const NODE_SHOP   = 'shop';
    public const NODE_REST   = 'rest';
    public const NODE_EVENT  = 'event';

    /** 战斗类节点（需要构建关卡） */
    public const COMBAT_TYPES = ['battle', 'elite', 'boss'];

    /** 遗物稀有度 → 强度权重（用于难度补偿） */
    public const RARITY_POWER = [
        'common' => 1,
        'rare'   => 2,
        'epic'   => 3,
        'cursed' => 1,
    ];

    /** 节点修正系数 */
    public const ELITE_GOAL_MUL = 1.25;
    public const ELITE_MOVE_MUL = 0.9;
    public const BOSS_GOAL_MUL  = 1.5;
    public const BOSS_BASE_HP   = 1000;

    /** final_schema 缓存时间（秒） */
    public const CACHE_TTL = 3600;

    protected LevelGenerationService $generation;

    protected RelicService $relics;

    /** 是否启用关卡缓存 */
    protected bool $useCache = true;

    /** 是否校验节点可达性（防跳关） */
    protected bool $checkReachable = true;

    /**
     * 最近一次 buildLevel 产出的遗物 /run/* 标记
     *
     * @var array<string,mixed>
     */
    protected array $runFlags = [];

    /**
     * 取最近一次构建产生的 /run/* 标记（由 Match3RunService 固化到 match3_run.buffs）
     *
     * @return array<string,mixed>
     */
    public function getRunFlags(): array
    {
        return $this->runFlags;
    }

    public function __construct(
        ?LevelGenerationService $generation = null,
        ?RelicService $relics = null,
        array $config = []
    ) {
        $this->generation = $generation ?? new LevelGenerationService(null, null, (array) ($config['generation'] ?? []));
        $this->relics     = $relics ?? new RelicService();

        $this->useCache       = (bool) ($config['node_cache'] ?? true);
        $this->checkReachable = (bool) ($config['check_reachable'] ?? true);
    }

    public function setGenerator(LevelGenerationService $generation): self
    {
        $this->generation = $generation;
        return $this;
    }

    /* ======================================================================
     *  对外主流程
     * ====================================================================== */

    /**
     * 进入节点
     *
     * @param  array<int,string> $unlockedRelicIds meta 中已解锁的遗物 ID
     * @param  int               $startMovesBonus  meta 解锁的"起始步数 +N"
     * @return array{
     *     playToken:string, nodeId:string, nodeType:string, D:float,
     *     lives:int, coins:int, stage:mixed, appliedRelics:array,
     *     schemaFingerprint:string, relicChoices:array, autoCleared:bool,
     *     nextNodes:array, quality:array
     * }
     * @throws Match3NodeException
     */
    public function enterNode(
        int $userId,
        string $runId,
        string $nodeId,
        array $unlockedRelicIds = [],
        int $startMovesBonus = 0,
        ?array $relicPoolOverride = null
    ): array {
        // ① 加载本局
        $run = $this->loadRun($userId, $runId);

        // ② 节点信息
        $node = Match3MapGenerator::findNode($run['map_data'], $nodeId);
        if ($node === null) {
            throw new Match3NodeException('节点不在地图中: ' . $nodeId, Match3NodeException::CODE_NODE_NOT_FOUND);
        }
        $nodeType = (string) ($node['type'] ?? self::NODE_BATTLE);

        // ③ 可达性校验（防跳关）
        if ($this->checkReachable) {
            $cleared = $this->clearedNodeIds($runId);
            if (!Match3MapGenerator::isReachable($run['map_data'], $nodeId, $cleared)) {
                throw new Match3NodeException(
                    '节点不可达: ' . $nodeId,
                    Match3NodeException::CODE_NODE_NOT_FOUND
                );
            }
        }

        // ④ 已生成过（断点续玩 / 失败重试）→ 复用同一份 schema，保证分毫不差
        $existing = $this->findNodeRow($runId, $nodeId);
        if ($existing !== null) {
            return $this->buildResponse($this->reopenNode($existing), $run);
        }

        // ⑤ 非战斗节点（shop/rest/event）本期自动结算占位，Phase 4 接入真实逻辑
        if (!in_array($nodeType, self::COMBAT_TYPES, true)) {
            return $this->enterNonCombatNode($run, $node);
        }

        // ⑥ 遗物 → 难度补偿（并把 run 里记录的升级等级 lv 合并进配置）
        $relics     = $this->withRelicLevels($run['relics']);
        $relicPower = RelicService::power($relics);

        $layer = (int) ($node['layer'] ?? 1);
        $index = (int) ($node['index'] ?? 0);
        $D     = DifficultyCurve::D($layer, $index, $relicPower);

        // ⑦ 每节点派生 seed（否则同局所有节点长得一样）
        $nodeSeed = Match3Random::deriveSeed((int) $run['level_seed'], (int) crc32($nodeId));

        // ⑧ 取 base schema + generation 配置
        $baseRow    = $this->loadBaseLevel((string) ($node['stageCode'] ?? ''));
        $base       = LevelSchemaFactory::fromRow($baseRow);
        $generation = is_array($baseRow['generation'] ?? null) && $baseRow['generation'] !== []
            ? $baseRow['generation']
            : ['mode' => 'fixed'];

        // ⑨ 构建最终关卡
        $final = $this->buildLevel($base, $generation, $nodeSeed, $D, $nodeId, $nodeType, $relics, $quality, $startMovesBonus);

        // ⑨b 把遗物 /run/* patch 的产物（metaMul / shopPriceMul / eliteRewardMul …）固化到本局 buffs
        if (isset($final['run']) && is_array($final['run'])) {
            $this->runFlags = (array) $final['run'];
        }

        // ⑩ 存盘 + playToken + 本次遗物三选一（每日挑战用当日固定遗物池）
        $relicChoices = in_array($nodeType, self::COMBAT_TYPES, true)
            ? $this->relics->drawChoices(
                $nodeType,
                (int) $run['level_seed'],
                $nodeId,
                array_column($run['relics'], 'id'),
                $unlockedRelicIds,
                $relicPoolOverride
            )
            : [];

        $playToken = $this->saveNode($runId, $nodeId, $nodeType, $final, $D, $relicChoices);

        // ⑪ 更新本局当前位置
        $this->updateRunPosition($runId, $layer, $index);

        $row = $this->findNodeRow($runId, $nodeId) ?? [];
        $row['play_token']    = $playToken;
        $row['relic_choices'] = $relicChoices;

        return $this->buildResponse($row, $run, $playToken, $quality, []);
    }

    /**
     * 当前可进入的节点（供客户端渲染地图选择）
     *
     * @return array{available:array<int,string>, cleared:array<int,string>, current:string}
     */
    public function availableNodes(int $userId, string $runId): array
    {
        $run     = $this->loadRun($userId, $runId);
        $cleared = $this->clearedNodeIds($runId);

        return [
            'available' => Match3MapGenerator::availableNodeIds($run['map_data'], $cleared),
            'cleared'   => $cleared,
            'current'   => ((int) $run['layer']) . '-' . ((int) $run['node_index']),
        ];
    }

    /**
     * 供重放校验使用：按 playToken 取回该节点的最终 schema 行
     *
     * @return array<string, mixed>|null
     */
    public function getByPlayToken(string $playToken): ?array
    {
        $row = Match3RunNode::where('play_token', $playToken)->find();

        return $row ? $row->toArray() : null;
    }

    /**
     * 取节点最终 schema（数组形态，供重放使用）
     *
     * @return array<string, mixed>|null
     */
    public function getNodeSchema(string $runId, string $nodeId): ?array
    {
        $row = Match3RunNode::where('run_id', $runId)->where('node_id', $nodeId)->find();
        if (!$row) {
            return null;
        }

        $arr = $row->toArray();

        return is_array($arr['final_schema'] ?? null) ? $arr['final_schema'] : null;
    }

    /* ======================================================================
     *  纯逻辑（static，可单测）
     * ====================================================================== */

    /**
     * 节点类型修正（elite / boss）
     *
     * 注意：**不再**把 Boss 关的通关目标替换为 `{"type":"boss"}` ——
     * Boss 伤害结算在客户端，服务端本期无法判定该目标，替换会导致 Boss 关被"自动通过"。
     * 这里只注入 actors（渲染用）并放大目标，保留原有的可判定目标。
     *
     * @param  array<string,mixed> $level
     * @return array<string,mixed>
     */
    public static function applyNodeModifiers(array $level, string $nodeType, float $D = 1.0): array
    {
        if ($nodeType !== self::NODE_ELITE && $nodeType !== self::NODE_BOSS) {
            return $level;
        }

        $goalMul = $nodeType === self::NODE_ELITE ? self::ELITE_GOAL_MUL : self::BOSS_GOAL_MUL;

        if (!empty($level['rules']['goals']) && is_array($level['rules']['goals'])) {
            foreach ($level['rules']['goals'] as $i => $g) {
                if (isset($g['count']) && is_numeric($g['count'])) {
                    $level['rules']['goals'][$i]['count'] = max(1, (int) round($g['count'] * $goalMul));
                }
            }
        }

        if ($nodeType === self::NODE_ELITE && isset($level['rules']['moves'])) {
            $level['rules']['moves'] = max(3, (int) round($level['rules']['moves'] * self::ELITE_MOVE_MUL));
        }

        if ($nodeType === self::NODE_BOSS) {
            $level['actors'] = [[
                'id'         => 'boss_node',
                'kind'       => 'boss',
                'name'       => '关卡首领',
                'anchor'     => 'top',
                'hp'         => (int) round(self::BOSS_BASE_HP * $D),
                'armor'      => 0,
                'damageRule' => [
                    'perGem'   => 10,
                    'specials' => ['stripe' => 50, 'bomb' => 80, 'rainbow' => 150],
                    'comboMul' => [1, 1.2, 1.5, 2.0, 2.5, 3.0],
                ],
                'skills'     => [
                    ['every' => 3, 'action' => 'spawnBlocker', 'count' => 2, 'target' => 'randomEmpty'],
                ],
            ]];
        }

        return $level;
    }

    /**
     * 难度系数（委托 DifficultyCurve，保持与生成器/模拟器同一份公式）
     */
    public static function calcDifficulty(int $layer, int $nodeIndex, int $relicPower = 0): float
    {
        return DifficultyCurve::D($layer, $nodeIndex, (float) $relicPower);
    }

    /**
     * 难度 → 步数
     */
    public static function movesFor(float $D, int $baseMoves = DifficultyCurve::BASE_MOVES): int
    {
        return $D <= 0 ? $baseMoves : DifficultyCurve::moves($D);
    }

    /**
     * 缓存键
     *
     * @param array<int, string> $relicIds
     */
    public static function cacheKey(string $baseCode, float $D, array $relicIds, string $nodeId): string
    {
        sort($relicIds);
        return sprintf(
            'm3_node_%s_%s_%s_%s',
            $baseCode,
            number_format($D, 2, '.', ''),
            md5(implode(',', $relicIds)),
            md5($nodeId)
        );
    }

    /* ======================================================================
     *  内部：关卡构建
     * ====================================================================== */

    /**
     * @param  array<string,mixed> $base
     * @param  array<string,mixed> $generation
     * @param  array<int,array<string,mixed>> $relics
     * @param  array<string,mixed>|null $quality 出参：生成质量指标
     * @return array<string,mixed> 最终 schema
     * @throws Match3NodeException
     */
    protected function buildLevel(
        array $base,
        array $generation,
        int $seed,
        float $D,
        string $nodeId,
        string $nodeType,
        array $relics,
        ?array &$quality = null,
        int $startMovesBonus = 0
    ): array {
        // 起始步数加成参与缓存键（不同加成是不同的关卡）
        $this->runFlags = [];

        // 升级等级（lv）参与缓存键：同一遗物 Lv1 与 Lv2 是不同的关卡
        $lvSig = [];
        foreach ($relics as $r) {
            $lvSig[] = (string) ($r['relic_id'] ?? '') . '@' . max(1, (int) ($r['lv'] ?? 1));
        }

        $cacheSalt = $nodeId . '+m' . $startMovesBonus . '+' . md5(implode(',', $lvSig));
        $cacheKey  = self::cacheKey((string) ($base['code'] ?? ''), $D, array_column($relics, 'relic_id'), $cacheSalt);

        if ($this->useCache) {
            try {
                $cached = Cache::get($cacheKey);
                if (is_array($cached) && $cached !== []) {
                    $quality = [];
                    return $cached;
                }
            } catch (\Throwable $e) {
                // 缓存不可用不影响主流程
            }
        }

        // ① 生成 + 目标反标定 + 通关率体检
        $built   = $this->generation->generate($base, $generation, $seed, $D, $nodeId);
        $level   = (array) ($built['schema'] ?? []);
        $quality = (array) ($built['quality'] ?? []);

        // ①b 规范化：保证遗物 patch 引用的路径（rules.specials / board / regions）都存在
        $level = LevelSchemaFactory::normalize($level);

        if ($level === []) {
            throw new Match3NodeException(
                '关卡生成失败: ' . $nodeId,
                Match3NodeException::CODE_LEVEL_INVALID,
                (array) ($built['errors'] ?? [])
            );
        }

        // ② 应用遗物 patch（确定性，顺序 = 获得顺序）
        //    升级过的遗物（lv≥2）重复应用 patch —— 只允许"可升级"遗物被升级，
        //    因此这里重复应用一定有意义（add accumulate / mul）。
        $expanded = [];
        foreach ($relics as $r) {
            $lv = max(1, (int) ($r['lv'] ?? 1));
            for ($i = 0; $i < $lv; $i++) {
                $expanded[] = $r;
            }
        }

        $result = PatchEngine::applyRelics($level, $expanded);
        if ($result->hasSkipped()) {
            ContextualLogger::warning('[Match3Node] patch 有跳过项', [
                'node'    => $nodeId,
                'skipped' => $result->skipped,
            ]);
        }
        $level = is_array($result->doc) ? $result->doc : json_decode(json_encode($result->doc), true);

        // ③ 节点类型修正
        $level = self::applyNodeModifiers((array) $level, $nodeType, $D);

        // ③b meta 解锁的"起始步数 +N"
        if ($startMovesBonus > 0 && isset($level['rules']['moves'])) {
            $level['rules']['moves'] = (int) $level['rules']['moves'] + $startMovesBonus;
        }

        // ④ 校验（防 patch 把关卡改坏）
        $errors = LevelValidator::validate($level);
        if ($errors !== []) {
            throw new Match3NodeException(
                '关卡校验失败（patch 可能把关卡改坏）: ' . $nodeId,
                Match3NodeException::CODE_LEVEL_INVALID,
                $errors
            );
        }

        if ($this->useCache) {
            try {
                Cache::set($cacheKey, $level, self::CACHE_TTL);
            } catch (\Throwable $e) {
                // 忽略
            }
        }

        return $level;
    }

    /**
     * 非战斗节点（shop / rest / event）：建行但不结算
     *
     * Phase 4 起改为**显式交互**：客户端进入后调用对应端点（shop/rest/event）
     * 才会生成 payload，选择/离开后才标记节点通过。
     *
     * @param  array<string,mixed> $run
     * @param  array<string,mixed> $node
     * @return array<string,mixed>
     */
    protected function enterNonCombatNode(array $run, array $node): array
    {
        $runId    = (string) $run['run_id'];
        $nodeId   = (string) $node['id'];
        $nodeType = (string) $node['type'];

        Match3RunNode::create([
            'run_id'       => $runId,
            'node_id'      => $nodeId,
            'node_type'    => $nodeType,
            'final_schema' => [],
            'schema_fp'    => '',
            'difficulty'   => 1.0,
            'play_token'   => 'inter_' . bin2hex(random_bytes(8)),
            'status'       => Match3RunNode::STATUS_PLAYING,
            'create_time'   => date('Y-m-d H:i:s'),
        ]);

        $this->updateRunPosition($runId, (int) $node['layer'], (int) $node['index']);

        return [
            'playToken'         => '',
            'nodeId'            => $nodeId,
            'nodeType'          => $nodeType,
            'D'                 => 1.0,
            'lives'             => (int) $run['lives'],
            'coins'             => (int) $run['coins'],
            'stage'             => null,
            'appliedRelics'     => array_column($run['relics'], 'id'),
            'schemaFingerprint' => '',
            'relicChoices'      => [],
            'autoCleared'       => false,
            'interactive'       => true,
            'nextNodes'         => [],
            'quality'           => [],
        ];
    }

    /**
     * 把 run 里记录的遗物升级等级合并进遗物配置
     *
     * @param  array<int,array<string,mixed>> $runRelics run.relics（[{id,lv}]）
     * @return array<int,array<string,mixed>>
     */
    protected function withRelicLevels(array $runRelics): array
    {
        $relics = $this->relics->byIds(array_column($runRelics, 'id'));

        $lvMap = [];
        foreach ($runRelics as $r) {
            $lvMap[(string) ($r['id'] ?? '')] = max(1, (int) ($r['lv'] ?? 1));
        }

        foreach ($relics as $i => $r) {
            $relics[$i]['lv'] = $lvMap[(string) ($r['relic_id'] ?? '')] ?? 1;
        }

        return $relics;
    }

    /**
     * 复入已存在的节点：失败过的节点重开（新 play_token，schema 不变）
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function reopenNode(array $row): array
    {
        $token = (string) ($row['play_token'] ?? '');

        if ((int) ($row['status'] ?? 0) !== Match3RunNode::STATUS_PLAYING) {
            $token = 'pt_' . bin2hex(random_bytes(8));

            Match3RunNode::where('id', (int) $row['id'])->update([
                'status'      => Match3RunNode::STATUS_PLAYING,
                'play_token'  => $token,
                'score'       => 0,
                'moves_used'  => 0,
                'finished_at' => null,
            ]);

            $row['status']     = Match3RunNode::STATUS_PLAYING;
            $row['play_token'] = $token;
        }

        return $row;
    }

    /* ======================================================================
     *  内部：数据加载
     * ====================================================================== */

    /**
     * @return array<string, mixed>
     * @throws Match3NodeException
     */
    protected function loadRun(int $userId, string $runId): array
    {
        $run = Match3Run::where('run_id', $runId)->find();

        if (!$run) {
            throw new Match3NodeException('肉鸽局不存在: ' . $runId, Match3NodeException::CODE_RUN_NOT_FOUND);
        }
        if ((int) $run->user_id !== $userId) {
            throw new Match3NodeException('无权访问该局', Match3NodeException::CODE_RUN_NOT_FOUND);
        }
        if ((int) $run->status !== Match3Run::STATUS_PLAYING) {
            throw new Match3NodeException('本局已结束', Match3NodeException::CODE_RUN_FINISHED);
        }

        $arr           = $run->toArray();
        $arr['relics'] = is_array($arr['relics'] ?? null) ? $arr['relics'] : [];
        $arr['map_data'] = is_array($arr['map_data'] ?? null) ? $arr['map_data'] : [];

        return $arr;
    }

    /**
     * @return array<string, mixed>
     * @throws Match3NodeException
     */
    protected function loadBaseLevel(string $stageCode): array
    {
        $row = Match3LevelDef::where('code', $stageCode)->find();
        if (!$row) {
            throw new Match3NodeException('关卡模板不存在: ' . $stageCode, Match3NodeException::CODE_LEVEL_NOT_FOUND);
        }

        return $row->toArray();
    }

    /**
     * 已通关的节点 ID 列表
     *
     * @return array<int,string>
     */
    protected function clearedNodeIds(string $runId): array
    {
        return Match3RunNode::where('run_id', $runId)
            ->where('status', Match3RunNode::STATUS_PASSED)
            ->column('node_id');
    }

    protected function findNodeRow(string $runId, string $nodeId): ?array
    {
        $row = Match3RunNode::where('run_id', $runId)->where('node_id', $nodeId)->find();

        return $row ? $row->toArray() : null;
    }

    /**
     * 存盘并返回 playToken
     *
     * @param array<string,mixed> $finalSchema
     * @param array<int,array<string,mixed>> $relicChoices
     */
    protected function saveNode(
        string $runId,
        string $nodeId,
        string $nodeType,
        array $finalSchema,
        float $D,
        array $relicChoices = []
    ): string {
        $token = 'pt_' . bin2hex(random_bytes(8));

        Match3RunNode::create([
            'run_id'        => $runId,
            'node_id'       => $nodeId,
            'node_type'     => $nodeType,
            'final_schema'  => $finalSchema,
            'schema_fp'     => StableStringify::fingerprint($finalSchema),
            'difficulty'    => $D,
            'play_token'    => $token,
            'status'        => Match3RunNode::STATUS_PLAYING,
            'relic_choices' => $relicChoices,
            'create_time'    => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    protected function updateRunPosition(string $runId, int $layer, int $nodeIndex): void
    {
        Match3Run::where('run_id', $runId)->update([
            'layer'      => $layer,
            'node_index' => $nodeIndex,
        ]);
    }

    /**
     * @param array<string,mixed> $nodeRow
     * @param array<string,mixed> $run
     * @param array<string,mixed> $quality
     * @param array<int,string>   $nextNodes
     */
    protected function buildResponse(
        array $nodeRow,
        array $run,
        string $playToken = '',
        array $quality = [],
        ?array $nextNodes = null
    ): array {
        $schema = $nodeRow['final_schema'] ?? null;
        $schema = is_array($schema) && $schema !== [] ? $schema : null;

        return [
            'playToken'         => $playToken ?: (string) ($nodeRow['play_token'] ?? ''),
            'nodeId'            => (string) ($nodeRow['node_id'] ?? ''),
            'nodeType'          => (string) ($nodeRow['node_type'] ?? ''),
            'D'                 => (float) ($nodeRow['difficulty'] ?? 1.0),
            'lives'             => (int) $run['lives'],
            'coins'             => (int) $run['coins'],
            'stage'             => $schema,
            'appliedRelics'     => array_column($run['relics'], 'id'),
            'schemaFingerprint' => (string) ($nodeRow['schema_fp'] ?? ''),
            'relicChoices'      => is_array($nodeRow['relic_choices'] ?? null) ? $nodeRow['relic_choices'] : [],
            'autoCleared'       => false,
            'nextNodes'         => $nextNodes ?? Match3MapGenerator::nextNodeIds($run['map_data'], (string) ($nodeRow['node_id'] ?? '')),
            'quality'           => $quality,
        ];
    }
}
