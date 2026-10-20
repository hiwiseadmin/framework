<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3PvpBattle;
use plugin\match3game\model\Match3PvpPlayer;
use wise\support\context\ContextualLogger;

/**
 * 作弊复核（Phase 5 · 05 §A8.1 第 5 条，P5-B12）
 *
 * 数据来源：`match3_pvp_player.is_cheat`
 *   0 正常 / 1 可疑（重放比对失败：board_mismatch / result_mismatch）/ 2 确认作弊
 *
 * 复核动作：
 *   · 放行（is_cheat=0，误报）
 *   · 确认（is_cheat=2，运营据此做封号/清榜，本页只负责标记）
 *
 * ⚠️ 本期（L1/L2 校验）只有"结构 + 分数上界"能自动判定，
 *    真正的篡改检出依赖 Phase 6 把 `replay_engine.mode` 切到 http（L3 重放）。
 */
class Cheat extends Base
{
    public function index()
    {
        return $this->assign([
            'flags' => [
                Match3PvpPlayer::CHEAT_NONE      => '正常',
                Match3PvpPlayer::CHEAT_SUSPECT   => '可疑',
                Match3PvpPlayer::CHEAT_CONFIRMED => '确认作弊',
            ],
            'reasons' => [
                'score_over_replay'  => '分数超重放上界（PHP 重放引擎，观察期只标记）',
                'board_mismatch'     => '棋盘不一致（重放对不上，需 strict / http）',
                'result_mismatch'    => '结果不一致（分数/步数/目标被篡改）',
                'invalid_op'         => '非法操作序列',
                'invalid_checkpoint' => 'checkpoint 结构非法',
                'manual_review'      => '人工复核确认',
            ],
        ])->fetch();
    }

    public function getList()
    {
        $page     = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 20);
        $isCheat  = $this->request->param('is_cheat', '');
        $reason   = $this->request->param('reason', '');
        $userId   = (int) $this->request->param('user_id/d', 0);
        $battleId = (string) $this->request->param('battle_id', '');

        $query = Match3PvpPlayer::where('1=1');
        if ($isCheat === '') {
            // 默认只看可疑/确认（正常数据量太大，没有复核价值）
            $query->where('is_cheat', '>', 0);
        } else {
            $query->where('is_cheat', (int) $isCheat);
        }
        if ($reason !== '') {
            $query->where('cheat_reason', $reason);
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($battleId !== '') {
            $query->where('battle_id', $battleId);
        }

        $list = $query->order('id', 'desc')->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();

            $battle = Match3PvpBattle::where('battle_id', $row['battle_id'])->find();
            $row['mode']        = $battle ? (string) $battle->mode : '';
            $row['stage_code']  = $battle ? (string) $battle->stage_code : '';
            $row['battle_seed'] = $battle ? (int) $battle->seed : 0;
            $row['cheat_text']  = Match3PvpPlayer::cheatText((int) $row['is_cheat']);
            $row['status_text'] = Match3PvpPlayer::statusText((int) $row['status']);
            $row['replayed']    = (int) $row['replayed'] === 1 ? '是' : '否（弱校验）';

            // 对手信息（便于人工比对）
            $opp = Match3PvpPlayer::where('battle_id', $row['battle_id'])
                ->where('user_id', '<>', $row['user_id'])
                ->find();
            $row['opponent_id']    = $opp ? (int) $opp->user_id : 0;
            $row['opponent_score'] = $opp ? (int) $opp->score : 0;
            $row['score_gap']      = $opp ? ((int) $row['score'] - (int) $opp->score) : (int) $row['score'];

            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    /**
     * 复核：0=放行（误报） / 2=确认作弊
     */
    public function review()
    {
        $ids  = $this->request->post('ids');
        $flag = (int) $this->request->post('is_cheat', 0);

        if (empty($ids)) {
            return $this->error('请选择要复核的记录');
        }
        if (!in_array($flag, [Match3PvpPlayer::CHEAT_NONE, Match3PvpPlayer::CHEAT_CONFIRMED], true)) {
            return $this->error('复核结果只能是 0（放行）或 2（确认作弊）');
        }
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        try {
            Match3PvpPlayer::whereIn('id', $ids)->update([
                'is_cheat'     => $flag,
                'cheat_reason' => $flag === Match3PvpPlayer::CHEAT_NONE ? null : 'manual_review',
            ]);

            ContextualLogger::info('[match3] 作弊复核', ['ids' => $ids, 'is_cheat' => $flag]);

            return $this->success('复核完成');
        } catch (\Throwable $e) {
            return $this->error('复核失败: ' . $e->getMessage());
        }
    }
}
