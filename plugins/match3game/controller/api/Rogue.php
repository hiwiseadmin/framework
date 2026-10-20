<?php

declare(strict_types=1);

namespace plugin\match3game\controller\api;

use plugin\match3game\model\Match3Relic;
use plugin\match3game\model\Match3Run;
use plugin\match3game\model\Match3RunNode;
use plugin\match3game\service\level\GoalJudge;
use plugin\match3game\service\level\Match3FillVersion;
use plugin\match3game\service\level\ReplayEngineFactory;
use plugin\match3game\service\level\SubmissionValidator;
use plugin\match3game\service\rogue\DailyService;
use plugin\match3game\service\rogue\EventService;
use plugin\match3game\service\rogue\Match3NodeException;
use plugin\match3game\service\rogue\Match3RunService;
use plugin\match3game\service\rogue\ShopService;
use plugin\match3game\service\analytics\Match3Funnel;
use wise\support\context\ContextualLogger;

/**
 * 肉鸽玩法 API（05 §B4）
 *
 * 路由由 `app/api/route/api.php` 的 catch-all 提供：
 *   /api/match3game/rogue/{action}      （不需要 route/api.php，见 07 文档 R7）
 *
 * | action  | 方法 | 说明 |
 * |---------|------|------|
 * | start   | POST | 开新局 |
 * | current | POST | 断点续玩（返回当前局 + 可进入节点） |
 * | enter   | POST | 进节点 → 下发最终 schema + playToken + 遗物三选一 |
 * | result  | POST | 提交节点结果（三层校验 → 通关判定 → 结算） |
 * | fail    | POST | 玩家主动失败（步数用尽，非作弊） |
 * | pick    | POST | 选择遗物（三选一） |
 * | reroll  | POST | 重选遗物 |
 * | abandon | POST | 放弃本局 |
 * | meta    | POST | 局外进度 |
 * | unlock  | POST | 灵石解锁 |
 * | relics  | POST | 遗物图鉴（含个人收集进度） |
 *
 * Phase 4 新增：
 * | daily   | POST | 当日挑战信息（全服一致） |
 * | shop    | POST | 进入商店 → 库存 |
 * | buy     | POST | 商店购买 |
 * | leave   | POST | 离开商店（节点通过） |
 * | rest    | POST | 休息节点：不传 choice 只看选项，传了则结算 |
 * | event   | POST | 进入事件节点 → 事件内容 |
 * | choose  | POST | 选择事件选项 |
 */
class Rogue extends Base
{
    /** 提交体 ops 条数硬上限 */
    protected const MAX_OPS = 500;

    protected Match3RunService $runs;

    protected ShopService $shop;

    protected EventService $events;

    protected DailyService $daily;

    protected SubmissionValidator $validator;

    protected function initialize(): void
    {
        parent::initialize();

        $config       = config('match3game.match3', []);
        $config       = is_array($config) ? $config : [];
        $rogueCfg     = (array) ($config['rogue'] ?? []);
        $rogueCfg['generation'] = (array) ($config['generation'] ?? []);

        $this->runs = new Match3RunService(null, $rogueCfg);

        $this->shop   = new ShopService($this->runs);
        $this->events = new EventService($this->runs);
        $this->daily  = new DailyService();

        $this->validator = new SubmissionValidator(
            ReplayEngineFactory::make($config['replay_engine'] ?? null)
        );
    }

    /* ---------------- 局 ---------------- */

