<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3GroupBattle;
use plugin\match3game\model\Match3GroupBattleMember;
use plugin\match3game\service\social\GroupBattleService;
use plugin\match3game\service\social\SocialRules;
use wise\support\context\ContextualLogger;

/**
 * 群擂台管理（Phase 5 · 05 §A7.3，P5-B12）
 *
 * 【合规提醒】本页展示的 `group_id` 是**加密群标识**（openGId）。
 * 数据库里不存在明文群名/群号 —— 这是硬约束（05 §A8.1 第 8 条），
 * 页面也不提供"填写群名"的入口。
 *
 * 本页负责：周期列表 / 群排名 / Top5 明细 / 手动重算排名（等价 `php think match3:group-rank`）。
 */
class GroupBattle extends Base
{
    public function index()
    {
        return $this->assign([
            'periodId' => SocialRules::periodId(),
            'rules'    => [
                'topN'       => SocialRules::GROUP_TOP_N,
                'dailyPlays' => SocialRules::GROUP_DAILY_PLAYS,
                'stagePool'  => SocialRules::GROUP_STAGE_POOL,
            ],
        ])->fetch();
    }

    public function getList()
    {
        $page     = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 20);
        $periodId = (string) $this->request->param('period_id', '');
        $groupId  = (string) $this->request->param('group_id', '');

        $query = Match3GroupBattle::where('1=1');
        if ($periodId !== '') {
            $query->where('period_id', $periodId);
        }
        if ($groupId !== '') {
            $query->whereLike('group_id', "%{$groupId}%");
        }

        $list = $query->order('period_id', 'desc')->order('rank', 'asc')->paginate([
            'list_rows' => $pageSize, 'page' => $page,
        ]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row   = $item->toArray();
            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    /** 所有周期（下拉用） */
    public function periods()
    {
        $periods = Match3GroupBattle::distinct(true)->order('period_id', 'desc')->column('period_id');

        return $this->success('', ['periods' => array_values($periods)]);
    }

    /** 群的成员明细（Top5 明细 + 次数） */
    public function members()
    {
        $groupId  = (string) $this->request->param('group_id', '');
        $periodId = (string) $this->request->param('period_id', '');

        if ($groupId === '' || $periodId === '') {
            return $this->error('缺少 group_id 或 period_id');
        }

        $members = Match3GroupBattleMember::where('group_id', $groupId)
            ->where('period_id', $periodId)
            ->order('score', 'desc')
            ->limit(100)
            ->select()
            ->toArray();

        $scores = array_map(static fn ($m) => (int) $m['score'], $members);

        return $this->success('', [
            'members'    => $members,
            'topN'       => SocialRules::GROUP_TOP_N,
            'totalScore' => GroupBattleService::topK($scores, SocialRules::GROUP_TOP_N),
        ]);
    }

    /**
     * 重算本期排名（等价 `php think match3:group-rank`）
     */
    public function recalc()
    {
        $periodId = (string) $this->request->param('period_id', '');
        if ($periodId === '') {
            $periodId = SocialRules::periodId();
        }

        $service = new GroupBattleService();

        try {
            $groups = Match3GroupBattle::where('period_id', $periodId)->column('group_id');
            foreach ($groups as $groupId) {
                $service->recalcGroupScore((string) $groupId, $periodId);
            }
            $count = $service->recalcRanks($periodId);
        } catch (\Throwable $e) {
            ContextualLogger::error('[match3] GroupBattle recalc error: ' . $e->getMessage());

            return $this->error('重算失败: ' . $e->getMessage());
        }

        return $this->success('重算完成', ['periodId' => $periodId, 'groupCount' => $count]);
    }
}
