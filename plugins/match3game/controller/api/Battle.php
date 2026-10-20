<?php

declare(strict_types=1);

namespace plugin\match3game\controller\api;

use plugin\match3game\service\BattleService;
use plugin\match3game\service\analytics\Match3Funnel;

/**
 * 消消乐对局 API
 *
 * POST /api/match3game/battle/start    开始对局 → { play_token, seed, level(Level Schema v1), ... }
 * POST /api/match3game/battle/submit   提交结算（02 §8 协议）
 * GET  /api/match3game/battle/latest   断线恢复
 *
 * submit 请求体：
 * {
 *   "play_token": "开局下发的 token（= battle_token，一次性）",
 *   "seed": 1736438400,
 *   "ops":         [{"t":"swap","a":[1,1],"b":[2,1]}, {"t":"prop","p":"hammer","at":[4,6]}],
 *   "checkpoints": [{"t":0,"h":"a3f2c1"}, {"t":1,"h":"7b19de"}],
 *   "claim":       {"score":8640,"movesUsed":24,"passed":true,"goals":{"ice":30},"maxCombo":5},
 *   "items_used":  [{"item":"hammer","count":1}],
 *   "duration_ms": 42000
 * }
 *
 * 校验分三层：L1 ops 合法 → L2 分数粗筛 → L3 重放比对（replay_engine.mode=http 时启用）。
 * 响应含 replayed 字段：false 表示本局走的是弱校验（风控据此统计）。
 */
class Battle extends Base
{
    /**
     * 开始对局（§7.1）
     *
     * 带 `battle_id` 时进入对战模式：seed 由对战锁定，双方棋盘 100% 一致（05 §A8.1 第 1 条）
     */
    public function start()
    {
        $userId    = $this->userId();
        $levelCode = (string) $this->payload('level_code', '');
        $useProps  = (array) $this->payload('use_props', []);
        $battleId  = $this->payload('battle_id', null);

        $service = new BattleService();

        try {
            $result = $service->start(
                $userId,
                $levelCode,
                $useProps,
                is_string($battleId) && $battleId !== '' ? $battleId : null
            );
        } catch (\plugin\match3game\exception\Match3Exception $e) {
            return $this->error($e->getMessage(), [], (int) $e->getCode());
        }

        Match3Funnel::emit(Match3Funnel::LEVEL_START, $this->userId(), [
            'levelCode' => (string) ($result['level']['code'] ?? ''),
            'isBattle'  => !empty($result['is_battle']) ? 1 : 0,
            'battleId'  => $result['battle_id'] ?? null,
            'seed'      => (int) ($result['seed'] ?? 0),
        ]);

        return $this->success('ok', $result);
    }

    /**
     * 提交结算（§7.2）
     */
    public function submit()
    {
        $userId     = $this->userId();
        $submission = $this->payload();
        $levelCode  = (string) ($submission['level_code'] ?? '');

        $service = new BattleService();
        $result  = $service->submit($userId, $submission);

        // 埋点：固定关卡漏斗 + L3 重放可观测性
        Match3Funnel::emit(
            !empty($result['passed']) ? Match3Funnel::LEVEL_PASS : Match3Funnel::LEVEL_FAIL,
            $userId,
            [
                'levelCode'  => $levelCode,
                'score'      => (int) ($result['score'] ?? 0),
                'stars'      => (int) ($result['stars'] ?? 0),
                'replayed'   => !empty($result['replayed']) ? 1 : 0,
                'replayMode' => (string) ($result['replay_mode'] ?? 'off'),
            ]
        );

        Match3Funnel::replay(
            $userId,
            (string) ($result['replay_mode'] ?? 'off'),
            !empty($result['replayed']),
            !empty($result['replay_suspect']),
            $result['replay_score'] ?? null,
            $result['claim_score'] ?? null,
            // S5-4：分桶维度（客户端包版本 → 回落算法版本）+ 逐回合 checkpoint 分歧条数
            [
                'levelCode'          => $levelCode,
                'source'             => 'level',
                'clientVersion'      => (string) ($result['client_version'] ?? ''),
                'fillVersion'        => (string) ($result['fill_version'] ?? ''),
                'checkpointMismatch' => (int) ($result['checkpoint_mismatch'] ?? 0),
            ]
        );

        return $this->success('ok', $result);
    }

    /**
     * 断线恢复（§7.3）
     */
    public function latest()
    {
        $userId = $this->userId();

        $service = new BattleService();
        $result  = $service->latest($userId);

        return $this->success('ok', $result);
    }
}