    /** 开新局（mode=daily 时开始当日挑战） */
    public function start()
    {
        $startOption = (string) $this->payload('start_option', 'default');
        $mode        = (string) $this->payload('mode', Match3Run::MODE_NORMAL);
        $startRelics = (array) $this->payload('start_relics', []);

        try {
            $run = $mode === Match3Run::MODE_DAILY
                ? $this->runs->startDaily($this->userId())
                : $this->runs->startRun($this->userId(), $startOption, null, $startRelics);

            $meta = $this->runs->getMeta($this->userId());
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        Match3Funnel::emit(Match3Funnel::RUN_START, $this->userId(), [
            'runId'       => (string) $run['run_id'],
            'mode'        => (string) ($run['mode'] ?? Match3Run::MODE_NORMAL),
            'startOption' => $startOption,
            'mapSeed'     => (int) ($run['map_seed'] ?? 0),
            'levelSeed'   => (int) ($run['level_seed'] ?? 0),
            'startRelics' => array_column((array) ($run['relics'] ?? []), 'id'),
        ]);

        return $this->success('ok', [
            'run'             => $this->publicRun($run),
            'startNodes'      => \plugin\match3game\service\rogue\Match3MapGenerator::startNodeIds($run['map_data']),
            'startRelicSlots' => $this->runs->startRelicSlots($meta),
        ]);
    }

    /** 当日挑战信息（05 §B7.5） */
    public function daily()
    {
        try {
            return $this->success('ok', $this->daily->info($this->userId()));
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }
    }

    /**
     * 断点续玩
     *
     * ⚠️ 这里（以及 `meta`/`daily`）原本**没有**捕获 `Match3NodeException`：
     * 肉鸽的业务异常（41001~41008）会一路冒到 `ApiDispatch`，被兜成
     * `10501 服务器内部错误` —— 客户端只看到"服务器内部错误"，服务端也没日志（2026-09-27 排查卡住的根因）。
     */
    public function current()
    {
        try {
            $run = $this->runs->getCurrentRun($this->userId());
            if ($run === null) {
                return $this->success('ok', null);
            }

            $available = $this->runs->availableNodes($this->userId(), (string) $run['run_id']);
            $meta      = $this->runs->getMeta($this->userId());

            return $this->success('ok', [
                'run'             => $this->publicRun($run),
                'available'       => $available['available'],
                'cleared'         => $available['cleared'],
                'current'         => $available['current'],
                'startRelicSlots' => $this->runs->startRelicSlots($meta),
            ]);
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }
    }

    /** 放弃本局 */
    public function abandon()
    {
        $runId = (string) $this->payload('run_id', '');
        $run   = $this->runs->getCurrentRun($this->userId());

        try {
            $this->runs->abandonRun($this->userId(), $runId);
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        // 退出点分布：玩家在第几层、第几个节点弃坑（关卡难度调优的关键输入）
        Match3Funnel::emit(Match3Funnel::RUN_ABANDON, $this->userId(), [
            'runId'      => $runId,
            'layer'      => $run ? (int) $run['layer'] : 0,
            'nodeIndex'  => $run ? (int) $run['node_index'] : 0,
            'score'      => $run ? (int) $run['score'] : 0,
            'lives'      => $run ? (int) $run['lives'] : 0,
            'relicCount' => $run ? count((array) ($run['relics'] ?? [])) : 0,
        ]);

        return $this->success('ok');
    }

    /* ---------------- 节点 ---------------- */

    /** 进入节点 */
    public function enter()
    {
        $runId  = (string) $this->payload('run_id', '');
        $nodeId = (string) $this->payload('node_id', '');

        try {
            $data = $this->runs->enterNode($this->userId(), $runId, $nodeId);
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        Match3Funnel::emit(Match3Funnel::NODE_ENTER, $this->userId(), [
            'runId'    => $runId,
            'nodeId'   => $nodeId,
            'nodeType' => (string) ($data['nodeType'] ?? ''),
            'D'        => (float) ($data['D'] ?? 0),
            'moves'    => (int) ($data['stage']['rules']['moves'] ?? 0),
        ]);

        // 算法版本握手（strict 的保险丝）：肉鸽节点同样走 L3 重放，
        // 客户端 mismatch 时应提示强更、拒绝进节点
        $data['fill_version'] = Match3FillVersion::CURRENT;

        return $this->success('ok', $data);
    }

    /**
     * 提交节点结果（三层校验 → 通关判定 → 结算）
     *
     * body: { run_id, node_id, submission:{ play_token, seed, ops, checkpoints, claim } }
     */
    public function result()
    {
        $userId = $this->userId();
        $runId  = (string) $this->payload('run_id', '');
        $nodeId = (string) $this->payload('node_id', '');
        $sub    = $this->payload('submission', null);

        if (!is_array($sub)) {
            return $this->error('submission 不能为空');
        }

        // ① 取回该节点的最终 schema（重放初始条件）+ 校验 token
        $node = $this->runs->getNodeService()->getByPlayToken((string) ($sub['play_token'] ?? ''));
        if (!$node) {
            return $this->error('play_token 无效或已使用', [], Match3NodeException::CODE_NODE_NOT_FOUND);
        }
        if ((string) $node['run_id'] !== $runId || (string) $node['node_id'] !== $nodeId) {
            return $this->error('play_token 与节点不匹配', [], Match3NodeException::CODE_NODE_NOT_FOUND);
        }
        if ((int) $node['status'] !== Match3RunNode::STATUS_PLAYING) {
            return $this->error('该节点已结算', [], Match3NodeException::CODE_NODE_SETTLED);
        }

        $schema = is_array($node['final_schema'] ?? null) ? $node['final_schema'] : [];
        if ($schema === []) {
            return $this->error('节点关卡数据缺失', [], Match3NodeException::CODE_LEVEL_INVALID);
        }

        // ② seed 必须与存档一致（防用别的 seed 重放）
        $storedSeed = (int) ($schema['random']['seed'] ?? 0);
        if (isset($sub['seed']) && (int) $sub['seed'] !== $storedSeed) {
            return $this->error('seed 与服务端不一致', [], 11004);
        }

        $ops = is_array($sub['ops'] ?? null) ? $sub['ops'] : [];
        if (count($ops) > self::MAX_OPS) {
            return $this->error('ops 条数超限', [], 11001);
        }

        // ③ 三层校验
        $res      = $this->validator->validateSubmission($schema, $sub);
        $replayed = $res->replayed;
        $claim    = (array) ($sub['claim'] ?? []);
        $goals    = (array) ($claim['goals'] ?? []);

        if (!$res->passed) {
            ContextualLogger::warning('[Match3] 肉鸽节点校验失败', [
                'user' => $userId, 'run' => $runId, 'node' => $nodeId,
                'code' => $res->errorCode, 'errors' => $res->errors,
            ]);

            return $this->error(implode('; ', $res->errors), [], $this->errNo($res->errorCode));
        }

        $score     = (int) ($res->meta['score'] ?? 0);
        $movesUsed = (int) ($res->meta['moves'] ?? 0);
        $progress  = (array) ($res->meta['goals'] ?? $goals);

        // ④ 通关判定：L3 用重放 goals；未启用退化 claim.passed（弱校验期）
        $rules  = (array) ($schema['rules'] ?? []);
        $goalsConf = (array) ($rules['goals'] ?? []);
        $passed = $replayed
            ? GoalJudge::met($goalsConf, $progress, $score)
            : (bool) ($claim['passed'] ?? false);

        // ⑤ 结算
        try {
            $out = $passed
                ? $this->runs->completeNode($userId, $runId, $nodeId, $score, $movesUsed, $progress)
                : $this->runs->failNode($userId, $runId, $nodeId);
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        // 埋点：节点通过/失败
        Match3Funnel::emit($passed ? Match3Funnel::NODE_PASS : Match3Funnel::NODE_FAIL, $userId, [
            'runId'      => $runId,
            'nodeId'     => $nodeId,
            'nodeType'   => (string) ($node['node_type'] ?? ''),
            'score'      => $score,
            'movesUsed'  => $movesUsed,
            'replayed'   => $replayed ? 1 : 0,
            'cleared'    => !empty($out['cleared']) ? 1 : 0,
            'lives'      => $out['lives'] ?? null,
            'coins'      => $out['coins'] ?? null,
            'totalScore' => $out['totalScore'] ?? null,
        ]);

        // 埋点：L3 重放可观测性（命中率 / 降级率 / 偏差分布）—— upper_bound 升级决策的数据来源
        Match3Funnel::replay(
            $userId,
            (string) ($res->meta['replayMode'] ?? 'off'),
            $replayed,
            (bool) ($res->meta['replaySuspicious'] ?? false),
            $res->meta['replayScore'] ?? null,
            $res->meta['claimScore'] ?? null,
            // S5-4：与固定关卡同一口径（分桶维度 + checkpoint 分歧条数）
            [
                'runId'              => $runId,
                'nodeId'             => $nodeId,
                'source'             => 'rogue',
                'clientVersion'      => (string) ($sub['client_version'] ?? ''),
                'fillVersion'        => (string) ($sub['fill_version'] ?? ''),
                'checkpointMismatch' => (int) ($res->meta['checkpointMismatch'] ?? 0),
            ]
        );

        return $this->success('ok', [
            'passed'   => $passed,
            'replayed' => $replayed,
            'unmet'    => GoalJudge::unmetReason($goalsConf, $progress, $score),
            'reward'   => $out['reward'] ?? null,
            'run'      => [
                'lives'        => $out['lives'] ?? null,
                'coins'        => $out['coins'] ?? null,
                'totalScore'   => $out['totalScore'] ?? null,
                'cleared'      => $out['cleared'] ?? false,
                'nextNodes'    => $out['nextNodes'] ?? [],
                'relicChoices' => $out['relicChoices'] ?? [],
            ],
        ]);
    }

    /** 玩家主动失败（步数用尽，非作弊） */
    public function fail()
    {
        $runId  = (string) $this->payload('run_id', '');
        $nodeId = (string) $this->payload('node_id', '');

        try {
            $out = $this->runs->failNode($this->userId(), $runId, $nodeId);
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        Match3Funnel::emit(Match3Funnel::NODE_FAIL, $this->userId(), [
            'runId'    => $runId,
            'nodeId'   => $nodeId,
            'lives'    => $out['lives'] ?? null,
            'runEnded' => !empty($out['runEnded']) ? 1 : 0,
            'reason'   => 'moves_exhausted',
        ]);

        return $this->success('ok', $out);
    }

    /* ---------------- 遗物 ---------------- */

    /** 选择遗物 */
    public function pick()
    {
        $runId    = (string) $this->payload('run_id', '');
        $nodeId   = (string) $this->payload('node_id', '');
        $relicId  = (string) $this->payload('relic_id', '');

        try {
            $out = $this->runs->pickRelic($this->userId(), $runId, $nodeId, $relicId);
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        // 遗物选择分布（哪个遗物被拿、在第几层拿）
        Match3Funnel::emit(Match3Funnel::RELIC_PICK, $this->userId(), [
            'runId'   => $runId,
            'nodeId'  => $nodeId,
            'relicId' => $relicId,
            'reroll'  => 0,
        ]);

        return $this->success('ok', $out);
    }

    /** 重选遗物（付费/看广告后带 paid=true 再调一次） */
    public function reroll()
    {
        $runId  = (string) $this->payload('run_id', '');
        $nodeId = (string) $this->payload('node_id', '');
        $paid   = (bool) $this->payload('paid', false);

        try {
            $out = $this->runs->rerollRelics($this->userId(), $runId, $nodeId, $paid);
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        Match3Funnel::emit(Match3Funnel::RELIC_PICK, $this->userId(), [
            'runId'    => $runId,
            'nodeId'   => $nodeId,
            'reroll'   => 1,
            'paid'     => $paid ? 1 : 0,
            'choices'  => array_column((array) ($out['relicChoices'] ?? []), 'id'),
        ]);

        return $this->success('ok', $out);
    }

    /** 遗物图鉴（含个人收集进度，05 §B5 / P4-B07） */
    public function relics()
    {
        $rows = Match3Relic::where('enabled', 1)
            ->field('relic_id, name, rarity, category, desc, icon, unlock_cost')
            ->order('rarity', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $meta    = $this->runs->getMeta($this->userId());
        $owned   = (array) ($meta['unlocked_relics'] ?? []);
        $current = $this->runs->getCurrentRun($this->userId(), null);
        $inRun   = $current ? array_column((array) $current['relics'], 'id') : [];

        $total = 0;
        $list  = [];
        foreach ($rows as $r) {
            $id          = (string) $r['relic_id'];
            $unlocked    = (int) ($r['unlock_cost'] ?? 0) === 0 || in_array($id, $owned, true);
            $total      += $unlocked ? 1 : 0;

            $r['unlocked'] = $unlocked;
            $r['inRun']    = in_array($id, $inRun, true);
            $list[]        = $r;
        }

        return $this->success('ok', [
            'list'     => $list,
            'progress' => [
                'unlocked' => $total,
                'total'    => count($rows),
                'percent'  => count($rows) > 0 ? round($total / count($rows) * 100, 1) : 0,
            ],
        ]);
    }

    /* ---------------- 局内节点：商店 / 休息 / 事件（Phase 4） ---------------- */

    /** 进入商店 → 库存 */
    public function shop()
    {
        try {
            $out = $this->shop->enter($this->userId(), (string) $this->payload('run_id', ''), (string) $this->payload('node_id', ''));
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        return $this->success('ok', $out);
    }

    /** 商店购买 */
    public function buy()
    {
        try {
            $out = $this->shop->buy(
                $this->userId(),
                (string) $this->payload('run_id', ''),
                (string) $this->payload('node_id', ''),
                (string) $this->payload('item_id', '')
            );
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        return $this->success('ok', $out);
    }

    /** 离开商店 */
    public function leave()
    {
        try {
            $out = $this->shop->leave(
                $this->userId(),
                (string) $this->payload('run_id', ''),
                (string) $this->payload('node_id', '')
            );
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        return $this->success('ok', $out);
    }

    /** 休息节点：不传 choice 只返回选项；传了则结算 */
    public function rest()
    {
        $choice = $this->payload('choice', null);
        $relic  = $this->payload('relic_id', null);

        try {
            $out = $this->shop->rest(
                $this->userId(),
                (string) $this->payload('run_id', ''),
                (string) $this->payload('node_id', ''),
                is_string($choice) && $choice !== '' ? $choice : null,
                is_string($relic) && $relic !== '' ? $relic : null
            );
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        return $this->success('ok', $out);
    }

    /** 进入事件节点 → 事件内容 */
    public function event()
    {
        try {
            $out = $this->events->enter(
                $this->userId(),
                (string) $this->payload('run_id', ''),
                (string) $this->payload('node_id', '')
            );
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        return $this->success('ok', $out);
    }

    /** 选择事件选项 */
    public function choose()
    {
        try {
            $out = $this->events->choose(
                $this->userId(),
                (string) $this->payload('run_id', ''),
                (string) $this->payload('node_id', ''),
                (string) $this->payload('option_id', '')
            );
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }

        return $this->success('ok', $out);
    }

    /* ---------------- 局外进度 ---------------- */

    /** 局外进度 */
    public function meta()
    {
        try {
            return $this->success('ok', $this->runs->getMeta($this->userId()));
        } catch (Match3NodeException $e) {
            return $this->error($e->getMessage(), [], $e->getCode());
        }
    }

    /** 灵石解锁（key: start_move_1 / character / relic_slot / relic:r_xxx） */
    public function unlock()
    {
        $key = (string) $this->payload('unlock_key', '');

        $out = $this->runs->unlockMeta($this->userId(), $key);

        return $out['ok']
            ? $this->success('ok', ['stone' => $out['stone'], 'bonus' => $out['bonus'] ?? 0])
            : $this->error((string) ($out['reason'] ?? 'unlock_failed'), [], Match3NodeException::CODE_STONE_NOT_ENOUGH);
    }

    /* ---------------- 内部 ---------------- */

    /**
     * 对外安全的 run 结构
     */
    protected function publicRun(array $run): array
    {
        return [
            'runId'     => (string) $run['run_id'],
            'mode'      => (string) ($run['mode'] ?? Match3Run::MODE_NORMAL),
            'dailyDay'  => $run['daily_day'] ?? null,
            'status'    => (int) $run['status'],
            'layer'     => (int) $run['layer'],
            'nodeIndex' => (int) $run['node_index'],
            'lives'     => (int) $run['lives'],
            'coins'     => (int) $run['coins'],
            'score'     => (int) $run['score'],
            'buffs'     => array_merge(Match3Run::defaultBuffs(), (array) ($run['buffs'] ?? [])),
            'relics'    => array_map(static fn ($r) => [
                'id' => (string) ($r['id'] ?? ''),
                'lv' => (int) ($r['lv'] ?? 1),
            ], (array) $run['relics']),
            'map'       => $run['map_data'],
            'createdAt' => $run['create_time'] ?? null,
        ];
    }

    /**
     * 校验错误码 → 业务码（06 §十）
     */
    protected function errNo(?string $code): int
    {
        return match ($code) {
            SubmissionValidator::ERR_INVALID_OP      => 11001,
            SubmissionValidator::ERR_INVALID_CLAIM   => 11002,
            SubmissionValidator::ERR_RESULT_MISMATCH => 11003,
            SubmissionValidator::ERR_BOARD_MISMATCH  => 11004,
            SubmissionValidator::ERR_CHECKPOINT      => 11005,
            default                                  => 11009,
        };
    }
}
